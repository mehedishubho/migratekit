<?php
/**
 * Uniform option access with an in-memory fallback for offline tests.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Support;

final class OptionStore {

	/** @var array<string,mixed> Fallback storage when WordPress is not running. */
	private static $memory = array();

	private function __construct() {
	}

	/**
	 * @param string $key     Option name.
	 * @param mixed  $default Value when the option is missing.
	 * @return mixed
	 */
	public static function get(string $key, $default = null) {
		if (function_exists('get_option')) {
			return get_option($key, $default);
		}

		return array_key_exists($key, self::$memory) ? self::$memory[ $key ] : $default;
	}

	/**
	 * @param string $key   Option name.
	 * @param mixed  $value Value stored without autoloading.
	 * @return void
	 */
	public static function put(string $key, $value): void {
		if (function_exists('update_option')) {
			update_option($key, $value, false);
			return;
		}

		self::$memory[ $key ] = $value;
	}

	/**
	 * @param string $key Option name.
	 * @return void
	 */
	public static function drop(string $key): void {
		if (function_exists('delete_option')) {
			delete_option($key);
			return;
		}

		unset(self::$memory[ $key ]);
	}

	/**
	 * Clear the in-memory fallback (test helper).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$memory = array();
	}
}
