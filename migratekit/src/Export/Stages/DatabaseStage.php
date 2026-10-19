<?php
/**
 * Dumps the database to storage/database.sql.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export\Stages;

use MigrateKit\Archive\Protocol;
use MigrateKit\Database\Dumper;
use MigrateKit\Database\ReplaceEngine;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class DatabaseStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (! empty($state['database_done'])) {
			return true;
		}

		if (! empty($state['options']['no_database'])) {
			$state['database_done'] = true;
			$state['database_file'] = null;
			return true;
		}

		if ($ctx->db === null) {
			throw new \RuntimeException('A database driver is required to export the database.');
		}

		$file   = rtrim($state['storage_dir'], '/\\') . DIRECTORY_SEPARATOR . 'database.sql';
		$handle = fopen($file, 'wb');

		if ($handle === false) {
			throw new \RuntimeException('Unable to write database dump: ' . $file);
		}

		$replace = self::replace_engine($state);

		$dumper = new Dumper(
			$ctx->db,
			(string) $ctx->site->get('table_prefix', 'wp_'),
			Protocol::TABLE_PREFIX,
			$replace
		);

		$dumper->dump(
			static function (string $chunk) use ($handle): void {
				fwrite($handle, $chunk);
			},
			array(
				'excluded_tables'   => isset($state['options']['excluded_tables']) ? (array) $state['options']['excluded_tables'] : array(),
				'included_tables'   => isset($state['options']['included_tables']) ? (array) $state['options']['included_tables'] : array(),
				'no_post_revisions' => ! empty($state['options']['no_post_revisions']),
				'no_spam_comments'  => ! empty($state['options']['no_spam_comments']),
				'table_scope'       => self::table_scope($ctx->site, $state),
				'extra_tables'      => self::extra_tables($ctx->site, $state),
			)
		);

		fclose($handle);

		$state['database_file'] = $file;
		$state['database_done'] = true;

		$ctx->report('Dumped the database');

		return true;
	}

	/**
	 * Find/replace engine from the export options (null when no pairs set).
	 *
	 * @param array<string,mixed> $state Job state.
	 * @return ReplaceEngine|null
	 */
	private static function replace_engine(array $state): ?ReplaceEngine {
		$old = isset($state['options']['replace_old']) && is_array($state['options']['replace_old'])
			? array_map('strval', array_values($state['options']['replace_old']))
			: array();

		$new = isset($state['options']['replace_new']) && is_array($state['options']['replace_new'])
			? array_map('strval', array_values($state['options']['replace_new']))
			: array();

		if ($old === array() || $old === array( '' ) || implode('', $old) === '') {
			return null;
		}

		return new ReplaceEngine($old, $new);
	}

	/**
	 * Table prefixes included by the export.
	 *
	 * Whole-network exports cover the base prefix (site tables plus globals)
	 * and every subsite prefix. Subsite exports scope to their own prefix and
	 * pull the shared user tables in via the extra table list.
	 *
	 * @param \MigrateKit\Site\SiteContext $site  Site metadata.
	 * @param array<string,mixed>          $state Job state.
	 * @return string[]
	 */
	private static function table_scope(\MigrateKit\Site\SiteContext $site, array $state): array {
		$mode  = isset($state['options']['network']) ? (string) $state['options']['network'] : '';
		$sites = (array) $site->get('network_sites', array());

		if ($sites === array() || $mode === '') {
			return array();
		}

		if ($mode === 'whole') {
			$prefixes = array( (string) $site->get('base_prefix', 'wp_') );

			foreach ($sites as $entry) {
				$prefix = (string) ( $entry['table_prefix'] ?? '' );

				if ($prefix !== '' && ! in_array($prefix, $prefixes, true)) {
					$prefixes[] = $prefix;
				}
			}

			return $prefixes;
		}

		$id = (int) $site->get('current_blog_id', 1);

		foreach ($sites as $entry) {
			if ((int) ( $entry['blog_id'] ?? 0 ) === $id) {
				$prefix = (string) ( $entry['table_prefix'] ?? '' );

				return $prefix === '' ? array() : array( $prefix );
			}
		}

		return array( (string) $site->get('table_prefix', 'wp_') );
	}

	/**
	 * Exact tables added beyond the prefixes (shared user tables for subsite
	 * exports, so their accounts come along).
	 *
	 * @param \MigrateKit\Site\SiteContext $site  Site metadata.
	 * @param array<string,mixed>          $state Job state.
	 * @return string[]
	 */
	private static function extra_tables(\MigrateKit\Site\SiteContext $site, array $state): array {
		$mode = isset($state['options']['network']) ? (string) $state['options']['network'] : '';
		$sites = (array) $site->get('network_sites', array());

		if ($sites === array() || $mode !== 'subsite') {
			return array();
		}

		$base = (string) $site->get('base_prefix', (string) $site->get('table_prefix', 'wp_'));

		return array( $base . 'users', $base . 'usermeta' );
	}
}
