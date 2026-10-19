<?php
/**
 * Derives placeholder table-name mappings for an import.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Import;

use MigrateKit\Archive\Protocol;
use MigrateKit\Site\SiteContext;

final class TableMappings {

	private function __construct() {
	}

	/**
	 * Placeholder mappings described by an archive manifest.
	 *
	 * Whole-network manifests list every subsite; each maps its numbered
	 * placeholder onto the destination's matching blog prefix. Subsite
	 * manifests carry no list, but their database prefix is the numbered
	 * source prefix; plain destinations fold the subsite onto their own
	 * bare prefix.
	 *
	 * @param array       $package package.json from the archive.
	 * @param SiteContext $site    Destination site.
	 * @return array<int,array{placeholder:string,real:string}>
	 */
	public static function build(array $package, SiteContext $site): array {
		$mappings = array();
		$base     = (string) $site->get('base_prefix', $site->get('table_prefix', 'wp_'));

		$sites = isset($package['Sites']) && is_array($package['Sites']) ? $package['Sites'] : array();

		foreach ($sites as $entry) {
			if (! is_array($entry) || ! empty($entry['IsMain'])) {
				continue;
			}

			$blog_id = (int) ( $entry['BlogId'] ?? 0 );

			if ($blog_id <= 0) {
				continue;
			}

			$mappings[] = array(
				'placeholder' => Protocol::TABLE_PREFIX . $blog_id . '_',
				'real'        => $base . $blog_id . '_',
			);
		}

		if ($sites !== array()) {
			return $mappings;
		}

		$source_prefix = isset($package['Database']['Prefix']) ? (string) $package['Database']['Prefix'] : '';

		if (preg_match('/^(.+?)(\d+)_$/', $source_prefix, $match) !== 1) {
			return $mappings;
		}

		$mappings[] = array(
			'placeholder' => Protocol::TABLE_PREFIX . (int) $match[2] . '_',
			'real'        => (string) $site->get('table_prefix', $base),
		);

		return $mappings;
	}
}
