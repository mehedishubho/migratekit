<?php
/**
 * Normalizes CREATE TABLE statements for cross-server portability.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Database;

final class CreateTableNormalizer {

	/**
	 * Collations that are not available on older MySQL/MariaDB servers.
	 *
	 * @var array<string,string>
	 */
	private const COLLATION_MAP = array(
		'utf8mb4_0900_ai_ci' => 'utf8mb4_unicode_ci',
		'utf8mb4_0900_as_ci' => 'utf8mb4_unicode_ci',
		'utf8mb4_0900_as_cs' => 'utf8mb4_unicode_ci',
		'utf8mb4_0900_bin'   => 'utf8mb4_bin',
		'utf8mb3_unicode_ci' => 'utf8mb4_unicode_ci',
		'utf8mb3_general_ci' => 'utf8mb4_general_ci',
	);

	/**
	 * Storage engines that do not exist on a stock MySQL/MariaDB target.
	 *
	 * @var string[]
	 */
	private const ENGINE_MAP = array( 'Aria', 'ColumnStore', 'Spider', 'CONNECT', 'Mroonga', 'S3' );

	private function __construct() {
	}

	/**
	 * Normalize a CREATE TABLE statement.
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return string
	 */
	public static function normalize(string $sql): string {
		$sql = strtr($sql, self::COLLATION_MAP);

		$engines = implode('|', array_map('preg_quote', self::ENGINE_MAP));
		$sql     = (string) preg_replace('/ENGINE\s*=\s*(' . $engines . ')/i', 'ENGINE=InnoDB', $sql);

		$unsupported = array(
			'/\s+PAGE_COMPRESS\s*=\s*\w+/i',
			'/\s+PAGE_COMPRESS_LEVEL\s*=\s*\w+/i',
			'/\s+ENCRYPTED\s*=\s*\w+/i',
			'/\s+TRANSACTIONAL\s*=\s*\w+/i',
			'/\s+SEQUENCE\s*=\s*\w+/i',
			'/\s+WITH\s+SYSTEM\s+VERSIONING/i',
			'/\s+SYSTEM\s+VERSIONING/i',
		);

		foreach ($unsupported as $pattern) {
			$sql = (string) preg_replace($pattern, '', $sql);
		}

		return $sql;
	}

	/**
	 * Whether a statement is a CREATE TABLE statement.
	 *
	 * @param string $sql SQL statement.
	 * @return bool
	 */
	public static function is_create_table(string $sql): bool {
		return stripos(ltrim($sql), 'CREATE TABLE') === 0;
	}
}
