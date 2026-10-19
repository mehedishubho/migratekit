<?php
/**
 * Selects the database driver for the current WordPress installation.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Database\Driver;

final class Factory {

	private function __construct() {
	}

	/**
	 * @param object $wpdb WordPress database object.
	 * @return DriverInterface
	 */
	public static function create($wpdb): DriverInterface {
		if (is_object($wpdb) && isset($wpdb->dbh) && $wpdb->dbh instanceof \mysqli) {
			return new MysqliDriver($wpdb);
		}

		throw new \RuntimeException('MigrateKit could not detect a supported database connection.');
	}
}
