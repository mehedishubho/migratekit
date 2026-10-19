<?php
/**
 * CRC32 (ISO-HDLC / crc32b) helpers used by the .wpress container.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Archive;

final class Crc32 {

	private const READ_SIZE = 1048576;

	/**
	 * CRC32 of a string as 8 lowercase hex characters.
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	public static function hex(string $data): string {
		return hash('crc32b', $data);
	}

	/**
	 * CRC32 of exactly $length bytes read from an open stream.
	 *
	 * @param resource $handle   Open, readable stream positioned at the start.
	 * @param int      $length   Number of bytes to consume.
	 * @return string 8 lowercase hex characters.
	 */
	public static function of_handle($handle, int $length): string {
		$context   = hash_init('crc32b');
		$remaining = $length;

		while ($remaining > 0) {
			$chunk = fread($handle, (int) min($remaining, self::READ_SIZE));

			if ($chunk === false || $chunk === '') {
				break;
			}

			hash_update($context, $chunk);
			$remaining -= strlen($chunk);
		}

		return hash_final($context);
	}

	/**
	 * CRC32 over a byte range of a file.
	 *
	 * @param string   $path   File path.
	 * @param int      $offset Start offset.
	 * @param int|null $length Bytes to read, or null for the rest of the file.
	 * @return string 8 lowercase hex characters.
	 */
	public static function of_file(string $path, int $offset = 0, ?int $length = null): string {
		$handle = fopen($path, 'rb');

		if ($handle === false) {
			throw new \RuntimeException('Unable to read file for CRC32: ' . $path);
		}

		if ($offset > 0) {
			fseek($handle, $offset);
		}

		$size = $length !== null ? $length : (int) filesize($path) - $offset;

		$crc = self::of_handle($handle, $size);
		fclose($handle);

		return $crc;
	}
}
