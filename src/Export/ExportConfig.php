<?php
/**
 * Builds the package.json manifest stored as the first archive record.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export;

use MigrateKit\Archive\Encryptor;
use MigrateKit\Archive\Protocol;
use MigrateKit\Site\SiteContext;

final class ExportConfig {

	/**
	 * Option key => package.json flag for export exclusions.
	 *
	 * @var array<string,string>
	 */
	private const FLAGS = array(
		'no_spam_comments'    => 'NoSpamComments',
		'no_post_revisions'   => 'NoPostRevisions',
		'no_media'            => 'NoMedia',
		'no_themes'           => 'NoThemes',
		'no_inactive_themes'  => 'NoInactiveThemes',
		'no_muplugins'        => 'NoMustUsePlugins',
		'no_plugins'          => 'NoPlugins',
		'no_inactive_plugins' => 'NoInactivePlugins',
		'no_cache'            => 'NoCache',
		'no_database'         => 'NoDatabase',
		'no_email_replace'    => 'NoEmailReplace',
	);

	private function __construct() {
	}

	/**
	 * @param SiteContext $site    Site metadata.
	 * @param array       $options Export options.
	 * @return array<string,mixed>
	 */
	public static function build(SiteContext $site, array $options = array()): array {
		$config = array(
			'SiteURL'         => $site->get('site_url'),
			'HomeURL'         => $site->get('home_url'),
			'InternalSiteURL' => $site->get('internal_site_url'),
			'InternalHomeURL' => $site->get('internal_home_url'),
			'Replace'         => array(
				'OldValues' => array_values(isset($options['replace_old']) ? (array) $options['replace_old'] : array()),
				'NewValues' => array_values(isset($options['replace_new']) ? (array) $options['replace_new'] : array()),
			),
		);

		foreach (self::FLAGS as $option => $flag) {
			if (! empty($options[ $option ])) {
				$config[ $flag ] = true;
			}
		}

		$config['Plugin'] = array(
			'Version' => defined('MIGRATEKIT_VERSION') ? MIGRATEKIT_VERSION : '0.0.3',
		);

		$config['WordPress'] = array(
			'Version'    => $site->get('wp_version'),
			'Absolute'   => $site->get('absolute_path'),
			'Content'    => $site->get('content_path'),
			'Plugins'    => $site->get('plugins_path'),
			'Themes'     => array_values((array) $site->get('themes', array())),
			'Uploads'    => $site->get('uploads_path'),
			'UploadsURL' => $site->get('uploads_url'),
		);

		$config['Database'] = array(
			'Version'        => $site->get('db_version'),
			'Charset'        => $site->get('db_charset'),
			'Collate'        => $site->get('db_collate'),
			'Prefix'         => $site->get('table_prefix'),
			'ExcludedTables' => array_values(isset($options['excluded_tables']) ? (array) $options['excluded_tables'] : array()),
			'IncludedTables' => array_values(isset($options['included_tables']) ? (array) $options['included_tables'] : array()),
		);

		$config['PHP'] = array(
			'Version' => PHP_VERSION,
			'System'  => PHP_OS,
			'Integer' => PHP_INT_SIZE,
		);

		$config['Plugins']    = array_values((array) $site->get('plugins', array()));
		$config['Template']   = $site->get('template');
		$config['Stylesheet'] = $site->get('stylesheet');
		$config['Uploads']    = $site->get('uploads_path');
		$config['UploadsURL'] = $site->get('uploads_url');

		$server = (array) $site->get('server', array());

		if ($server !== array()) {
			$config['Server'] = array();

			foreach ($server as $name => $contents) {
				$config['Server'][ $name ] = base64_encode((string) $contents);
			}
		}

		$compression = isset($options['compression']) ? (string) $options['compression'] : '';

		if ($compression !== '') {
			$config['Compression'] = array(
				'Enabled' => true,
				'Type'    => $compression,
			);
		}

		$password = isset($options['password']) ? (string) $options['password'] : '';

		if ($password !== '') {
			$encryptor = new Encryptor($password);

			$config['Encrypted']          = true;
			$config['EncryptedSignature'] = $encryptor->sign(Protocol::SIGN_TEXT);
		}

		$network = self::network_block($site, $options);

		if ($network !== array()) {
			$config['Network'] = $network['mode'];
			$config['Sites']   = $network['sites'];
		}

		return $config;
	}

	/**
	 * Network manifest block for multisite exports.
	 *
	 * A whole-network export records every site with its table prefix and
	 * upload paths; a subsite export reorients the manifest to that one site,
	 * which lets it restore into a single site through the standard path.
	 *
	 * @param SiteContext $site    Site metadata.
	 * @param array       $options Export options.
	 * @return array{mode:array<string,mixed>,sites:array<int,array<string,mixed>>}|array<empty,empty>
	 */
	private static function network_block(SiteContext $site, array $options): array {
		$mode = isset($options['network']) ? (string) $options['network'] : '';
		$sites = (array) $site->get('network_sites', array());

		if ($sites === array() || $mode === '') {
			return array();
		}

		$is_main = false;
		$id      = (int) $site->get('current_blog_id', 1);

		foreach ($sites as $entry) {
			if ((int) ( $entry['blog_id'] ?? 0 ) === $id) {
				$is_main = ! empty($entry['is_main']);
				break;
			}
		}

		if ($mode === 'subsite' && ! $is_main) {
			// Subsite export: the manifest describes ONE site, no Sites list.
			return array();
		}

		return array(
			'mode' => self::network_mode($sites),
			'sites' => self::sites_block($sites),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $sites Network site metadata.
	 * @return array{Mode:string,MainBlogId:int}
	 */
	private static function network_mode(array $sites): array {
		$main_path = '';
		$main_id   = 1;

		foreach ($sites as $entry) {
			if (! empty($entry['is_main'])) {
				$main_path = trim((string) ( $entry['path'] ?? '' ), '/');
				$main_id   = (int) ( $entry['blog_id'] ?? 1 );
				break;
			}
		}

		$mode = 'subdomain';

		foreach ($sites as $entry) {
			if (! empty($entry['is_main'])) {
				continue;
			}

			if (trim((string) ( $entry['path'] ?? '/' ), '/') !== '') {
				$mode = 'subdirectory';
				break;
			}
		}

		return array(
			'Mode'       => $mode,
			'MainBlogId' => $main_id,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $sites Network site metadata.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sites_block(array $sites): array {
		$manifest = array();

		foreach ($sites as $entry) {
			$manifest[] = array(
				'BlogId'     => (int) ( $entry['blog_id'] ?? 0 ),
				'IsMain'     => ! empty($entry['is_main']),
				'SiteURL'    => (string) ( $entry['site_url'] ?? '' ),
				'HomeURL'    => (string) ( $entry['home_url'] ?? '' ),
				'UploadsURL' => (string) ( $entry['uploads_url'] ?? '' ),
				'Uploads'    => (string) ( $entry['uploads_path'] ?? '' ),
				'Prefix'     => (string) ( $entry['table_prefix'] ?? '' ),
				'Domain'     => (string) ( $entry['domain'] ?? '' ),
				'Path'       => (string) ( $entry['path'] ?? '' ),
			);
		}

		return $manifest;
	}
}
