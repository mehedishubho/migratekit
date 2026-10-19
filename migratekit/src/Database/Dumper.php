<?php
/**
 * Streams a portable SQL dump of the site database.
 *
 * Table identifiers are rewritten to the archive placeholder prefix so the dump
 * can be restored onto any prefix. An optional export find/replace engine runs
 * against row values while dumping; URL/path replacement also happens on
 * import where the destination site is known.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Database;

use MigrateKit\Database\Driver\DriverInterface;

final class Dumper {

	public const MAX_ROWS_PER_INSERT = 1000;

	/** @var DriverInterface */
	private $db;

	/** @var string */
	private $real_prefix;

	/** @var string */
	private $placeholder;

	/** @var ReplaceEngine|null */
	private $replace;

	/** @var string[] Prefix strings seen in the active dump scope. */
	private $table_scope_prefixes = array();

	/**
	 * @param DriverInterface    $db          Database driver.
	 * @param string             $real_prefix Active table prefix.
	 * @param string             $placeholder Archive placeholder prefix.
	 * @param ReplaceEngine|null $replace     Export find/replace engine applied to row values.
	 */
	public function __construct(DriverInterface $db, string $real_prefix, string $placeholder = 'SERVMASK_PREFIX_', ?ReplaceEngine $replace = null) {
		$this->db = $db;
		$this->real_prefix = $real_prefix;
		$this->placeholder = $placeholder;
		$this->replace = $replace;
	}

	/**
	 * Write the dump by invoking $write for each SQL chunk.
	 *
	 * Supported options:
	 *   excluded_tables (string[]), included_tables (string[]),
	 *   no_spam_comments (bool), no_post_revisions (bool)
	 *
	 * @param callable $write   Receives SQL text chunks.
	 * @param array    $options Dump options.
	 * @return void
	 */
	public function dump(callable $write, array $options = array()): void {
		$this->table_scope_prefixes = isset($options['table_scope']) && is_array($options['table_scope'])
			? array_values(array_filter(array_map('strval', $options['table_scope'])))
			: array();

		$write($this->header());

		foreach ($this->tables($options) as $table) {
			$mapped = $this->mapped_table($table);

			$write('DROP TABLE IF EXISTS `' . $mapped . "`;\n");

			$create = $this->db->show_create_table($table);

			if ($create !== null) {
				$write($this->rewrite_create($create, $table, $mapped) . ";\n\n");
			}

			$this->dump_rows($table, $mapped, $write, $options);
		}
	}

	/**
	 * @return string
	 */
	private function header(): string {
		return sprintf(
			"-- MigrateKit SQL Dump\n-- https://getMigrateKit.com\n--\n-- Host: %s\n-- Database: %s\n-- Class: %s\n--\n\n",
			$this->db->host(),
			$this->db->database_name(),
			get_class($this->db)
		);
	}

	/**
	 * @param array $options Dump options.
	 * @return string[]
	 */
	private function tables(array $options): array {
		$tables = $this->db->list_tables();

		$scope = isset($options['table_scope']) && is_array($options['table_scope'])
			? array_values(array_filter(array_map('strval', $options['table_scope'])))
			: array();

		if ($scope !== array()) {
			$scoped = array();

			foreach ($tables as $table) {
				foreach ($scope as $prefix) {
					if (strlen($prefix) > 0 && strpos($table, $prefix) === 0) {
						$scoped[] = $table;
						break;
					}
				}
			}

			$tables = $scoped;
		}

		$extras = isset($options['extra_tables']) && is_array($options['extra_tables'])
			? $options['extra_tables']
			: array();

		foreach ($extras as $table) {
			$table = (string) $table;

			if ($table !== '' && in_array($table, $this->db->list_tables(), true)) {
				$tables[] = $table;
			}
		}

		$tables = array_values(array_unique($tables));

		$included = isset($options['included_tables']) && is_array($options['included_tables'])
			? $options['included_tables']
			: array();

		$excluded = isset($options['excluded_tables']) && is_array($options['excluded_tables'])
			? $options['excluded_tables']
			: array();

		if ($included !== array()) {
			$tables = array_values(array_intersect($tables, $included));
		}

		if ($excluded !== array()) {
			$tables = array_values(array_diff($tables, $excluded));
		}

		sort($tables);

		return $tables;
	}

	/**
	 * @param string $table Real table name.
	 * @return string
	 */
	private function mapped_table(string $table): string {
		if ($this->real_prefix !== '' && strpos($table, $this->real_prefix) === 0) {
			return $this->placeholder . substr($table, strlen($this->real_prefix));
		}

		return $table;
	}

	/**
	 * @param string $create CREATE TABLE statement.
	 * @param string $table  Real table name.
	 * @param string $mapped Placeholder table name.
	 * @return string
	 */
	private function rewrite_create(string $create, string $table, string $mapped): string {
		$pattern     = '/`' . preg_quote($table, '/') . '`/';
		$replacement = '`' . $mapped . '`';

		$rewritten = preg_replace($pattern, $replacement, $create, 1);

		return $rewritten === null ? $create : $rewritten;
	}

	/**
	 * @param string   $table   Real table name.
	 * @param string   $mapped  Placeholder table name.
	 * @param callable $write   SQL sink.
	 * @param array    $options Dump options.
	 * @return void
	 */
	private function dump_rows(string $table, string $mapped, callable $write, array $options): void {
		$where  = $this->where_clause($table, $options);
		$result = $this->db->query('SELECT * FROM `' . $table . '`' . $where);

		if ($result === false) {
			return;
		}

		$columns = array();
		$batch   = array();
		$count   = 0;

		while (($row = $this->db->fetch_assoc($result)) !== null) {
			if ($columns === array()) {
				$columns = array_keys($row);
			}

			$batch[] = '(' . implode(',', $this->row_values($row)) . ')';
			++$count;

			if (count($batch) >= self::MAX_ROWS_PER_INSERT) {
				$write($this->insert_statement($mapped, $columns, $batch));
				$batch = array();
			}
		}

		$this->db->free_result($result);

		if ($batch !== array()) {
			$write($this->insert_statement($mapped, $columns, $batch));
		}

		if ($count > 0) {
			$write("\n");
		}
	}

	/**
	 * @param string   $table   Real table name.
	 * @param array    $options Dump options.
	 * @return string
	 */
	private function where_clause(string $table, array $options): string {
		$suffix = $this->table_suffix($table);

		if (! empty($options['no_post_revisions']) && preg_match('/^(?:\d+_)?posts$/', $suffix) === 1) {
			return " WHERE `post_type` <> 'revision'";
		}

		if (! empty($options['no_spam_comments']) && preg_match('/^(?:\d+_)?comments$/', $suffix) === 1) {
			return " WHERE `comment_approved` <> 'spam'";
		}

		return '';
	}

	/**
	 * Suffix of a table relative to its most specific known prefix.
	 *
	 * Non-solid tables (global or subsite with digits) keep the numeric segment
	 * so `wp_2_posts` resolves to `2_posts`. Filters that care about the table
	 * kind strip the digits themselves.
	 *
	 * @param string $table Real table name.
	 * @return string
	 */
	private function table_suffix(string $table): string {
		$prefixes = array( $this->real_prefix );

		$extra = isset($this->table_scope_prefixes) ? $this->table_scope_prefixes : array();
		$prefixes = array_merge($prefixes, $extra);
		$prefixes = array_values(array_unique(array_filter($prefixes, static function (string $p): bool {
			return $p !== '';
		})));

		usort($prefixes, static function (string $a, string $b): int {
			return strlen($b) <=> strlen($a);
		});

		foreach ($prefixes as $prefix) {
			if (strpos($table, $prefix) === 0) {
				return substr($table, strlen($prefix));
			}
		}

		return $table;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return string[]
	 */
	private function row_values(array $row): array {
		$values = array();

		foreach ($row as $value) {
			if ($value === null) {
				$values[] = 'NULL';
				continue;
			}

			$value = (string) $value;

			if ($this->replace !== null) {
				$value = $this->replace->apply($value);
			}

			$values[] = "'" . $this->db->escape($value) . "'";
		}

		return $values;
	}

	/**
	 * @param string   $mapped  Placeholder table name.
	 * @param string[] $columns Column names.
	 * @param string[] $tuples  Parenthesised value tuples.
	 * @return string
	 */
	private function insert_statement(string $mapped, array $columns, array $tuples): string {
		$quoted = array_map(
			static function (string $column): string {
				return '`' . str_replace('`', '``', $column) . '`';
			},
			$columns
		);

		return 'INSERT INTO `' . $mapped . '` (' . implode(',', $quoted) . ') VALUES '
			. implode(',', $tuples) . ";\n";
	}
}
