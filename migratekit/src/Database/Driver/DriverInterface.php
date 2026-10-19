<?php
/**
 * Database driver contract used by the dumper and importer.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Database\Driver;

interface DriverInterface {

	/**
	 * Execute a query.
	 *
	 * @param string $sql SQL statement.
	 * @return mixed Result handle, true for non-SELECT success, false on failure.
	 */
	public function query(string $sql);

	/**
	 * Fetch the next row as an associative array, or null at end of result.
	 *
	 * @param mixed $result Result handle.
	 * @return array<string,mixed>|null
	 */
	public function fetch_assoc($result): ?array;

	/**
	 * Release a result handle.
	 *
	 * @param mixed $result Result handle.
	 * @return void
	 */
	public function free_result($result): void;

	/**
	 * Escape a raw string for use inside a single-quoted literal.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function escape(string $value): string;

	public function server_info(): string;

	public function database_name(): string;

	public function host(): string;

	/**
	 * @return string[] Table names without quoting.
	 */
	public function list_tables(): array;

	/**
	 * @param string $table Table name.
	 * @return string|null CREATE TABLE statement, or null.
	 */
	public function show_create_table(string $table): ?string;

	public function last_errno(): int;

	public function last_error(): string;

	public function close(): void;
}
