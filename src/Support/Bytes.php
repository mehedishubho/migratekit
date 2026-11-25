<?php
/**
 * Human-readable byte counts.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Support;

final class Bytes {

	private const UNITS = array( 'B', 'KB', 'MB', 'GB', 'TB' );

	private function __construct() {
	}

	/**
	 * @param int $bytes Byte count.
	 * @return string e.g. "9.1 GB".
	 */
	public static function format(int $bytes): string {
		$value = max(0, $bytes);
		$unit  = 0;

		while ($value >= 1024 && $unit < count(self::UNITS) - 1) {
			$value /= 1024;
			++$unit;
		}

		return $unit === 0 ? $value . ' B' : sprintf('%.1f %s', $value, self::UNITS[ $unit ]);
	}
}
