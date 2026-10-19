<?php
/**
 * Rewrites the contents of SQL string literals using a ReplaceEngine.
 *
 * Literals are unescaped to their semantic bytes first, so serialized values
 * keep valid length prefixes, then escaped again through the driver.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Database\Sql;

use MigrateKit\Database\Driver\DriverInterface;
use MigrateKit\Database\ReplaceEngine;

final class LiteralRewriter {

	private function __construct() {
	}

	/**
	 * Rewrite single-quoted literals in a SQL statement.
	 *
	 * @param string          $sql    SQL statement.
	 * @param ReplaceEngine   $engine Replacement engine.
	 * @param DriverInterface $driver Driver used for escaping.
	 * @return string
	 */
	public static function rewrite(string $sql, ReplaceEngine $engine, DriverInterface $driver): string {
		if ($engine->is_empty()) {
			return $sql;
		}

		$length = strlen($sql);
		$out    = '';
		$i      = 0;

		while ($i < $length) {
			$char = $sql[ $i ];

			if ($char !== "'") {
				$out .= $char;
				++$i;
				continue;
			}

			++$i;
			$raw = '';

			while ($i < $length) {
				$current = $sql[ $i ];

				if ($current === '\\' && $i + 1 < $length) {
					$raw .= $current . $sql[ $i + 1 ];
					$i   += 2;
					continue;
				}

				if ($current === "'") {
					if ($i + 1 < $length && $sql[ $i + 1 ] === "'") {
						$raw .= "''";
						$i   += 2;
						continue;
					}

					++$i;
					break;
				}

				$raw .= $current;
				++$i;
			}

			$decoded  = self::unescape($raw);
			$replaced = $engine->apply($decoded);

			$out .= "'" . $driver->escape($replaced) . "'";
		}

		return $out;
	}

	/**
	 * Decode MySQL-style backslash escapes in a literal.
	 *
	 * @param string $value Escaped literal contents.
	 * @return string
	 */
	public static function unescape(string $value): string {
		$length = strlen($value);
		$out    = '';

		for ($i = 0; $i < $length; $i++) {
			$char = $value[ $i ];

			if ($char === "'" && $i + 1 < $length && $value[ $i + 1 ] === "'") {
				$out .= "'";
				++$i;
				continue;
			}

			if ($char !== '\\' || $i + 1 >= $length) {
				$out .= $char;
				continue;
			}

			$next = $value[ ++$i ];

			switch ($next) {
				case '0':
					$out .= "\0";
					break;
				case 'n':
					$out .= "\n";
					break;
				case 'r':
					$out .= "\r";
					break;
				case 't':
					$out .= "\t";
					break;
				case 'b':
					$out .= "\x08";
					break;
				case 'Z':
					$out .= "\x1a";
					break;
				default:
					$out .= $next;
					break;
			}
		}

		return $out;
	}
}
