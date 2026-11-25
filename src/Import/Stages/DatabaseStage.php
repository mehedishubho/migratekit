<?php
/**
 * Imports database.sql into the destination database.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Import\Stages;

use MigrateKit\Archive\Protocol;
use MigrateKit\Database\Importer;
use MigrateKit\Import\ReplaceMatrixBuilder;
use MigrateKit\Import\TableMappings;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class DatabaseStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (! empty($state['database_done'])) {
			return true;
		}

		if (empty($state['database_file']) || ! is_file($state['database_file'])) {
			$state['database_done'] = true;
			return true;
		}

		if ($ctx->db === null) {
			throw new \RuntimeException('A database driver is required to import the database.');
		}

		$package = isset($state['package']) && is_array($state['package']) ? $state['package'] : array();

		$engine = ReplaceMatrixBuilder::build(
			$package,
			$ctx->site,
			self::skip_email_replace($state['options'] ?? array(), $package)
		);

		// On networks the network tables live under the base prefix; plain
		// installs always use their own prefix.
		$prefix      = (string) $ctx->site->get('table_prefix', 'wp_');
		$base_prefix = (string) ($ctx->site->get('is_multisite', false) ? $ctx->site->get('base_prefix', $prefix) : $prefix);
		$importer    = new Importer($ctx->db, $base_prefix, Protocol::TABLE_PREFIX, $engine);

		// Whole-network manifests map every subsite; subsite manifests fold
		// their numbered source prefix onto the destination's own numbering.
		$importer->add_mappings(TableMappings::build($package, $ctx->site));

		$importer->import_file((string) $state['database_file']);

		// Every table now holds what the backup held, while WordPress still has the old rows in its object cache.
		// Left that way, a write goes against rows that are gone: among them the job's own state, which would
		// then never be saved, and the next request would find the job gone.
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}

		// The options table is now the backup's: whatever the plugin keeps there is what the backup held.
		$state['replaced_options'] = true;
		$state['database_done']    = true;

		$ctx->report('Imported the database');

		return true;
	}

	/**
	 * Email-domain replacement is skipped per the request flag or a source
	 * archive that recorded its own NoEmailReplace.
	 *
	 * @param array<string,mixed> $options Import options.
	 * @param array<string,mixed> $package Source manifest.
	 * @return bool True when the engine should leave email domains alone.
	 */
	public static function skip_email_replace(array $options, array $package): bool {
		return ! empty($options['no_email_replace']) || ! empty($package['NoEmailReplace']);
	}
}
