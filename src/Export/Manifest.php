<?php
/**
 * On-disk file list for one export source.
 *
 * The list lives in the job's storage directory instead of the job state, so a
 * site with hundreds of thousands of files keeps its state tiny. Each entry is
 * a 12-byte header (4-byte path length, 8-byte size) followed by the path.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export;

final class Manifest {

	private const HEADER_PACK   = 'VP';
	private const HEADER_UNPACK = 'Vlength/Psize';
	private const HEADER_LENGTH = 12;

	private function __construct() {
	}

	/**
	 * @param string $storage_dir Job storage directory.
	 * @param string $key         Manifest key, e.g. "media".
	 * @return string
	 */
	public static function path(string $storage_dir, string $key): string {
		return rtrim($storage_dir, '/\\') . DIRECTORY_SEPARATOR . 'manifest-' . $key . '.list';
	}

	/**
	 * Companion file holding the directories still to be scanned.
	 *
	 * @param string $storage_dir Job storage directory.
	 * @param string $key         Manifest key.
	 * @return string
	 */
	public static function queue_path(string $storage_dir, string $key): string {
		return rtrim($storage_dir, '/\\') . DIRECTORY_SEPARATOR . 'manifest-' . $key . '.dirs';
	}

	/**
	 * Add an entry at the stream's end.
	 *
	 * @param resource $handle Stream opened for writing.
	 * @param string   $path   Path relative to the source root.
	 * @param int      $size   File size in bytes.
	 * @return void
	 */
	public static function append($handle, string $path, int $size = 0): void {
		$entry = pack(self::HEADER_PACK, strlen($path), $size) . $path;

		if (@fwrite($handle, $entry) !== strlen($entry)) {
			throw new \RuntimeException('Unable to write the file list — the disk may be full.');
		}
	}

	/**
	 * Read the entry at the stream's current position and advance past it.
	 *
	 * @param resource $handle Stream opened for reading.
	 * @return array{path:string,size:int}|null Null at the end of the list or on a truncated entry.
	 */
	public static function read($handle): ?array {
		$header = fread($handle, self::HEADER_LENGTH);

		if (! is_string($header) || strlen($header) < self::HEADER_LENGTH) {
			return null;
		}

		$fields = unpack(self::HEADER_UNPACK, $header);

		if (! is_array($fields)) {
			return null;
		}

		$length = (int) $fields['length'];
		$path   = $length > 0 ? fread($handle, $length) : '';

		if (! is_string($path) || strlen($path) !== $length) {
			return null;
		}

		return array(
			'path' => $path,
			'size' => (int) $fields['size'],
		);
	}
}
