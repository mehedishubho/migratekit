<?php
/**
 * Shared secret used to authorize public export/import endpoints.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Support;

final class SecretKey {

	public const OPTION = 'migratekit_secret_key';

	private function __construct() {
	}

	public static function get(): string {
		$key = OptionStore::get(self::OPTION);

		if (! is_string($key) || $key === '') {
			$key = self::regenerate();
		}

		return $key;
	}

	public static function regenerate(): string {
		$key = is_callable('wp_generate_password') ? wp_generate_password(24, false, false) : '';

		if (! is_string($key) || $key === '') {
			$key = substr(bin2hex(random_bytes(12)), 0, 24);
		}

		OptionStore::put(self::OPTION, $key);

		return $key;
	}

	/**
	 * Constant-time verification of a supplied secret.
	 *
	 * @param mixed $provided Supplied secret.
	 * @return bool
	 */
	public static function verify($provided): bool {
		if (! is_string($provided) || $provided === '') {
			return false;
		}

		return hash_equals(self::get(), $provided);
	}
}
