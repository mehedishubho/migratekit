<?php
/**
 * Dependency-free test suite for the database layer.
 *
 * Usage: php tests/run-database.php
 *
 * @package MigrateKit
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MigrateKit\Database\CreateTableNormalizer;
use MigrateKit\Database\Dumper;
use MigrateKit\Database\Driver\DriverInterface;
use MigrateKit\Database\Importer;
use MigrateKit\Database\ReplaceEngine;
use MigrateKit\Database\SerializedParser;
use MigrateKit\Database\Sql\LiteralRewriter;
use MigrateKit\Database\Sql\Splitter;

$GLOBALS['db_pass'] = 0;
$GLOBALS['db_fail'] = 0;

function check_db(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['db_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['db_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function same_db(string $label, $expected, $actual): void {
	if ($expected === $actual) {
		$GLOBALS['db_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['db_fail']++;
		echo "FAIL  - {$label}\n";
		echo '        expected: ' . var_export($expected, true) . "\n";
		echo '        actual:   ' . var_export($actual, true) . "\n";
	}
}

/**
 * In-memory result handle.
 */
final class FakeResult {
	/** @var array<int,array<string,mixed>> */
	public $rows;

	public function __construct(array $rows) {
		$this->rows = $rows;
	}
}

/**
 * In-memory driver used by the dumper/importer tests.
 *
 * A table is its columns and rows, plus optionally `key` (primary key columns)
 * with `numeric` flags. A keyed table answers the key lookups the dumper asks
 * for and is paged by key the way a real server pages it; `stuck` makes the key
 * condition never advance.
 */
final class FakeDriver implements DriverInterface {
	/** @var array<string,array{columns:string[],rows:array<int,array<string,mixed>>,key?:string[],numeric?:bool[],stuck?:bool}> */
	public $tables = array();

	/** @var string[] */
	public $statements = array();

	/** @var string|null */
	public $fail_contains = null;

	/** @var bool */
	public $fail_listing = false;

	/** @var string[] Tables whose structure lookup fails. */
	public $fail_structure = array();

	/** @var int */
	private $last_errno = 0;

	/** @var string */
	private $last_error = '';

	public function query(string $sql) {
		$this->statements[] = $sql;

		if ($this->fail_contains !== null && strpos($sql, $this->fail_contains) !== false) {
			return $this->failed();
		}

		$this->last_errno = 0;
		$this->last_error = '';

		if (preg_match('/^SHOW KEYS FROM `([^`]+)`/', $sql, $match) === 1) {
			$rows = array();

			foreach ($this->key_of($match[1]) as $index => $column) {
				$rows[] = array( 'Key_name' => 'PRIMARY', 'Seq_in_index' => $index + 1, 'Column_name' => $column );
			}

			return new FakeResult($rows);
		}

		if (preg_match('/^SHOW COLUMNS FROM `([^`]+)`/', $sql, $match) === 1) {
			$rows  = array();
			$table = $match[1];

			foreach ($this->tables[ $table ]['columns'] as $column) {
				$numeric = array_search($column, $this->key_of($table), true);
				$rows[]  = array(
					'Field' => $column,
					'Type'  => $numeric !== false && ! empty($this->tables[ $table ]['numeric'][ $numeric ] ) ? 'bigint' : 'varchar(255)',
				);
			}

			return new FakeResult($rows);
		}

		if (preg_match('/^SELECT \* FROM `([^`]+)`(.*)$/iu', trim($sql), $match) === 1) {
			return new FakeResult($this->page($match[1], $match[2]));
		}

		return true;
	}

	/**
	 * The rows one row query returns.
	 *
	 * @param string $table Table name.
	 * @param string $rest  SQL after the table name.
	 * @return array<int,array<string,mixed>>
	 */
	private function page(string $table, string $rest): array {
		$definition = $this->tables[ $table ] ?? array( 'rows' => array() );
		$rows       = $definition['rows'];
		$where      = preg_match('/\bWHERE\s+(.*?)\s+(?:ORDER BY|LIMIT)\b/is', $rest, $match) === 1 ? $match[1] : '';

		// The key condition is the last top-level term, after any row filter.
		if (! empty($definition['stuck']) && $where !== '') {
			$cut   = strrpos($where, ' AND ( ' );
			$where = $cut === false ? '' : substr($where, 0, $cut);
		}

		if ($where !== '') {
			$rows = array_values(
				array_filter(
					$rows,
					fn( array $row ): bool => $this->matches($row, $where)
				)
			);
		}

		$key = $this->key_of($table);

		if ($key !== array()) {
			$numeric = $definition['numeric'] ?? array();

			usort(
				$rows,
				static function (array $a, array $b) use ($key, $numeric): int {
					foreach ($key as $index => $column) {
						$order = ! empty($numeric[ $index ] )
							? (float) $a[ $column ] <=> (float) $b[ $column ]
							: strcmp((string) $a[ $column ], (string) $b[ $column ]);

						if ($order !== 0) {
							return $order;
						}
					}

					return 0;
				}
			);
		}

		if (preg_match('/\bLIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?$/i', $rest, $limit) === 1) {
			$rows = array_slice($rows, isset($limit[2]) && $limit[2] !== '' ? (int) $limit[2] : 0, (int) $limit[1]);
		}

		return $rows;
	}

	/**
	 * Evaluate the WHERE clause the dumper builds: comparisons joined by AND and OR inside parentheses.
	 *
	 * @param array<string,mixed> $row   Candidate row.
	 * @param string              $where Bare SQL condition.
	 * @return bool
	 */
	private function matches(array $row, string $where): bool {
		preg_match_all('/\(|\)|`[^`]+`|<>|>|=|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s()]+/', $where, $match);

		$tokens = $match[0];
		$at     = 0;
		$peek   = static function () use ($tokens, &$at) {
			return $tokens[ $at ] ?? null;
		};
		$atom = $term = $expr = null;

		$atom = static function (array $row) use (&$atom, &$expr, $peek, &$at, $tokens): bool {
			if ($peek() === '(') {
				++$at;
				$value = $expr($row);
				++$at;

				return $value;
			}

			$column   = trim((string) $tokens[ $at++ ], '`');
			$operator = (string) $tokens[ $at++ ];
			$literal  = (string) $tokens[ $at++ ];
			$number   = $literal[0] !== "'";
			$left     = $row[ $column ] ?? '';
			$order    = $number
				? (float) $left <=> (float) $literal
				: strcmp((string) $left, LiteralRewriter::unescape(substr($literal, 1, -1)));

			if ($operator === '>') {
				return $order > 0;
			}

			return $operator === '<>' ? $order !== 0 : $order === 0;
		};

		$term = static function (array $row) use (&$term, $atom, $peek, &$at): bool {
			$value = $atom($row);

			while ($peek() === 'AND') {
				++$at;
				$value = $atom($row) && $value;
			}

			return $value;
		};

		$expr = static function (array $row) use (&$expr, $term, $peek, &$at): bool {
			$value = $term($row);

			while ($peek() === 'OR') {
				++$at;
				$value = $term($row) || $value;
			}

			return $value;
		};

		return $expr($row);
	}

	/**
	 * @param string $table Table name.
	 * @return string[]
	 */
	private function key_of(string $table): array {
		return $this->tables[ $table ]['key'] ?? array();
	}

	/**
	 * @return false
	 */
	private function failed() {
		$this->last_errno = 1064;
		$this->last_error = 'synthetic failure';

		return false;
	}

	/**
	 * Like the real driver, a lookup that succeeds clears the last error.
	 */
	private function answered(): void {
		$this->last_errno = 0;
		$this->last_error = '';
	}

	public function fetch_assoc($result): ?array {
		if (! ($result instanceof FakeResult) || $result->rows === array()) {
			return null;
		}

		return array_shift($result->rows);
	}

	public function free_result($result): void {
	}

	public function escape(string $value): string {
		return str_replace(
			array( '\\', "\0", "\n", "\r", "'", '"', "\x1a" ),
			array( '\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z' ),
			$value
		);
	}

	public function server_info(): string {
		return '8.0.0-fake';
	}

	public function database_name(): string {
		return 'wp';
	}

	public function host(): string {
		return 'localhost';
	}

	public function list_tables(): array {
		if ($this->fail_listing) {
			$this->failed();

			return array();
		}

		$this->answered();

		return array_keys($this->tables);
	}

	public function show_create_table(string $table): ?string {
		if (in_array($table, $this->fail_structure, true)) {
			$this->failed();

			return null;
		}

		$this->answered();

		return 'CREATE TABLE `' . $table . "` (\n  `id` int NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
	}

	public function last_errno(): int {
		return $this->last_errno;
	}

	public function last_error(): string {
		return $this->last_error;
	}

	public function close(): void {
	}
}

echo "MigrateKit — database layer suite\n\n";

// ---------------------------------------------------------------------------
echo "[1] SerializedParser: length-safe replacement\n";
$old = 'http://old.test';
$new = 'https://new.example.com';
$value = serialize(
	array(
		'siteurl' => $old,
		'home'    => $old . '/home',
		'nested'  => array( 'url' => $old . '/nested', 'n' => 5 ),
		'plain'   => 'no url here',
	)
);
$rewritten = SerializedParser::replace($value, array( $old => $new ));
check_db('serialized value rewritten', $rewritten !== null);
$decoded = unserialize($rewritten);
same_db('top-level string replaced', $new, $decoded['siteurl']);
same_db('nested string replaced', $new . '/nested', $decoded['nested']['url']);
same_db('integer untouched', 5, $decoded['nested']['n']);
check_db('replacement is longer than source', strlen($new) > strlen($old));

$multibyte = serialize(array('u' => $old . '/über'));
$mb_out = SerializedParser::replace($multibyte, array($old => $new));
$mb_decoded = unserialize($mb_out);
same_db('multibyte payload length fixed', $new . '/über', $mb_decoded['u']);

same_db('non-serialized input returns null', null, SerializedParser::replace('just text', array($old => $new)));
same_db('empty map returns null', null, SerializedParser::replace($value, array()));
same_db('trailing garbage rejected', null, SerializedParser::replace($value . 'x', array($old => $new)));

// Object payload.
$object = 'O:8:"stdClass":1:{s:3:"url";s:' . strlen($old) . ':"' . $old . '";}';
$object_out = SerializedParser::replace($object, array($old => $new));
check_db('object string payload replaced', strpos($object_out, 's:' . strlen($new) . ':"' . $new . '"') !== false);

// ---------------------------------------------------------------------------
echo "\n[2] ReplaceEngine\n";
$engine = new ReplaceEngine(array($old, 'files/'), array($new, 'media/'));
same_db('plain URL replaced', 'see ' . $new . ' now', $engine->apply('see ' . $old . ' now'));
same_db('multiple needles replaced', 'media/x/' . $new, $engine->apply('files/x/' . $old));
$ser = serialize(array('a' => $old . '/a'));
same_db('serialized handled structurally', true, strpos($engine->apply($ser), 's:' . strlen($new . '/a') . ':') !== false);
$b64_payload = 'prefix ' . base64_encode($old . '/path') . ' suffix';
$b64_out = $engine->apply($b64_payload);
check_db('base64 payload replaced', strpos($b64_out, base64_encode($new . '/path')) !== false);
same_db('empty engine is a no-op', 'value', (new ReplaceEngine(array(), array()))->apply('value'));
same_db('empty needle ignored', 'value', (new ReplaceEngine(array(''), array('x')))->apply('value'));

// ---------------------------------------------------------------------------
echo "\n[3] SQL Splitter\n";
$sql = "-- a comment\nCREATE TABLE `a` (`id` int);\n"
	. "INSERT INTO `a` VALUES ('one;two', \"x;y\", 'it\\'s');\n"
	. "/* block ; comment */\nUPDATE `a` SET `v` = 'z' WHERE `id` = 1;";
$statements = Splitter::split($sql);
same_db('statement count', 3, count($statements));
check_db('semicolon inside single quotes preserved', strpos($statements[1], "'one;two'") !== false);
check_db('semicolon inside double quotes preserved', strpos($statements[1], '"x;y"') !== false);
check_db('escaped quote preserved', strpos($statements[1], "it\\'s") !== false);
check_db('line comment stripped', strpos($statements[0], '--') === false);
check_db('block comment stripped', strpos($statements[2], '/*') === false);
same_db('backtick identifiers intact', true, strpos($statements[0], '`a`') !== false);

$tmp = sys_get_temp_dir() . '/mk-split-' . bin2hex(random_bytes(4)) . '.sql';
file_put_contents($tmp, $sql);
$streamed = array();
Splitter::each_file($tmp, static function (string $statement) use (&$streamed): void {
	$streamed[] = $statement;
});
same_db('each_file yields same statement count', 3, count($streamed));
unlink($tmp);

// ---------------------------------------------------------------------------
echo "\n[4] LiteralRewriter\n";
$driver = new FakeDriver();
$literal_engine = new ReplaceEngine(array($old), array($new));
$insert = "INSERT INTO `wp_options` (`option_value`) VALUES ('" . $driver->escape($old . '/x') . "')";
$out = LiteralRewriter::rewrite($insert, $literal_engine, $driver);
check_db('literal URL replaced', strpos($out, $new . '/x') !== false);
check_db('identifier untouched', strpos($out, '`wp_options`') !== false);

$serialized_literal = serialize(array('url' => $old . '/y'));
$escaped_literal = $driver->escape($serialized_literal);
$out2 = LiteralRewriter::rewrite("INSERT INTO `t` VALUES ('" . $escaped_literal . "')", $literal_engine, $driver);
preg_match("/VALUES \\('(.*)'\\)$/s", $out2, $m);
$decoded_literal = LiteralRewriter::unescape($m[1]);
check_db('serialized literal still unserializes', is_array(@unserialize($decoded_literal)));
$round = @unserialize($decoded_literal);
same_db('serialized literal URL replaced', $new . '/y', is_array($round) ? $round['url'] : null);

same_db('unescape backslash', 'a\\b', LiteralRewriter::unescape('a\\\\b'));
same_db('unescape quote', "a'b", LiteralRewriter::unescape("a\\'b"));
same_db('unescape newline', "a\nb", LiteralRewriter::unescape('a\\nb'));
same_db('unescape doubled quote', "a'b", LiteralRewriter::unescape("a''b"));

// ---------------------------------------------------------------------------
echo "\n[5] CreateTableNormalizer\n";
$create = 'CREATE TABLE `t` (`id` int) ENGINE=Aria DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci PAGE_COMPRESS=1 WITH SYSTEM VERSIONING';
$normalized = CreateTableNormalizer::normalize($create);
check_db('Aria converted to InnoDB', strpos($normalized, 'ENGINE=InnoDB') !== false);
check_db('0900 collation downgraded', strpos($normalized, 'utf8mb4_unicode_ci') !== false);
check_db('PAGE_COMPRESS removed', stripos($normalized, 'PAGE_COMPRESS') === false);
check_db('SYSTEM VERSIONING removed', stripos($normalized, 'SYSTEM VERSIONING') === false);
check_db('is_create_table detects', CreateTableNormalizer::is_create_table('  CREATE TABLE `x` (id int)'));
check_db('is_create_table rejects INSERT', ! CreateTableNormalizer::is_create_table('INSERT INTO x VALUES (1)'));

// ---------------------------------------------------------------------------
echo "\n[6] Dumper\n";
$db = new FakeDriver();
$db->tables = array(
	'wp_posts'    => array(
		'columns' => array( 'ID', 'post_type', 'post_title' ),
		'rows'    => array(
			array( 'ID' => 1, 'post_type' => 'post', 'post_title' => "Hello 'world'" ),
			array( 'ID' => 2, 'post_type' => 'revision', 'post_title' => 'Revision' ),
		),
	),
	'wp_options'  => array(
		'columns' => array( 'option_id', 'option_name', 'option_value' ),
		'rows'    => array(
			array( 'option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'http://old.test' ),
			array( 'option_id' => 2, 'option_name' => 'null_option', 'option_value' => null ),
		),
	),
	'wp_comments' => array(
		'columns' => array( 'comment_ID', 'comment_approved' ),
		'rows'    => array(
			array( 'comment_ID' => 1, 'comment_approved' => '1' ),
			array( 'comment_ID' => 2, 'comment_approved' => 'spam' ),
		),
	),
	'other_table' => array(
		'columns' => array( 'id' ),
		'rows'    => array( array( 'id' => 9 ) ),
	),
);

$output = '';
$dumper = new Dumper($db, 'wp_', 'SERVMASK_PREFIX_');
$dumper->dump(
	static function (string $chunk) use (&$output): void {
		$output .= $chunk;
	},
	array(
		'no_post_revisions'  => true,
		'no_spam_comments'   => true,
		'excluded_tables'    => array( 'other_table' ),
	)
);

check_db('header present', strpos($output, 'MigrateKit SQL Dump') !== false);
check_db('drop uses placeholder prefix', strpos($output, 'DROP TABLE IF EXISTS `SERVMASK_PREFIX_posts`;') !== false);
check_db('create rewritten to placeholder', strpos($output, 'CREATE TABLE `SERVMASK_PREFIX_posts`') !== false);
check_db('insert targets placeholder', strpos($output, 'INSERT INTO `SERVMASK_PREFIX_posts`') !== false);
check_db('revision row excluded', strpos($output, 'Revision') === false);
check_db('normal post included', strpos($output, "Hello \\'world\\'") !== false);
check_db('spam comment excluded', strpos($output, "'spam'") === false);
check_db('non-spam comment included', strpos($output, "('1','1')") !== false);
check_db('excluded table absent', strpos($output, 'other_table') === false);
check_db('NULL rendered as NULL', strpos($output, 'NULL') !== false);

// Unprefixed tables are emitted under their own name.
$plain_output = '';
$dumper->dump(
	static function (string $chunk) use (&$plain_output): void {
		$plain_output .= $chunk;
	},
	array( 'included_tables' => array( 'other_table' ) )
);
check_db('unprefixed table keeps its name', strpos($plain_output, 'DROP TABLE IF EXISTS `other_table`;') !== false);

// Included-table filter.
$included_output = '';
$dumper->dump(
	static function (string $chunk) use (&$included_output): void {
		$included_output .= $chunk;
	},
	array( 'included_tables' => array( 'wp_options' ) )
);
check_db('included-only dump has options', strpos($included_output, 'SERVMASK_PREFIX_options') !== false);
check_db('included-only dump omits posts', strpos($included_output, 'SERVMASK_PREFIX_posts') === false);

// Slicing the dump across requests yields the same SQL as one pass.
$sliced       = '';
$slice_cursor = array();
$slice_guard  = 0;

do {
	$sliced_db       = new FakeDriver();
	$sliced_db->tables = $db->tables;

	// An already-expired deadline writes about one page of rows per request.
	$slice_finished = (new Dumper($sliced_db, 'wp_', 'SERVMASK_PREFIX_'))->dump_chunk(
		$slice_cursor,
		static function (string $chunk) use (&$sliced): void {
			$sliced .= $chunk;
		},
		array(
			'no_post_revisions' => true,
			'no_spam_comments'  => true,
			'excluded_tables'   => array( 'other_table' ),
		),
		microtime(true)
	);
} while ( ! $slice_finished && ++$slice_guard < 200 );

check_db('sliced dump finishes', $slice_finished);
check_db('sliced dump needed several requests', $slice_guard >= 2);
same_db('sliced dump matches one pass', $output, $sliced);

// ---------------------------------------------------------------------------
echo "\n[6b] Row paging by key\n";

/**
 * Rows of every INSERT into one table, in dump order: one array of rows per statement.
 */
function db_inserts(string $dump, string $mapped): array {
	preg_match_all('/^INSERT INTO `' . $mapped . '` \([^)]+\) VALUES (.+);$/m', $dump, $statements);

	$inserts = array();

	foreach ($statements[1] as $values) {
		$rows = array();

		foreach (explode('),(', substr($values, 1, -1)) as $tuple) {
			$rows[] = db_cells($tuple);
		}

		$inserts[] = $rows;
	}

	return $inserts;
}

/**
 * Cell values of one tuple, read left to right so a very long value cannot exhaust the regex engine.
 *
 * @param string $tuple "(v1,v2,…)" with the outer parentheses removed.
 * @return array<int,string|null>
 */
function db_cells(string $tuple): array {
	$cells  = array();
	$cell   = '';
	$quoted = false;

	for ( $i = 0, $length = strlen( $tuple ); $i < $length; $i++ ) {
		$char = $tuple[ $i ];

		if ($quoted) {
			if ( $char === '\\' && $i + 1 < $length ) {
				$cell .= $char . $tuple[ ++$i ];
			} elseif ($char === "'") {
				$quoted  = false;
				$cells[] = LiteralRewriter::unescape( $cell );
				$cell    = '';
			} else {
				$cell .= $char;
			}
		} elseif ($char === "'") {
			$quoted = true;
		} elseif (substr($tuple, $i, 4) === 'NULL') {
			$cells[] = null;
			$i      += 3;
		}
	}

	return $cells;
}

function db_rows(array $inserts): array {
	$rows = array();

	foreach ($inserts as $statement) {
		foreach ($statement as $row) {
			$rows[] = $row;
		}
	}

	return $rows;
}

/**
 * Compare two row lists without printing them when they differ.
 */
function same_rows_db(string $label, array $expected, array $actual): void {
	$same = $expected === $actual;
	$at   = 0;

	while (! $same && isset($expected[ $at ] ) && $expected[ $at ] === ( $actual[ $at ] ?? null )) {
		++$at;
	}

	if ($same) {
		$GLOBALS['db_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['db_fail']++;
		echo "FAIL  - {$label}\n";
		printf( "        expected %d rows, got %d; first difference at row %d\n", count( $expected ), count( $actual ), $at );
	}
}

/**
 * The message a dump fails with, or '' when it does not.
 */
function db_dump_error(FakeDriver $driver): string {
	try {
		(new Dumper($driver, 'wp_', 'SERVMASK_PREFIX_'))->dump(static function (): void {}, array());
	} catch (\RuntimeException $e) {
		return $e->getMessage();
	}

	return '';
}

$paged = new FakeDriver();

// 5,000 rows stored out of order, every 700th one too large to share a page with many others.
$big = array();

for ($i = 1; $i <= 5000; $i++) {
	$big[] = array( 'ID' => $i, 'body' => $i % 700 === 0 ? str_repeat('w', 300000) : str_repeat('x', 40) );
}

shuffle($big);

$slugs = array( "o'brien", 'back\\slash', 'sp ace', 'ünïcødé', '0', '1e3', '-1', '(paren)', 'AND', "it''s", "two\nlines", '0x1A', ' 1', '1 ', 'a' );
$pairs = array();

foreach ( array( 2, 10, 1 ) as $blog ) {
	foreach ($slugs as $slug) {
		$pairs[] = array( 'blog_id' => (string) $blog, 'slug' => $slug, 'title' => 'row ' . $blog . ' ' . $slug );
	}
}

shuffle($pairs);

$amounts = array( '0.00', '-3.25', '1.50', '10.00', '1000.00', '0.05' );
$prices  = array();

foreach ($amounts as $amount) {
	$prices[] = array( 'amount' => $amount, 'label' => 'costs ' . $amount );
}

// Values that look like anything but text; as a text key each must compare as text.
$tokens = array( '1', '01', '1e3', '0x1A', ' 1', '1 ', '+1', '-0', '1.0', 'a', 'A', '' );
$notes  = array();

foreach ($tokens as $token) {
	$notes[] = array( 'token' => $token, 'note' => 'token ' . strlen($token) );
}

$paged->tables = array(
	'wp_big'   => array( 'columns' => array( 'ID', 'body' ), 'rows' => $big, 'key' => array( 'ID' ), 'numeric' => array( true ) ),
	'wp_pair'  => array(
		'columns' => array( 'blog_id', 'slug', 'title' ),
		'rows'    => $pairs,
		'key'     => array( 'blog_id', 'slug' ),
		'numeric' => array( true, false ),
	),
	'wp_price' => array( 'columns' => array( 'amount', 'label' ), 'rows' => $prices, 'key' => array( 'amount' ), 'numeric' => array( true ) ),
	'wp_token' => array( 'columns' => array( 'token', 'note' ), 'rows' => $notes, 'key' => array( 'token' ), 'numeric' => array( false ) ),
);

$paged_output = '';
$collect      = static function (string $chunk) use (&$paged_output): void {
	$paged_output .= $chunk;
};

(new Dumper($paged, 'wp_', 'SERVMASK_PREFIX_'))->dump($collect, array());

$big_inserts = db_inserts($paged_output, 'SERVMASK_PREFIX_big');

same_rows_db('single-column key: every row exactly once, in key order', range(1, 5000), array_map('intval', array_column(db_rows($big_inserts), 0)));

$sizes = array_map('count', $big_inserts);

check_db('single-column key: no page exceeds the row cap', max($sizes) <= 1000);

$after_wide = true;

foreach ($big_inserts as $index => $rows) {
	foreach ($rows as $row) {
		if (strlen($row[1]) === 300000) {
			$after_wide = $after_wide && isset($big_inserts[ $index + 1 ] ) && count($big_inserts[ $index + 1 ] ) <= 13;
		}
	}
}

check_db('single-column key: a wide row keeps the next page small', $after_wide);
check_db('single-column key: a wide row was dumped', strpos($paged_output, str_repeat('w', 300000)) !== false);

$big_queries = count(preg_grep('/^SELECT \* FROM `wp_big`/', $paged->statements));
check_db('single-column key: 5,000 rows take a few dozen pages', $big_queries >= 15 && $big_queries <= 30);

$by_pair = static function (array $a, array $b): int {
	$blog = (int) $a['blog_id'] <=> (int) $b['blog_id'];

	return $blog !== 0 ? $blog : strcmp($a['slug'], $b['slug']);
};

usort($pairs, $by_pair);
same_rows_db('composite key: every row exactly once, in key order', array_map('array_values', $pairs), db_rows(db_inserts($paged_output, 'SERVMASK_PREFIX_pair')));

usort($prices, static function (array $a, array $b): int { return (float) $a['amount'] <=> (float) $b['amount']; });
same_rows_db('fractional numeric key: every row exactly once, in key order', array_map('array_values', $prices), db_rows(db_inserts($paged_output, 'SERVMASK_PREFIX_price')));

usort($notes, static function (array $a, array $b): int { return strcmp($a['token'], $b['token']); });
same_rows_db('text key: every row exactly once, in key order', array_map('array_values', $notes), db_rows(db_inserts($paged_output, 'SERVMASK_PREFIX_token')));

// One page per request yields the same SQL as one pass.
$keyed         = '';
$keyed_cursor  = array();
$keyed_guard   = 0;
$keyed_collect = static function (string $chunk) use (&$keyed): void {
	$keyed .= $chunk;
};

do {
	$keyed_db         = new FakeDriver();
	$keyed_db->tables = $paged->tables;

	$keyed_done = (new Dumper($keyed_db, 'wp_', 'SERVMASK_PREFIX_'))->dump_chunk($keyed_cursor, $keyed_collect, array(), microtime(true));
} while (! $keyed_done && ++$keyed_guard < 500);

check_db('keyed dump sliced across requests finishes', $keyed_done);
check_db('keyed dump sliced across requests needed many requests', $keyed_guard > 10);
same_db('keyed dump sliced across requests matches one pass', $paged_output, $keyed);

// A key whose condition never advances ends the table instead of looping forever.
$loop        = new FakeDriver();
$loop_chunks = 0;
$loop->tables = array(
	'wp_loop' => array(
		'columns' => array( 'ID', 'body' ),
		'rows'    => array(
			array( 'ID' => 1, 'body' => 'one' ),
			array( 'ID' => 2, 'body' => 'two' ),
			array( 'ID' => 3, 'body' => 'three' ),
			array( 'ID' => 4, 'body' => 'four' ),
			array( 'ID' => 5, 'body' => 'five' ),
		),
		'key'     => array( 'ID' ),
		'stuck'   => true,
	),
);

(new Dumper($loop, 'wp_', 'SERVMASK_PREFIX_'))->dump(
	static function () use (&$loop_chunks): void {
		if (++$loop_chunks > 10) {
			throw new \RuntimeException('paging never ended');
		}
	},
	array()
);

check_db('a key that never advances ends the table', $loop_chunks <= 10);

// ---------------------------------------------------------------------------
echo "\n[6c] A database error never passes as an empty table\n";

$broken = new FakeDriver();
$broken->tables        = $paged->tables;
$broken->fail_contains = 'SELECT * FROM `wp_big`';

same_db('a failed row query fails the dump', 'Unable to read the rows of table `wp_big` — synthetic failure', db_dump_error($broken));

$broken->fail_contains = null;
$broken->fail_listing  = true;
same_db('a failed table listing fails the dump', 'Unable to list the database tables — synthetic failure', db_dump_error($broken));

$broken->fail_listing  = false;
$broken->fail_structure = array( 'wp_big' );
same_db('a failed structure lookup fails the dump', 'Unable to read the structure of table `wp_big` — synthetic failure', db_dump_error($broken));

// The request that failed saved nothing for that page, so the next one reads it again.
$broken->fail_structure = array();
$broken->fail_contains  = 'SELECT * FROM `wp_big`';

$resumed       = '';
$resumed_cursor = array();
$resumed_threw  = false;

try {
	(new Dumper($broken, 'wp_', 'SERVMASK_PREFIX_'))->dump_chunk(
		$resumed_cursor,
		static function (string $chunk) use (&$resumed): void {
			$resumed .= $chunk;
		},
		array()
	);
} catch (\RuntimeException $e) {
	$resumed_threw = true;
}

check_db('a failed page reaches the job as an error', $resumed_threw);

$resumed_done = (new Dumper($paged, 'wp_', 'SERVMASK_PREFIX_'))->dump_chunk(
	$resumed_cursor,
	static function (string $chunk) use (&$resumed): void {
		$resumed .= $chunk;
	},
	array()
);

check_db('the dump continues from the failed page', $resumed_done);
same_db('resumed dump matches one pass', $paged_output, $resumed);

// Export find/replace pairs rewrite row values while dumping.
$replace_db        = new FakeDriver();
$replace_db->tables = array(
	'wp_options' => array(
		'columns' => array( 'option_id', 'option_name', 'option_value' ),
		'rows'    => array(
			array( 'option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'http://old-domain.test' ),
			array(
				'option_id'    => 2,
				'option_name'  => 'serialized',
				'option_value' => 'a:2:{s:8:"site_ref";s:19:"www.old-domain.test";i:1;s:5:"plain";}',
			),
		),
	),
);

$replaced = '';
$replacer = new Dumper(
	$replace_db,
	'wp_',
	'SERVMASK_PREFIX_',
	new ReplaceEngine(array( 'old-domain.test', 'www.old-domain.test' ), array( 'new-domain.test', 'new-domain.test' ))
);
$replacer->dump(
	static function (string $chunk) use (&$replaced): void {
		$replaced .= $chunk;
	},
	array()
);
check_db('export replace applies to plain values', strpos($replaced, 'http://new-domain.test') !== false);
check_db('export replace leaves no old host', strpos($replaced, 'old-domain.test') === false);
check_db('export replace recalculates serialized lengths', strpos($replaced, 's:19:') === false && strpos($replaced, 's:15:') !== false);

// ---------------------------------------------------------------------------
echo "\n[7] Importer\n";
$import_db = new FakeDriver();
$import_engine = new ReplaceEngine(array($old), array($new));
$importer = new Importer($import_db, 'wp_', 'SERVMASK_PREFIX_', $import_engine);
$importer->import_sql(
	"CREATE TABLE `SERVMASK_PREFIX_posts` (`id` int);\n"
	. "INSERT INTO `SERVMASK_PREFIX_options` (`option_value`) VALUES ('" . $import_db->escape($old . '/x') . "');"
);
same_db('two statements executed', 2, count($import_db->statements));
check_db('placeholder mapped to real prefix in CREATE', strpos($import_db->statements[0], 'CREATE TABLE `wp_posts`') !== false);
check_db('literal replacement applied in INSERT', strpos($import_db->statements[1], $new . '/x') !== false);

// CREATE TABLE failure retried with normalization.
$retry_db = new FakeDriver();
$retry_db->fail_contains = 'ENGINE=Aria';
$retry = new Importer($retry_db, 'wp_', 'SERVMASK_PREFIX_');
$retry->import_statement('CREATE TABLE `SERVMASK_PREFIX_x` (`id` int) ENGINE=Aria DEFAULT CHARSET=utf8mb4');
same_db('statement attempted twice', 2, count($retry_db->statements));
check_db('retry uses normalized engine', strpos($retry_db->statements[1], 'ENGINE=InnoDB') !== false);

// Prefix mapping touches identifiers only, never literal data.
$mapped_db = new FakeDriver();
$mapped = new Importer($mapped_db, 'wp_', 'SERVMASK_PREFIX_');
$mapped->import_statement(
	"INSERT INTO `SERVMASK_PREFIX_options` (`option_value`) VALUES ('SERVMASK_PREFIX_literal');"
);
check_db('identifier prefix mapped', strpos($mapped_db->statements[0], '`wp_options`') !== false);
check_db('literal placeholder preserved', strpos($mapped_db->statements[0], "'SERVMASK_PREFIX_literal'") !== false);

// Hard failure surfaces an exception.
$fail_db = new FakeDriver();
$fail_db->fail_contains = 'INSERT';
$failing = new Importer($fail_db, 'wp_', 'SERVMASK_PREFIX_');
$threw = false;
try {
	$failing->import_statement("INSERT INTO `x` VALUES (1)");
} catch (\RuntimeException $e) {
	$threw = true;
}
check_db('unrecoverable failure throws', $threw);

// ---------------------------------------------------------------------------
echo "\n";
echo "passed: {$GLOBALS['db_pass']}, failed: {$GLOBALS['db_fail']}\n";
exit($GLOBALS['db_fail'] === 0 ? 0 : 1);
