<?php
/**
 * Minimal PSR-4 autoloader so MigrateKit runs without a Composer autoloader
 * present in the deployed plugin.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Support;

final class Autoloader {

	/**
	 * Register a PSR-4 prefix -> directory mapping.
	 *
	 * @param string $prefix Class namespace prefix, e.g. "MigrateKit\\".
	 * @param string $dir    Absolute directory that holds the mapped classes.
	 * @return void
	 */
	public static function register(string $prefix, string $dir): void {
		$prefix = ltrim($prefix, '\\');
		$dir    = rtrim($dir, '/\\');

		spl_autoload_register(
			static function (string $class) use ($prefix, $dir): void {
				if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
					return;
				}

				$relative = substr($class, strlen($prefix));
				$file     = $dir . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

				if (is_file($file)) {
					require $file;
				}
			}
		);
	}
}
