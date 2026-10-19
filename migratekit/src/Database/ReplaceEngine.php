<?php
/**
 * Applies URL/path replacements to database values, safely handling PHP
 * serialization and base64-encoded payloads.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Database;

final class ReplaceEngine {

	/** @var array<string,string> */
	private $map = array();

	/**
	 * @param string[] $search  Values to find.
	 * @param string[] $replace Corresponding replacements.
	 */
	public function __construct(array $search, array $replace) {
		foreach ($search as $index => $needle) {
			$needle = (string) $needle;

			if ($needle === '' || ! array_key_exists($index, $replace)) {
				continue;
			}

			$this->map[ $needle ] = (string) $replace[ $index ];
		}
	}

	public function is_empty(): bool {
		return $this->map === array();
	}

	/**
	 * @return array<string,string>
	 */
	public function map(): array {
		return $this->map;
	}

	/**
	 * Replace within one database value.
	 *
	 * Serialized values are rewritten structurally; everything else is a plain
	 * substring replacement plus a base64-aware pass.
	 *
	 * @param string $value Raw database value.
	 * @return string
	 */
	public function apply(string $value): string {
		if ($this->map === array() || $value === '') {
			return $value;
		}

		$serialized = SerializedParser::replace($value, $this->map);

		if ($serialized !== null) {
			return $serialized;
		}

		$value = strtr($value, $this->map);

		return $this->replace_base64($value);
	}

	/**
	 * Replace occurrences inside base64-encoded runs.
	 *
	 * Only touches runs that decode to valid UTF-8 and actually contain a search
	 * string, which keeps false positives out of binary payloads.
	 *
	 * @param string $value Value to scan.
	 * @return string
	 */
	private function replace_base64(string $value): string {
		$result = preg_replace_callback(
			'/[A-Za-z0-9+\/]{16,}={0,2}/',
			function (array $match): string {
				$decoded = base64_decode($match[0], true);

				if ($decoded === false || $decoded === '' || preg_match('//u', $decoded) !== 1) {
					return $match[0];
				}

				$replaced = strtr($decoded, $this->map);

				if ($replaced === $decoded) {
					return $match[0];
				}

				return base64_encode($replaced);
			},
			$value
		);

		return $result === null ? $value : $result;
	}
}
