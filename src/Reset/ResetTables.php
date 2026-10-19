<?php
/**
 * WordPress content tables owned by the reset pipeline.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Reset;

final class ResetTables {

	/**
	 * Core tables emptied by a site reset, keyed in dependency-safe order.
	 *
	 * @var string[]
	 */
	public const CORE = array(
		'posts',
		'postmeta',
		'comments',
		'commentmeta',
		'links',
		'term_relationships',
		'term_taxonomy',
		'terms',
		'termmeta',
	);

	private function __construct() {
	}

	/**
	 * Qualified names of the core content tables that exist for this site.
	 *
	 * @param string        $prefix     Current table prefix.
	 * @param string[]      $tables     All table names in the database.
	 * @param string[]|null $core       Core table suffixes, or null for all.
	 * @return array<string,string> Suffix => full table name (present only).
	 */
	public static function present(string $prefix, array $tables, ?array $core = null): array {
		$core   = $core === null ? self::CORE : $core;
		$lookup = array_flip($tables);
		$found  = array();

		foreach ($core as $suffix) {
			$name = $prefix . $suffix;

			if (isset($lookup[ $name ])) {
				$found[ $suffix ] = $name;
			}
		}

		return $found;
	}

	/**
	 * Non-core tables that carry the current prefix (plugin and data tables).
	 *
	 * @param string   $prefix   Current table prefix.
	 * @param string[] $tables   All table names in the database.
	 * @return string[] Full table names in stable order.
	 */
	public static function third_party(string $prefix, array $tables): array {
		$checked = array();

		foreach (self::present($prefix, $tables) as $name) {
			$checked[ $name ] = true;
		}

		sort($tables);

		$extra = array();

		foreach ($tables as $table) {
			if (strpos($table, $prefix) !== 0 || isset($checked[ $table ])) {
				continue;
			}

			$extra[] = $table;
		}

		return $extra;
	}
}
