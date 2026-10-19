<?php
/**
 * PHP-serialization-aware string replacement.
 *
 * Walks serialized data recursively, replaces substrings inside string values,
 * and rewrites the s:N length prefixes so the result stays valid. Returns null
 * when the input is not a complete serialized value, signalling the caller to
 * fall back to plain replacement.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Database;

final class SerializedParser {

	private function __construct() {
	}

	/**
	 * Replace substrings inside a serialized value.
	 *
	 * @param string                $data Serialized value.
	 * @param array<string,string>  $map  Search => replacement map.
	 * @return string|null Rewritten serialized value, or null if not serialized.
	 */
	public static function replace(string $data, array $map): ?string {
		if ($data === '' || $map === array()) {
			return null;
		}

		$offset = 0;
		$result = self::parse($data, $offset, $map);

		if ($result === null || $offset !== strlen($data)) {
			return null;
		}

		return $result;
	}

	/**
	 * @param string               $data   Serialized value.
	 * @param int                  $offset Cursor, advanced past the token.
	 * @param array<string,string> $map    Search => replacement map.
	 * @return string|null
	 */
	private static function parse(string $data, int &$offset, array $map): ?string {
		$length = strlen($data);

		if ($offset >= $length) {
			return null;
		}

		switch ($data[$offset]) {
			case 'N':
				if (substr($data, $offset, 2) !== 'N;') {
					return null;
				}
				$offset += 2;
				return 'N;';

			case 'b':
				if (preg_match('/\Gb:[01];/', $data, $match, 0, $offset) !== 1) {
					return null;
				}
				$offset += strlen($match[0]);
				return $match[0];

			case 'i':
				if (preg_match('/\Gi:[+-]?\d+;/', $data, $match, 0, $offset) !== 1) {
					return null;
				}
				$offset += strlen($match[0]);
				return $match[0];

			case 'd':
				if (preg_match('/\Gd:(?:[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?|NAN|INF|-INF);/', $data, $match, 0, $offset) !== 1) {
					return null;
				}
				$offset += strlen($match[0]);
				return $match[0];

			case 'R':
			case 'r':
				if (preg_match('/\G[rR]:\d+;/', $data, $match, 0, $offset) !== 1) {
					return null;
				}
				$offset += strlen($match[0]);
				return $match[0];

			case 's':
				return self::parse_string($data, $offset, $map);

			case 'a':
				return self::parse_array($data, $offset, $map);

			case 'O':
				return self::parse_object($data, $offset, $map);

			case 'C':
				return self::parse_custom($data, $offset);

			default:
				return null;
		}
	}

	/**
	 * @param string               $data   Serialized value.
	 * @param int                  $offset Cursor.
	 * @param array<string,string> $map    Replacement map.
	 * @return string|null
	 */
	private static function parse_string(string $data, int &$offset, array $map): ?string {
		if (preg_match('/\Gs:(\d+):"/', $data, $match, 0, $offset) !== 1) {
			return null;
		}

		$declared   = (int) $match[1];
		$start      = $offset + strlen($match[0]);
		$total      = strlen($data);

		if ($start + $declared + 2 > $total) {
			return null;
		}

		if (substr($data, $start + $declared, 2) !== '";') {
			return null;
		}

		$payload      = substr($data, $start, $declared);
		$replaced     = strtr($payload, $map);

		$offset = $start + $declared + 2;

		return 's:' . strlen($replaced) . ':"' . $replaced . '";';
	}

	/**
	 * @param string               $data   Serialized value.
	 * @param int                  $offset Cursor.
	 * @param array<string,string> $map    Replacement map.
	 * @return string|null
	 */
	private static function parse_array(string $data, int &$offset, array $map): ?string {
		if (preg_match('/\Ga:(\d+):\{/', $data, $match, 0, $offset) !== 1) {
			return null;
		}

		$count  = (int) $match[1];
		$cursor = $offset + strlen($match[0]);
		$out    = 'a:' . $count . ':{';

		for ($i = 0; $i < $count; $i++) {
			$key = self::parse($data, $cursor, $map);

			if ($key === null) {
				return null;
			}

			$value = self::parse($data, $cursor, $map);

			if ($value === null) {
				return null;
			}

			$out .= $key . $value;
		}

		if (substr($data, $cursor, 1) !== '}') {
			return null;
		}

		$offset = $cursor + 1;

		return $out . '}';
	}

	/**
	 * @param string               $data   Serialized value.
	 * @param int                  $offset Cursor.
	 * @param array<string,string> $map    Replacement map.
	 * @return string|null
	 */
	private static function parse_object(string $data, int &$offset, array $map): ?string {
		if (preg_match('/\GO:(\d+):"/', $data, $match, 0, $offset) !== 1) {
			return null;
		}

		$class_length = (int) $match[1];
		$cursor       = $offset + strlen($match[0]);

		if ($cursor + $class_length + 2 > strlen($data)) {
			return null;
		}

		$class = substr($data, $cursor, $class_length);
		$cursor += $class_length;

		if (substr($data, $cursor, 2) !== '":') {
			return null;
		}

		$cursor += 2;

		if (preg_match('/\G(\d+):\{/', $data, $count_match, 0, $cursor) !== 1) {
			return null;
		}

		$count  = (int) $count_match[1];
		$cursor += strlen($count_match[0]);
		$out    = 'O:' . $class_length . ':"' . $class . '":' . $count . ':{';

		for ($i = 0; $i < $count; $i++) {
			$key = self::parse($data, $cursor, $map);

			if ($key === null) {
				return null;
			}

			$value = self::parse($data, $cursor, $map);

			if ($value === null) {
				return null;
			}

			$out .= $key . $value;
		}

		if (substr($data, $cursor, 1) !== '}') {
			return null;
		}

		$offset = $cursor + 1;

		return $out . '}';
	}

	/**
	 * Custom serialized objects (C:) carry opaque payloads; copy them verbatim.
	 *
	 * @param string $data   Serialized value.
	 * @param int    $offset Cursor.
	 * @return string|null
	 */
	private static function parse_custom(string $data, int &$offset): ?string {
		if (preg_match('/\GC:(\d+):"/', $data, $match, 0, $offset) !== 1) {
			return null;
		}

		$class_length = (int) $match[1];
		$cursor       = $offset + strlen($match[0]);

		if ($cursor + $class_length + 2 > strlen($data)) {
			return null;
		}

		$class = substr($data, $cursor, $class_length);
		$cursor += $class_length;

		if (substr($data, $cursor, 2) !== '":') {
			return null;
		}

		$cursor += 2;

		if (preg_match('/\G(\d+):\{/', $data, $length_match, 0, $cursor) !== 1) {
			return null;
		}

		$payload_length = (int) $length_match[1];
		$cursor += strlen($length_match[0]);

		if ($cursor + $payload_length + 1 > strlen($data)) {
			return null;
		}

		$payload = substr($data, $cursor, $payload_length);

		if (substr($data, $cursor + $payload_length, 1) !== '}') {
			return null;
		}

		$offset = $cursor + $payload_length + 1;

		return 'C:' . $class_length . ':"' . $class . '":' . $payload_length . ':{' . $payload . '}';
	}
}
