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

	/** Target size of one row page, so tables with wide rows cannot exhaust memory. */
	public const PAGE_BYTES = 4194304;

	/** Rows in a table's first page; later pages grow with the measured row size. */
	private const FIRST_PAGE_ROWS = 1;

	/** Declared types whose values compare correctly as unquoted numbers. */
	private const NUMERIC_TYPE = '/^(?:tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|dec|float|double|real|year)\b/i';

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
	 * Write the whole dump by invoking $write for each SQL chunk.
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
		$cursor = array();

		while ( ! $this->dump_chunk($cursor, $write, $options) ) {
		}
	}

	/**
	 * Write one slice of the dump, stopping at $deadline.
	 *
	 * $cursor is the dump position: start with an empty array and pass it back
	 * in to continue where the previous slice stopped.
	 *
	 * @param array      $cursor   Dump position (by reference).
	 * @param callable   $write    Receives SQL text chunks.
	 * @param array      $options  Dump options.
	 * @param float|null $deadline Wall-clock time to stop at, or null for none.
	 * @return bool True when the whole dump has been written.
	 */
	public function dump_chunk(array &$cursor, callable $write, array $options = array(), ?float $deadline = null): bool {
		$this->table_scope_prefixes = isset($options['table_scope']) && is_array($options['table_scope'])
			? array_values(array_filter(array_map('strval', $options['table_scope'])))
			: array();

		if ( ! isset( $cursor['tables'] ) ) {
			$cursor['tables'] = $this->tables($options);
			$cursor['table']  = 0;
			$cursor['phase']  = 'schema';

			$write( $this->header() );
		}

		$count = count( $cursor['tables'] );

		while ( $cursor['table'] < $count ) {
			$table  = $cursor['tables'][ $cursor['table'] ];
			$mapped = $this->mapped_table( $table );

			if ( $cursor['phase'] === 'schema' ) {
				$write( 'DROP TABLE IF EXISTS `' . $mapped . "`;\n" );

				$create = $this->db->show_create_table( $table );

				$this->assert_answered( 'Unable to read the structure of table `' . $table . '`' );

				if ( $create !== null ) {
					$write( $this->rewrite_create( $create, $table, $mapped ) . ";\n\n" );
				}

				$this->begin_rows( $cursor, $table, $options );
			}

			if ( ! $this->dump_page( $cursor, $table, $mapped, $write ) ) {
				if ( $cursor['count'] > 0 ) {
					$write( "\n" );
				}

				++$cursor['table'];
				$cursor['phase'] = 'schema';

				if ( $deadline !== null && microtime( true ) >= $deadline ) {
					return $cursor['table'] >= $count;
				}

				continue;
			}

			if ( $deadline !== null && microtime( true ) >= $deadline ) {
				return false;
			}
		}

		return true;
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

		$this->assert_answered( 'Unable to list the database tables' );

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
	 * Work out how the table's rows will be paged and reset the per-table cursor.
	 *
	 * @param array  $cursor  Dump cursor (by reference).
	 * @param string $table   Real table name.
	 * @param array  $options Dump options.
	 * @return void
	 */
	private function begin_rows(array &$cursor, string $table, array $options): void {
		$cursor['phase']  = 'rows';
		$cursor['key']    = $this->primary_key($table);
		$cursor['filter'] = $this->row_filter($table, $options);
		$cursor['last']   = array();
		$cursor['offset'] = 0;
		$cursor['count']  = 0;
		$cursor['limit']  = self::FIRST_PAGE_ROWS;
	}

	/**
	 * Primary key columns in index order, each with a flag telling whether its
	 * values can be compared as numbers.
	 *
	 * @param string $table Real table name.
	 * @return array{columns:string[],numeric:bool[]} Empty when the table cannot be paged by key.
	 */
	private function primary_key(string $table): array {
		$none    = array( 'columns' => array(), 'numeric' => array() );
		$quoted  = '`' . str_replace('`', '``', $table) . '`';
		$columns = array();
		$result  = $this->db->query('SHOW KEYS FROM ' . $quoted . " WHERE `Key_name` = 'PRIMARY'");

		if ($result === false) {
			return $none;
		}

		while ( ( $row = $this->db->fetch_assoc($result) ) !== null ) {
			if ( isset( $row['Column_name'] ) ) {
				$columns[ (int) ( $row['Seq_in_index'] ?? count( $columns ) + 1 ) ] = (string) $row['Column_name'];
			}
		}

		$this->db->free_result($result);

		if ($columns === array()) {
			return $none;
		}

		ksort($columns);

		// Without the declared types a key value cannot be quoted safely, so fall back to paging by offset.
		$types = $this->column_types($table);

		if ($types === null) {
			return $none;
		}

		$numeric = array();

		foreach ($columns as $column) {
			$numeric[] = isset( $types[ $column ] ) && preg_match(self::NUMERIC_TYPE, $types[ $column ]) === 1;
		}

		return array(
			'columns' => array_values($columns),
			'numeric' => $numeric,
		);
	}

	/**
	 * @param string $table Real table name.
	 * @return string[]|null Column name => declared type, or null when the server did not answer.
	 */
	private function column_types(string $table): ?array {
		$quoted = '`' . str_replace('`', '``', $table) . '`';
		$result = $this->db->query('SHOW COLUMNS FROM ' . $quoted);

		if ($result === false) {
			return null;
		}

		$types = array();

		while ( ( $row = $this->db->fetch_assoc($result) ) !== null ) {
			if ( isset( $row['Field'], $row['Type'] ) ) {
				$types[ (string) $row['Field'] ] = (string) $row['Type'];
			}
		}

		$this->db->free_result($result);

		return $types;
	}

	/**
	 * Row exclusion for a table, such as post revisions or spam comments.
	 *
	 * @param string $table   Real table name.
	 * @param array  $options Dump options.
	 * @return string Bare SQL condition, or '' for none.
	 */
	private function row_filter(string $table, array $options): string {
		$suffix = $this->table_suffix($table);

		if (! empty($options['no_post_revisions']) && preg_match('/^(?:\d+_)?posts$/', $suffix) === 1) {
			return "`post_type` <> 'revision'";
		}

		if (! empty($options['no_spam_comments']) && preg_match('/^(?:\d+_)?comments$/', $suffix) === 1) {
			return "`comment_approved` <> 'spam'";
		}

		return '';
	}

	/**
	 * Write the next page of a table's rows.
	 *
	 * @param array    $cursor Dump cursor (by reference).
	 * @param string   $table  Real table name.
	 * @param string   $mapped Placeholder table name.
	 * @param callable $write  SQL sink.
	 * @return bool True when the table may have more rows.
	 */
	private function dump_page(array &$cursor, string $table, string $mapped, callable $write): bool {
		$limit    = (int) $cursor['limit'];
		$previous = $cursor['last'];
		$result   = $this->db->query($this->page_sql($cursor, $table));

		if ($result === false) {
			throw $this->refused( 'Unable to read the rows of table `' . $table . '`' );
		}

		$columns = array();
		$tuples  = array();
		$rows    = 0;
		$widest  = 0;
		$last    = array();

		while ( ( $row = $this->db->fetch_assoc($result) ) !== null ) {
			if ($columns === array()) {
				$columns = array_keys($row);
			}

			$tuple    = '(' . implode(',', $this->row_values($row)) . ')';
			$size     = strlen($tuple) + 1;
			$tuples[] = $tuple;
			$widest   = max( $widest, $size );
			++$rows;
			$last     = array();

			foreach ( $cursor['key']['columns'] as $column ) {
				$last[] = array_key_exists($column, $row) ? (string) $row[ $column ] : '';
			}
		}

		$this->db->free_result($result);

		if ( $tuples !== array() ) {
			$write( $this->insert_statement($mapped, $columns, $tuples) );
		}

		$cursor['count']  += $rows;
		$cursor['offset'] += $rows;
		$cursor['last']    = $last;

		// A short page ends the table; so does a page that ends on the same key as the last one, which means paging is not advancing.
		if ( $rows < $limit || ( $previous !== array() && $last === $previous ) ) {
			return false;
		}

		/*
		 * Size the next page from the rows just seen. It grows at most four-fold, so one
		 * small row cannot invite a thousand large ones, and it holds about one page's
		 * worth of the widest row seen, so a run of large ones cannot pile up either.
		 */
		$cursor['limit'] = max(
			1,
			min(
				self::MAX_ROWS_PER_INSERT,
				(int) floor( self::PAGE_BYTES / max( 1, $widest ) ),
				$limit * 4
			)
		);

		return true;
	}

	/**
	 * Fail a lookup the server refused. A table it would not read must never be
	 * archived as if it were empty.
	 *
	 * @param string $what What was being read.
	 * @return void
	 */
	private function assert_answered( string $what ): void {
		if ( $this->db->last_errno() !== 0 ) {
			throw $this->refused( $what );
		}
	}

	/**
	 * @param string $what What was being read.
	 * @return \RuntimeException
	 */
	private function refused( string $what ): \RuntimeException {
		$error = $this->db->last_error();

		return new \RuntimeException( $what . ' — ' . ( $error !== '' ? $error : 'the database did not say why.' ) );
	}

	/**
	 * The row query for the current page. Tables with a primary key are paged by
	 * key, which stays fast on tables with millions of rows; the rest by offset.
	 *
	 * @param array  $cursor Dump cursor.
	 * @param string $table  Real table name.
	 * @return string
	 */
	private function page_sql(array $cursor, string $table): string {
		$conditions = array();
		$keyed      = $cursor['key']['columns'] !== array();

		if ( $cursor['filter'] !== '' ) {
			$conditions[] = $cursor['filter'];
		}

		if ( $keyed && $cursor['last'] !== array() ) {
			$conditions[] = $this->after_key($cursor);
		}

		$sql = 'SELECT * FROM `' . $table . '`';

		if ( $conditions !== array() ) {
			$sql .= ' WHERE ' . implode(' AND ', $conditions);
		}

		if ( $keyed ) {
			$order = array();

			foreach ( $cursor['key']['columns'] as $column ) {
				$order[] = '`' . str_replace('`', '``', $column) . '`';
			}

			$sql .= ' ORDER BY ' . implode(', ', $order);
		}

		$sql .= ' LIMIT ' . (int) $cursor['limit'];

		if ( ! $keyed && (int) $cursor['offset'] > 0 ) {
			$sql .= ' OFFSET ' . (int) $cursor['offset'];
		}

		return $sql;
	}

	/**
	 * Condition selecting the rows that sort after the last key read from a table.
	 *
	 * @param array $cursor Dump cursor.
	 * @return string
	 */
	private function after_key(array $cursor): string {
		$scopes = array();
		$equal  = array();
		$index  = 0;

		foreach ( $cursor['key']['columns'] as $column ) {
			$quoted   = '`' . str_replace('`', '``', $column) . '`';
			$literal  = $this->key_literal($cursor, $index);
			$scopes[] = '( ' . implode(' AND ', array_merge($equal, array( $quoted . ' > ' . $literal ))) . ' )';
			$equal[]  = $quoted . ' = ' . $literal;
			++$index;
		}

		return count($scopes) === 1 ? $scopes[0] : '( ' . implode(' OR ', $scopes) . ' )';
	}

	/**
	 * @param array $cursor Dump cursor.
	 * @param int   $index  Key column index.
	 * @return string
	 */
	private function key_literal(array $cursor, int $index): string {
		$value = (string) ( $cursor['last'][ $index ] ?? '' );

		if ( $cursor['key']['numeric'][ $index ] && is_numeric($value) ) {
			return $value;
		}

		return "'" . $this->db->escape($value) . "'";
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
