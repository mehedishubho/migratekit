<?php
/**
 * Rewrites table-prefix placeholders inside quoted identifiers only.
 *
 * This keeps literal values that happen to contain the placeholder untouched.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Database\Sql;

final class IdentifierMapper {

	private function __construct() {
	}

	/**
	 * Replace $search with $replace inside backtick-quoted identifiers.
	 *
	 * @param string $sql    SQL statement.
	 * @param string $search Placeholder prefix.
	 * @param string $replace Real prefix.
	 * @return string
	 */
	public static function map_prefix(string $sql, string $search, string $replace): string {
		if ($search === '' || $search === $replace || strpos($sql, '`') === false) {
			return $sql;
		}

		$length = strlen($sql);
		$out    = '';
		$i      = 0;

		while ($i < $length) {
			$char = $sql[ $i ];

			if ($char === '`') {
				$end = $i + 1;

				while ($end < $length) {
					if ($sql[ $end ] === '`') {
						if ($end + 1 < $length && $sql[ $end + 1 ] === '`') {
							$end += 2;
							continue;
						}

						break;
					}

					++$end;
				}

				$span = substr($sql, $i, $end - $i + 1);
				$out .= str_replace($search, $replace, $span);
				$i    = $end + 1;
				continue;
			}

			if ($char === "'" || $char === '"') {
				$out .= $char;
				++$i;

				while ($i < $length) {
					$current = $sql[ $i ];

					if ($current === '\\' && $i + 1 < $length) {
						$out .= $current . $sql[ $i + 1 ];
						$i   += 2;
						continue;
					}

					$out .= $current;
					++$i;

					if ($current === $char) {
						break;
					}
				}

				continue;
			}

			$out .= $char;
			++$i;
		}

		return $out;
	}
}
