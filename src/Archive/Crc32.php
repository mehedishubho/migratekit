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

	/** CRC-32 polynomial in bit-reflected form. */
	private const POLYNOMIAL = 0xEDB88320;

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
		return self::stream($handle, $length, null)['crc'];
	}

	/**
	 * Hash up to $length bytes from an open stream, stopping early at $deadline.
	 *
	 * @param resource    $handle   Open, readable stream.
	 * @param int         $length   Number of bytes to consume.
	 * @param float|null  $deadline Wall-clock time to stop at, or null for none.
	 * @return array{crc:string,read:int} CRC32 of the bytes read, and how many that was.
	 */
	public static function stream($handle, int $length, ?float $deadline = null): array {
		$context   = hash_init('crc32b');
		$remaining = $length;

		while ($remaining > 0) {
			$chunk = fread($handle, (int) min($remaining, self::READ_SIZE));

			if ($chunk === false || $chunk === '') {
				break;
			}

			hash_update($context, $chunk);
			$remaining -= strlen($chunk);

			if ($deadline !== null && microtime(true) >= $deadline) {
				break;
			}
		}

		return array(
			'crc'  => hash_final($context),
			'read' => $length - $remaining,
		);
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

	/**
	 * CRC32 of two byte strings joined together, computed from their separate CRC32 values.
	 *
	 * Lets a checksum be carried across requests: combine the running value with
	 * each new part. This is zlib's crc32_combine.
	 *
	 * @param string|null $first         CRC32 of everything hashed so far, or null before any.
	 * @param string      $second        CRC32 of the new part.
	 * @param int         $second_length Byte count of the new part.
	 * @return string 8 lowercase hex characters.
	 */
	public static function combine(?string $first, string $second, int $second_length): string {
		if ($first === null || $first === '') {
			return $second;
		}

		if ($second_length <= 0) {
			return $first;
		}

		$crc1 = (int) hexdec($first) & 0xFFFFFFFF;
		$crc2 = (int) hexdec($second) & 0xFFFFFFFF;
		$len  = $second_length;

		// Operators for appending 1, 2 and 4 zero bits.
		$odd = array( self::POLYNOMIAL );
		$row = 1;

		for ($n = 1; $n < 32; $n++) {
			$odd[] = $row;
			$row <<= 1;
		}

		$even = self::square($odd);
		$odd  = self::square($even);

		do {
			// Appends 8 zero bits per set bit of $len: the operator of one zero byte lands in $even.
			$even = self::square($odd);

			if ($len & 1) {
				$crc1 = self::multiply($even, $crc1);
			}

			$len >>= 1;

			if ($len === 0) {
				break;
			}

			$odd = self::square($even);

			if ($len & 1) {
				$crc1 = self::multiply($odd, $crc1);
			}

			$len >>= 1;
		} while ($len !== 0);

		return sprintf('%08x', ( $crc1 ^ $crc2 ) & 0xFFFFFFFF);
	}

	/**
	 * Multiply a vector by a matrix over GF(2), zlib's gf2_matrix_times().
	 *
	 * @param int[] $matrix 32 rows.
	 * @param int   $vector 32-bit value.
	 * @return int
	 */
	private static function multiply(array $matrix, int $vector): int {
		$sum = 0;
		$row = 0;

		while ($vector !== 0) {
			if ($vector & 1) {
				$sum ^= $matrix[ $row ];
			}

			$vector >>= 1;
			++$row;
		}

		return $sum;
	}

	/**
	 * Square a GF(2) matrix, doubling the number of zero bits its operator appends.
	 *
	 * @param int[] $matrix 32 rows.
	 * @return int[]
	 */
	private static function square(array $matrix): array {
		$result = array();

		for ($n = 0; $n < 32; $n++) {
			$result[] = self::multiply($matrix, $matrix[ $n ]);
		}

		return $result;
	}
}
