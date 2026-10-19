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
 */
final class FakeDriver implements DriverInterface {
	/** @var array<string,array{columns:string[],rows:array<int,array<string,mixed>>}> */
	public $tables = array();

	/** @var string[] */
	public $statements = array();

	/** @var string|null */
	public $fail_contains = null;

	/** @var int */
	private $last_errno = 0;

	/** @var string */
	private $last_error = '';

	public function query(string $sql) {
		$this->statements[] = $sql;

		if ($this->fail_contains !== null && strpos($sql, $this->fail_contains) !== false) {
			$this->last_errno = 1064;
			$this->last_error = 'synthetic failure';
			return false;
		}

		$this->last_errno = 0;
		$this->last_error = '';

		if (preg_match('/^SELECT \* FROM `([^`]+)`(.*)$/iu', trim($sql), $match) === 1) {
			$table = $match[1];
			$where = $match[2];
			$rows  = isset($this->tables[ $table ]['rows']) ? $this->tables[ $table ]['rows'] : array();

			if (stripos($where, 'post_type') !== false) {
				$rows = array_values(
					array_filter(
						$rows,
						static function (array $row): bool {
							return ! isset($row['post_type']) || $row['post_type'] !== 'revision';
						}
					)
				);
			}

			if (stripos($where, 'comment_approved') !== false) {
				$rows = array_values(
					array_filter(
						$rows,
						static function (array $row): bool {
							return ! isset($row['comment_approved']) || $row['comment_approved'] !== 'spam';
						}
					)
				);
			}

			return new FakeResult($rows);
		}

		return true;
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
		return array_keys($this->tables);
	}

	public function show_create_table(string $table): ?string {
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
