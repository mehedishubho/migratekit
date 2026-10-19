<?php
/**
 * Filesystem path helpers for storage and backups directories.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Support;

final class Paths {

	public const BACKUPS_DIR_NAME = 'migratekit-backups';

	public static function storage(): string {
		return rtrim(MIGRATEKIT_PATH, '/\\') . DIRECTORY_SEPARATOR . 'storage';
	}

	public static function backups(): string {
		$option = OptionStore::get('migratekit_backups_path');

		if (is_string($option) && $option !== '') {
			$resolved = rtrim($option, '/\\');
			$parent   = dirname($resolved);

			if ($parent !== '' && $parent !== '.' && is_dir($parent)) {
				return $resolved;
			}
		}

		return self::default_backups_path();
	}

	public static function default_backups_path(): string {
		return rtrim((string) WP_CONTENT_DIR, '/\\') . DIRECTORY_SEPARATOR . self::BACKUPS_DIR_NAME;
	}

	/**
	 * Create the storage directory (and its web-access guards) if missing.
	 *
	 * @return void
	 */
	public static function ensure_storage(): void {
		self::protect(self::storage());
	}

	/**
	 * Create the backups directory (and its web-access guards) if missing.
	 *
	 * @return void
	 */
	public static function ensure_backups(): void {
		self::protect(self::backups());
	}

	/**
	 * Create a directory and drop the standard web-access guard files.
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	private static function protect(string $dir): void {
		if (! is_dir($dir)) {
			wp_mkdir_p($dir);
		}

		$guards = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
			'.htaccess'  => "Order deny,allow\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'robots.txt' => "User-agent: *\nDisallow: /\n",
		);

		foreach ($guards as $name => $contents) {
			$file = $dir . DIRECTORY_SEPARATOR . $name;
			if (! file_exists($file)) {
				@file_put_contents($file, $contents);
			}
		}
	}
}
