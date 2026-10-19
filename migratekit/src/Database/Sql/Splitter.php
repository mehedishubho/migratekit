<?php
/**
 * Splits a SQL dump into statements while respecting quotes and comments, and
 * streams large dumps without loading them into memory.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Database\Sql;

final class Splitter {

	private const CHUNK_SIZE = 1048576;

	private function __construct() {
	}

	/**
	 * Split a SQL string into individual statements.
	 *
	 * @param string $sql SQL text.
	 * @return string[]
	 */
	public static function split(string $sql): array {
		$statements = array();
		$buffer     = '';
		$state      = self::fresh_state();

		self::feed($sql, $buffer, $state, static function (string $statement) use (&$statements): void {
			$statements[] = $statement;
		});

		$tail = trim($buffer);

		if ($tail !== '') {
			$statements[] = $tail;
		}

		return $statements;
	}

	/**
	 * Stream statements from a file, invoking $callback for each one.
	 *
	 * @param string   $path     SQL file path.
	 * @param callable $callback Receives one statement string.
	 * @return void
	 */
	public static function each_file(string $path, callable $callback): void {
		$handle = fopen($path, 'rb');

		if ($handle === false) {
			throw new \RuntimeException('Unable to read SQL file: ' . $path);
		}

		$buffer = '';
		$state  = self::fresh_state();

		while (! feof($handle)) {
			$chunk = fread($handle, self::CHUNK_SIZE);

			if ($chunk === false || $chunk === '') {
				break;
			}

			self::feed($chunk, $buffer, $state, $callback);
		}

		fclose($handle);

		$tail = trim($buffer);

		if ($tail !== '') {
			$callback($tail);
		}
	}

	/**
	 * @return array<string,bool>
	 */
	private static function fresh_state(): array {
		return array(
			'single' => false,
			'double' => false,
			'backtick' => false,
			'line_comment' => false,
			'block_comment' => false,
		);
	}

	/**
	 * Consume a chunk, emitting completed statements.
	 *
	 * @param string   $chunk    Input chunk.
	 * @param string   $buffer   Statement buffer (by reference).
	 * @param array    $state    Quote/comment state (by reference).
	 * @param callable $callback Statement sink.
	 * @return void
	 */
	private static function feed(string $chunk, string &$buffer, array &$state, callable $callback): void {
		$length = strlen($chunk);

		for ($i = 0; $i < $length; $i++) {
			$char = $chunk[$i];
			$next = $i + 1 < $length ? $chunk[ $i + 1 ] : '';

			if ($state['line_comment']) {
				if ($char === "\n") {
					$state['line_comment'] = false;
				}
				continue;
			}

			if ($state['block_comment']) {
				if ($char === '*' && $next === '/') {
					$state['block_comment'] = false;
					$i++;
				}
				continue;
			}

			$outside = ! $state['single'] && ! $state['double'] && ! $state['backtick'];

			if ($outside) {
				if ($char === '-' && $next === '-') {
					$state['line_comment'] = true;
					$i++;
					continue;
				}

				if ($char === '#') {
					$state['line_comment'] = true;
					continue;
				}

				if ($char === '/' && $next === '*') {
					$state['block_comment'] = true;
					$i++;
					continue;
				}

				if ($char === ';') {
					$statement = trim($buffer);
					$buffer    = '';

					if ($statement !== '') {
						$callback($statement);
					}

					continue;
				}
			}

			if ($char === '\\' && ($state['single'] || $state['double'])) {
				$buffer .= $char;

				if ($i + 1 < $length) {
					$buffer .= $chunk[ ++$i ];
				}

				continue;
			}

			if ($char === "'" && ! $state['double'] && ! $state['backtick']) {
				$state['single'] = ! $state['single'];
			} elseif ($char === '"' && ! $state['single'] && ! $state['backtick']) {
				$state['double'] = ! $state['double'];
			} elseif ($char === '`' && ! $state['single'] && ! $state['double']) {
				$state['backtick'] = ! $state['backtick'];
			}

			$buffer .= $char;
		}
	}
}
