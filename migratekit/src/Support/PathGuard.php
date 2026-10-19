<?php
/**
 * Safe path joining for archive extraction.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Support;

final class PathGuard {

	private function __construct() {
	}

	/**
	 * Join a base directory and an archive-relative path, rejecting traversal.
	 *
	 * @param string $base     Destination directory.
	 * @param string $relative Archive-relative path.
	 * @return string|null Absolute path, or null when the relative path escapes.
	 */
	public static function join(string $base, string $relative): ?string {
		$relative = ltrim(str_replace('\\', '/', $relative), '/');

		if ($relative === '') {
			return null;
		}

		foreach (explode('/', $relative) as $segment) {
			if ($segment === '..') {
				return null;
			}
		}

		return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
	}
}
