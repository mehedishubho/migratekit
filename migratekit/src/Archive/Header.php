<?php
/**
 * The 4377-byte .wpress record header and end-of-archive sentinel block.
 *
 * Layout (all fields NUL-padded, ASCII unless noted):
 *   0     255  name        basename of the archived file (NUL => EOF block)
 *   255    14  size        archived byte count, decimal
 *   269    12  mtime       Unix mtime, decimal
 *   281  4088  path        dirname, "/" separated, "." for the archive root
 *   4369    8  crc32       CRC32 of the original file content, 8 hex chars
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Archive;

final class Header {

	public const LENGTH        = 4377;
	public const NAME_OFFSET   = 0;
	public const NAME_LENGTH   = 255;
	public const SIZE_OFFSET   = 255;
	public const SIZE_LENGTH   = 14;
	public const MTIME_OFFSET  = 269;
	public const MTIME_LENGTH  = 12;
	public const PATH_OFFSET   = 281;
	public const PATH_LENGTH   = 4088;
	public const CRC_OFFSET    = 4369;
	public const CRC_LENGTH    = 8;

	/** Records always stored raw: no compression, encryption or length prefix. */
	public const UNPROCESSED = array('package.json', 'multisite.json');

	private const RECORD_FORMAT = 'a255a14a12a4088a8';
	private const PARSE_FORMAT  = 'a255name/a14size/a12mtime/a4088path/a8crc32';
	private const EOF_FORMAT    = 'a255a14a4100a8';
	private const TRIM_CHARLIST = " \t\n\r\0\x0B";

	private function __construct() {
	}

	/**
	 * Pack a record header. Pass "" for $size/$crc to write placeholders.
	 *
	 * @param string $name  Basename.
	 * @param string $size  Archived size as decimal text.
	 * @param string $mtime Unix mtime as decimal text.
	 * @param string $path  "/"-separated dirname, or "." for root.
	 * @param string $crc   8 hex chars, or "".
	 * @return string 4377 bytes.
	 */
	public static function pack_record(string $name, string $size, string $mtime, string $path, string $crc): string {
		return pack(self::RECORD_FORMAT, $name, $size, $mtime, $path, $crc);
	}

	/**
	 * Pack the end-of-archive sentinel block (v2, CRC-bearing).
	 *
	 * @param string $eof_start Absolute offset at which this block begins.
	 * @param string $archive_crc CRC32 of the bytes before this block.
	 * @return string 4377 bytes.
	 */
	public static function pack_eof(string $eof_start, string $archive_crc): string {
		return pack(self::EOF_FORMAT, '', $eof_start, '', $archive_crc);
	}

	/**
	 * Split a record block into its trimmed fields.
	 *
	 * @param string $block 4377 bytes.
	 * @return array{name:string,size:string,mtime:string,path:string,crc:string}
	 */
	public static function parse(string $block): array {
		$fields = unpack(self::PARSE_FORMAT, $block);

		if (! is_array($fields)) {
			throw new \RuntimeException('Unable to parse .wpress header block.');
		}

		return array(
			'name'  => trim((string) $fields['name'], self::TRIM_CHARLIST),
			'size'  => trim((string) $fields['size'], self::TRIM_CHARLIST),
			'mtime' => trim((string) $fields['mtime'], self::TRIM_CHARLIST),
			'path'  => trim((string) $fields['path'], self::TRIM_CHARLIST),
			'crc'   => trim((string) $fields['crc32'], self::TRIM_CHARLIST),
		);
	}

	/**
	 * Whether a block is the legacy all-NUL end-of-archive sentinel (v1).
	 *
	 * @param string $block 4377 bytes.
	 * @return bool
	 */
	public static function is_eof_v1(string $block): bool {
		return $block === str_repeat("\0", self::LENGTH);
	}

	/**
	 * Whether a block is a well-formed v2 (CRC-bearing) sentinel.
	 *
	 * Validated by strict re-pack comparison, matching the strict reader.
	 *
	 * @param string $block 4377 bytes.
	 * @return bool
	 */
	public static function is_eof_v2(string $block): bool {
		if (strlen($block) < self::LENGTH) {
			return false;
		}

		$fields = unpack('a255/a14size/a4100/a8crc32', $block);

		if (! is_array($fields)) {
			return false;
		}

		$crc = (string) $fields['crc32'];

		if (preg_match('/^[0-9a-f]{8}$/i', $crc) !== 1) {
			return false;
		}

		return $block === self::pack_eof((string) $fields['size'], $crc);
	}

	/**
	 * Whether a block is an end-of-archive sentinel of either version.
	 *
	 * @param string $block 4377 bytes.
	 * @return bool
	 */
	public static function is_eof(string $block): bool {
		return self::is_eof_v1($block) || self::is_eof_v2($block);
	}
}
