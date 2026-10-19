<?php
/**
 * MySQL/MariaDB driver backed by the live mysqli handle that WordPress uses.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Database\Driver;

final class MysqliDriver implements DriverInterface {

	/** @var object WordPress $wpdb instance. */
	private $wpdb;

	/** @var \mysqli */
	private $link;

	/** @var int */
	private $last_errno = 0;

	/** @var string */
	private $last_error = '';

	/**
	 * @param object $wpdb WordPress database object exposing a mysqli $dbh.
	 */
	public function __construct($wpdb) {
		if (! is_object($wpdb) || ! isset($wpdb->dbh) || ! ($wpdb->dbh instanceof \mysqli)) {
			throw new \RuntimeException('MigrateKit requires a MySQLi connection to export or import a database.');
		}

		$this->wpdb = $wpdb;
		$this->link = $wpdb->dbh;
	}

	public function query(string $sql) {
		$result = @$this->link->query($sql);

		if ($result === false) {
			$this->last_errno = (int) $this->link->errno;
			$this->last_error = (string) $this->link->error;
		} else {
			$this->last_errno = 0;
			$this->last_error = '';
		}

		return $result;
	}

	public function fetch_assoc($result): ?array {
		if (! ($result instanceof \mysqli_result)) {
			return null;
		}

		$row = $result->fetch_assoc();

		return $row === null ? null : $row;
	}

	public function free_result($result): void {
		if ($result instanceof \mysqli_result) {
			$result->free();
		}
	}

	public function escape(string $value): string {
		return $this->link->real_escape_string($value);
	}

	public function server_info(): string {
		return (string) $this->link->server_info;
	}

	public function database_name(): string {
		return isset($this->wpdb->dbname) ? (string) $this->wpdb->dbname : '';
	}

	public function host(): string {
		return isset($this->wpdb->dbhost) ? (string) $this->wpdb->dbhost : '';
	}

	public function list_tables(): array {
		$result = $this->query('SHOW TABLES');
		$tables = array();

		if ($result === false) {
			return $tables;
		}

		while (($row = $this->fetch_assoc($result)) !== null) {
			$values = array_values($row);
			$tables[] = (string) $values[0];
		}

		$this->free_result($result);

		return $tables;
	}

	public function show_create_table(string $table): ?string {
		$quoted = '`' . str_replace('`', '``', $table) . '`';
		$result = $this->query('SHOW CREATE TABLE ' . $quoted);

		if ($result === false) {
			return null;
		}

		$row = $this->fetch_assoc($result);
		$this->free_result($result);

		if ($row === null) {
			return null;
		}

		$values = array_values($row);

		return isset($values[1]) ? (string) $values[1] : null;
	}

	public function last_errno(): int {
		return $this->last_errno;
	}

	public function last_error(): string {
		return $this->last_error;
	}

	public function close(): void {
		// The connection belongs to WordPress; never close it.
	}
}
