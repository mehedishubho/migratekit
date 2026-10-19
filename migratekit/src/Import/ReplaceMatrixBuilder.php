<?php
/**
 * Builds the old -> new replacement matrix used during an import.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Import;

use MigrateKit\Database\ReplaceEngine;
use MigrateKit\Site\SiteContext;

final class ReplaceMatrixBuilder {

	private function __construct() {
	}

	/**
	 * @param array       $package          package.json from the archive.
	 * @param SiteContext $site             Destination site.
	 * @param bool        $no_email_replace Whether to skip email-domain replacement.
	 * @return ReplaceEngine
	 */
	public static function build(
		array $package,
		SiteContext $site,
		bool $no_email_replace = false
	): ReplaceEngine {
		$pairs = array();

		$wordpress = isset($package['WordPress']) && is_array($package['WordPress'])
			? $package['WordPress']
			: array();

		self::add_pair($pairs, (string) ($package['SiteURL'] ?? ''), (string) $site->get('site_url', ''));
		self::add_pair($pairs, (string) ($package['HomeURL'] ?? ''), (string) $site->get('home_url', ''));
		self::add_pair($pairs, (string) ($package['UploadsURL'] ?? ''), (string) $site->get('uploads_url', ''));
		self::add_pair($pairs, (string) ($wordpress['UploadsURL'] ?? ''), (string) $site->get('uploads_url', ''));
		self::add_pair($pairs, (string) ($wordpress['Uploads'] ?? ''), (string) $site->get('uploads_path', ''));
		self::add_pair($pairs, (string) ($wordpress['Absolute'] ?? ''), (string) $site->get('absolute_path', ''));
		self::add_pair($pairs, (string) ($wordpress['Content'] ?? ''), (string) $site->get('content_path', ''));

		$replace     = isset($package['Replace']) && is_array($package['Replace']) ? $package['Replace'] : array();
		$old_values  = isset($replace['OldValues']) && is_array($replace['OldValues']) ? $replace['OldValues'] : array();
		$new_values  = isset($replace['NewValues']) && is_array($replace['NewValues']) ? $replace['NewValues'] : array();

		foreach ($old_values as $index => $old) {
			self::add_pair($pairs, (string) $old, (string) ( $new_values[ $index ] ?? '' ));
		}

		if (! $no_email_replace) {
			$old_host = self::host((string) ($package['SiteURL'] ?? ''));
			$new_host = self::host((string) $site->get('site_url', ''));

			if ($old_host !== '' && $new_host !== '' && $old_host !== $new_host) {
				$pairs[] = array( '@' . $old_host, '@' . $new_host );
				$pairs[] = array( '//' . $old_host, '//' . $new_host );
			}
		}

		$sites = isset($package['Sites']) && is_array($package['Sites']) ? $package['Sites'] : array();

		if ($sites !== array() && ! $no_email_replace) {
			// Multisite rows keep bare hostnames (wp_blogs.domain) and host
			// forms in network tables; the scheme and email pairs above only
			// cover decorated occurrences. The raw pair rewrites the host
			// wherever the stored main host appears on its own.
			$old_host = self::host((string) ($package['SiteURL'] ?? ''));
			$new_host = self::host((string) $site->get('site_url', ''));

			if ($old_host !== '' && $new_host !== '' && $old_host !== $new_host) {
				$pairs[] = array( $old_host, $new_host );
			}
		}

		foreach ($sites as $entry) {
			if (! is_array($entry) || ! empty($entry['IsMain'])) {
				continue;
			}

			$old_site    = trim((string) ( $entry['SiteURL'] ?? '' ));
			$derived_url = self::derived_site_url($entry, $site, $package);

			if ($old_site !== '' && $derived_url !== '') {
				self::add_pair($pairs, $old_site, $derived_url);
			}

			$old_uploads     = trim((string) ( $entry['UploadsURL'] ?? '' ));
			$derived_uploads = self::derived_uploads_url($entry, $site, $package, $derived_url);

			if ($old_uploads !== '' && $derived_uploads !== '') {
				self::add_pair($pairs, $old_uploads, $derived_uploads);
			}
		}

		$search  = array();
		$replacements = array();

		foreach ($pairs as $pair) {
			$old = $pair[0];
			$new = $pair[1];

			if ($old === '' || $old === $new) {
				continue;
			}

			foreach (self::variants($old, $new) as $needle => $substitute) {
				$search[]       = $needle;
				$replacements[] = $substitute;
			}
		}

		return new ReplaceEngine($search, $replacements);
	}

	/**
	 * @param array<int,array{0:string,1:string}> $pairs Collected pairs (by reference).
	 * @return void
	 */
	private static function add_pair(array &$pairs, string $old, string $new): void {
		$old = trim($old);
		$new = trim($new);

		if ($old === '' || $new === '') {
			return;
		}

		$pairs[] = array( $old, $new );
	}

	/**
	 * @return array<string,string>
	 */
	private static function variants(string $old, string $new): array {
		$variants = array( $old => $new );

		$encoded = urlencode($old);

		if ($encoded !== $old) {
			$variants[ $encoded ] = urlencode($new);
		}

		$raw = rawurlencode($old);

		if ($raw !== $old) {
			$variants[ $raw ] = rawurlencode($new);
		}

		$json = addcslashes($old, '/');

		if ($json !== $old) {
			$variants[ $json ] = addcslashes($new, '/');
		}

		if (preg_match('#^https?://#i', $old) === 1) {
			$other = preg_replace('#^https?://#i', self::is_secure($old) ? 'http://' : 'https://', $old);

			if (is_string($other) && $other !== $old) {
				$variants[ $other ] = $new;
			}
		}

		return $variants;
	}

	private static function is_secure(string $url): bool {
		return stripos($url, 'https://') === 0;
	}

	private static function host(string $url): string {
		$host = parse_url($url, PHP_URL_HOST);

		return is_string($host) ? $host : '';
	}

	/**
	 * Destination URL for an archive subsite.
	 *
	 * Subdirectory networks (or any custom-mapped domains) map the source
	 * path under the destination root. Subdomain networks move the source's
	 * subdomain labels onto the destination host; a source host that is not
	 * a real subdomain of the stored main host (domain mapping) has no
	 * derivable destination and earns no pair.
	 *
	 * @param array       $entry   Archive site block.
	 * @param SiteContext $site    Destination site.
	 * @param array       $package Full package manifest.
	 * @return string
	 */
	private static function derived_site_url(array $entry, SiteContext $site, array $package): string {
		$main_source = trim((string) ($package['SiteURL'] ?? ''));
		$target_main = trim((string) $site->get('site_url', ''));

		if ($main_source === '' || $target_main === '') {
			return '';
		}

		$path = self::sub_path((string) ( $entry['Path'] ?? '' ));
		$mode = (string) ( $package['Network']['Mode'] ?? 'subdirectory' );

		if ($mode !== 'subdomain') {
			// Empty path hosts are custom domain mappings; they stay unmapped.
			return $path === '' ? '' : $target_main . $path;
		}

		$host_part = self::host((string) ( $entry['SiteURL'] ?? '' ));
		$old_host  = self::host($main_source);
		$new_host  = self::host($target_main);
		$scheme    = self::is_secure($target_main) ? 'https://' : 'http://';

		if ($host_part === '' || $old_host === '' || $new_host === '') {
			return '';
		}

		if (strlen($host_part) <= strlen($old_host)) {
			return '';
		}

		if (substr((string) $host_part, -strlen((string) $old_host)) !== $old_host) {
			return '';
		}

		$labels = substr($host_part, 0, strlen($host_part) - strlen($old_host));

		return $scheme . $labels . $new_host . $path;
	}

	/**
	 * Destination uploads URL for an archive subsite.
	 *
	 * Subdirectory networks host subsite media under uploads/sites/{id} on
	 * the main site. Subdomain ones keep the target's uploads base path but
	 * substitute the derived host.
	 *
	 * @param array       $entry            Archive site block.
	 * @param SiteContext $site             Destination site.
	 * @param array       $package          Full package manifest.
	 * @param string      $derived_site_url Derived subsite URL.
	 * @return string
	 */
	private static function derived_uploads_url(array $entry, SiteContext $site, array $package, string $derived_site_url): string {
		$target_uploads = trim((string) $site->get('uploads_url', ''));

		if ($target_uploads === '') {
			return '';
		}

		$mode = (string) ( $package['Network']['Mode'] ?? 'subdirectory' );

		if ($mode !== 'subdomain' || $derived_site_url === '') {
			return $target_uploads . 'sites/' . (int) ( $entry['BlogId'] ?? 0 ) . '/';
		}

		$derived_host = self::host($derived_site_url);
		$base_host    = self::host($target_uploads);
		$base_path    = (string) parse_url($target_uploads, PHP_URL_PATH);
		$scheme       = self::is_secure($target_uploads) ? 'https://' : 'http://';

		if ($derived_host === '' || $base_host === '' || $derived_host === $base_host) {
			return $target_uploads . 'sites/' . (int) ( $entry['BlogId'] ?? 0 ) . '/';
		}

		return $scheme . $derived_host . $base_path;
	}

	/**
	 * Normalized network path for URL building ('' for the network root).
	 *
	 * @param string $path Raw path from the manifest.
	 * @return string
	 */
	private static function sub_path(string $path): string {
		$trimmed = trim(trim($path), '/');

		return $trimmed === '' ? '' : '/' . $trimmed;
	}
}
