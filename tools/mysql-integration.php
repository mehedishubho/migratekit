<?php
/**
 * Real MySQL/MariaDB integration test for the dumper and importer.
 *
 * Creates a source database, dumps it with MigrateKit, imports the dump into a
 * clean destination database under a different table prefix and URL, then
 * verifies every value round-tripped.
 *
 * Usage:
 *   php tools/mysql-integration.php <host> <user> <password>
 *
 * @package MigrateKit
 */

declare(strict_types=1);

require __DIR__ . '/../tests/bootstrap.php';

use MigrateKit\Database\Dumper;
use MigrateKit\Database\Driver\MysqliDriver;
use MigrateKit\Database\Importer;
use MigrateKit\Database\ReplaceEngine;

$host = isset($argv[1]) ? $argv[1] : '127.0.0.1';
$user = isset($argv[2]) ? $argv[2] : 'root';
$pass = isset($argv[3]) ? $argv[3] : 'root';

$GLOBALS['my_pass'] = 0;
$GLOBALS['my_fail'] = 0;

function check_my(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['my_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['my_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function same_my(string $label, $expected, $actual): void {
	if ($expected === $actual) {
		$GLOBALS['my_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['my_fail']++;
		echo "FAIL  - {$label}\n";
		echo '        expected: ' . var_export($expected, true) . "\n";
		echo '        actual:   ' . var_export($actual, true) . "\n";
	}
}

function connect_retry(string $host, string $user, string $pass, ?string $db = null): mysqli {
	$last = '';

	for ($attempt = 0; $attempt < 60; $attempt++) {
		$link = @new mysqli($host, $user, $pass, $db);

		if (! $link->connect_errno) {
			$link->set_charset('utf8mb4');
			return $link;
		}

		$last = $link->connect_error;
		sleep(1);
	}

	throw new RuntimeException('Database connection failed: ' . $last);
}

function wpdb_wrap(mysqli $link, string $db, string $host): object {
	$wpdb         = new stdClass();
	$wpdb->dbh    = $link;
	$wpdb->dbname = $db;
	$wpdb->dbhost = $host;

	return $wpdb;
}

function q(mysqli $link, string $sql) {
	$result = $link->query($sql);

	if ($result === false) {
		throw new RuntimeException('Query failed: ' . $link->error . ' in ' . $sql);
	}

	return $result;
}

function scalar(mysqli $link, string $sql) {
	$result = q($link, $sql);
	$row    = $result->fetch_row();
	$result->free();

	return $row === null ? null : $row[0];
}

function exec_insert(mysqli $link, string $sql, array $values): void {
	$statement = $link->prepare($sql);

	if ($statement === false) {
		throw new RuntimeException('Prepare failed: ' . $link->error);
	}

	$types = str_repeat('s', count($values));
	$statement->bind_param($types, ...$values);

	if (! $statement->execute()) {
		throw new RuntimeException('Insert failed: ' . $statement->error);
	}

	$statement->close();
}

$admin = connect_retry($host, $user, $pass);
$admin->query('DROP DATABASE IF EXISTS src');
$admin->query('DROP DATABASE IF EXISTS dst');
$admin->query('CREATE DATABASE src CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$admin->query('CREATE DATABASE dst CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$admin->close();

$src = connect_retry($host, $user, $pass, 'src');

$src->query(
	'CREATE TABLE wp_options (
		option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		option_name VARCHAR(191) NOT NULL DEFAULT "",
		option_value LONGTEXT NOT NULL,
		autoload VARCHAR(20) NOT NULL DEFAULT "yes",
		PRIMARY KEY (option_id),
		UNIQUE KEY option_name (option_name)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$src->query(
	'CREATE TABLE wp_posts (
		ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		post_type VARCHAR(20) NOT NULL DEFAULT "post",
		post_title TEXT NOT NULL,
		PRIMARY KEY (ID)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$src->query(
	'CREATE TABLE wp_comments (
		comment_ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		comment_approved VARCHAR(20) NOT NULL DEFAULT "1",
		comment_content TEXT NOT NULL,
		PRIMARY KEY (comment_ID)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$src->query(
	'CREATE TABLE wp_misc (
		id INT NOT NULL,
		nullable_value VARCHAR(100) NULL,
		payload LONGBLOB NULL,
		PRIMARY KEY (id)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$src->query(
	'CREATE TABLE custom_table (
		id INT NOT NULL,
		note VARCHAR(50) NOT NULL,
		PRIMARY KEY (id)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$serialized = serialize(
	array(
		'url'    => 'http://old.test/path',
		'home'   => 'http://old.test/home',
		'nested' => array( 'n' => 5 ),
	)
);
$quoted    = "It's a \"quoted\" path C:\\temp\\x";
$whitespace = "line1\nline2\r\nline3\ttab";
$placeholder_literal = 'SERVMASK_PREFIX_should_stay';

$rows = array(
	array( 'siteurl', 'http://old.test', 'yes' ),
	array( 'home', 'http://old.test', 'yes' ),
	array( 'serialized', $serialized, 'no' ),
	array( 'plain', 'Visit http://old.test/page', 'no' ),
	array( 'quoted', $quoted, 'no' ),
	array( 'whitespace', $whitespace, 'no' ),
	array( 'literal_placeholder', $placeholder_literal, 'no' ),
);

foreach ($rows as $row) {
	exec_insert(
		$src,
		'INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, ?)',
		$row
	);
}

exec_insert($src, 'INSERT INTO wp_posts (post_type, post_title) VALUES (?, ?)', array( 'post', 'Hello world' ));
exec_insert($src, 'INSERT INTO wp_posts (post_type, post_title) VALUES (?, ?)', array( 'revision', 'Revision of Hello' ));
exec_insert($src, 'INSERT INTO wp_posts (post_type, post_title) VALUES (?, ?)', array( 'post', "Title with 'quote'" ));

exec_insert($src, 'INSERT INTO wp_comments (comment_approved, comment_content) VALUES (?, ?)', array( '1', 'Approved comment' ));
exec_insert($src, 'INSERT INTO wp_comments (comment_approved, comment_content) VALUES (?, ?)', array( 'spam', 'Spam comment' ));

// Binary payload with the bytes escaping is most likely to get wrong.
$binary = random_bytes(4096) . "\x00\x1a\xff\x80'\"" . '\\';
$statement = $src->prepare('INSERT INTO wp_misc (id, nullable_value, payload) VALUES (1, NULL, ?)');
$null      = null;
$statement->bind_param('s', $binary);
$statement->execute();
$statement->close();

exec_insert($src, 'INSERT INTO wp_misc (id, nullable_value, payload) VALUES (?, ?, ?)', array( '2', 'has value', 'plain bytes' ));
exec_insert($src, 'INSERT INTO custom_table (id, note) VALUES (?, ?)', array( '1', 'custom row' ));

$src->close();

echo "MigrateKit — MySQL/MariaDB integration\n\n";

// ---------------------------------------------------------------------------
echo "[1] Dump source database\n";
$src = connect_retry($host, $user, $pass, 'src');
$source_driver = new MysqliDriver(wpdb_wrap($src, 'src', $host));

$dump_file = tempnam(sys_get_temp_dir(), 'mkdb') . '.sql';
$handle    = fopen($dump_file, 'wb');

$dumper = new Dumper($source_driver, 'wp_', 'SERVMASK_PREFIX_');
$dumper->dump(
	static function (string $chunk) use ($handle): void {
		fwrite($handle, $chunk);
	},
	array(
		'no_post_revisions' => true,
		'no_spam_comments'  => true,
	)
);
fclose($handle);
$src->close();

$dump = (string) file_get_contents($dump_file);
check_my('dump file written', strlen($dump) > 0);
check_my('tables rewritten to placeholder', strpos($dump, 'CREATE TABLE `SERVMASK_PREFIX_options`') !== false);
check_my('unprefixed table kept', strpos($dump, 'CREATE TABLE `custom_table`') !== false);
check_my('revision row excluded from dump', strpos($dump, 'Revision of Hello') === false);
check_my('spam row excluded from dump', strpos($dump, 'Spam comment') === false);

// ---------------------------------------------------------------------------
echo "\n[2] Import into destination under a new prefix\n";
$dst = connect_retry($host, $user, $pass, 'dst');
$dst->query("SET SESSION sql_mode=''");

$destination_driver = new MysqliDriver(wpdb_wrap($dst, 'dst', $host));
$engine   = new ReplaceEngine(array( 'http://old.test' ), array( 'https://new.example' ));
$importer = new Importer($destination_driver, 'new_', 'SERVMASK_PREFIX_', $engine);
$importer->import_file($dump_file);

$tables = array();
$result = q($dst, 'SHOW TABLES');

while (($row = $result->fetch_row()) !== null) {
	$tables[] = $row[0];
}

$result->free();
sort($tables);

same_my('destination table count', 5, count($tables));
check_my('prefixed tables renamed', in_array('new_options', $tables, true));
check_my('unprefixed table preserved', in_array('custom_table', $tables, true));
check_my('old prefix absent', ! in_array('wp_options', $tables, true));

same_my('siteurl replaced', 'https://new.example', (string) scalar($dst, "SELECT option_value FROM new_options WHERE option_name='siteurl'"));
same_my('plain URL replaced', 'Visit https://new.example/page', (string) scalar($dst, "SELECT option_value FROM new_options WHERE option_name='plain'"));
same_my('quoted value preserved', $quoted, (string) scalar($dst, "SELECT option_value FROM new_options WHERE option_name='quoted'"));
same_my('whitespace value preserved', $whitespace, (string) scalar($dst, "SELECT option_value FROM new_options WHERE option_name='whitespace'"));
same_my('placeholder literal preserved', $placeholder_literal, (string) scalar($dst, "SELECT option_value FROM new_options WHERE option_name='literal_placeholder'"));

$imported_serialized = (string) scalar($dst, "SELECT option_value FROM new_options WHERE option_name='serialized'");
$decoded = @unserialize($imported_serialized);
check_my('serialized value is valid', is_array($decoded));
same_my('serialized URL replaced', 'https://new.example/path', is_array($decoded) ? $decoded['url'] : null);
same_my('serialized home replaced', 'https://new.example/home', is_array($decoded) ? $decoded['home'] : null);
same_my('serialized nested value preserved', 5, is_array($decoded) ? $decoded['nested']['n'] : null);

same_my('plain post count (revision excluded)', 2, (int) scalar($dst, "SELECT COUNT(*) FROM new_posts WHERE post_type='post'"));
same_my('revision rows absent', 0, (int) scalar($dst, "SELECT COUNT(*) FROM new_posts WHERE post_type='revision'"));
same_my('spam comments excluded', 0, (int) scalar($dst, "SELECT COUNT(*) FROM new_comments WHERE comment_approved='spam'"));
same_my('approved comment present', 'Approved comment', (string) scalar($dst, 'SELECT comment_content FROM new_comments LIMIT 1'));
same_my('unprefixed table row preserved', 'custom row', (string) scalar($dst, 'SELECT note FROM custom_table LIMIT 1'));

check_my('NULL preserved', null === scalar($dst, 'SELECT nullable_value FROM new_misc WHERE id=1'));
same_my('text value preserved', 'has value', (string) scalar($dst, 'SELECT nullable_value FROM new_misc WHERE id=2'));
same_my('plain payload preserved', 'plain bytes', (string) scalar($dst, 'SELECT payload FROM new_misc WHERE id=2'));

$imported_binary = (string) scalar($dst, 'SELECT payload FROM new_misc WHERE id=1');
same_my('binary payload length', strlen($binary), strlen($imported_binary));
same_my('binary payload hash', hash('sha256', $binary), hash('sha256', $imported_binary));

// Table structure survived.
$result = q($dst, 'SHOW CREATE TABLE new_options');
$row    = $result->fetch_row();
$result->free();
check_my('create table preserved', isset($row[1]) && strpos($row[1], 'ENGINE=InnoDB') !== false);

$dst->close();

// ---------------------------------------------------------------------------
echo "\n[3] Row paging by key\n";

/**
 * Count and digest of every row of a table, independent of row order.
 */
function table_fingerprint(mysqli $link, string $table, string $where = ''): string {
	$result  = q($link, 'SELECT * FROM `' . $table . '`' . ( $where !== '' ? ' WHERE ' . $where : '' ));
	$digests = array();

	while ( ( $row = $result->fetch_assoc() ) !== null ) {
		$digests[] = md5( serialize( $row ) );
	}

	$result->free();
	sort( $digests );

	return count( $digests ) . ':' . md5( implode( '', $digests ) );
}

$src = connect_retry( $host, $user, $pass, 'src' );
$src->set_charset( 'utf8mb4' );

$result = q( $src, 'SHOW TABLES' );

while ( ( $row = $result->fetch_row() ) !== null ) {
	$src->query( 'DROP TABLE `' . $row[0] . '`' );
}

$result->free();

$src->query(
	'CREATE TABLE wp_posts (
		ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		post_type VARCHAR(20) NOT NULL,
		body LONGTEXT NOT NULL,
		PRIMARY KEY (ID)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$src->query(
	'CREATE TABLE wp_pair (
		blog_id INT UNSIGNED NOT NULL,
		slug VARCHAR(191) NOT NULL,
		title TEXT NOT NULL,
		PRIMARY KEY (blog_id, slug)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$src->query(
	'CREATE TABLE wp_token (
		token VARCHAR(191) NOT NULL,
		note VARCHAR(100) NOT NULL,
		PRIMARY KEY (token)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'
);
$src->query(
	'CREATE TABLE wp_price (
		amount DECIMAL(12,2) NOT NULL,
		label VARCHAR(50) NOT NULL,
		PRIMARY KEY (amount)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$src->query(
	'CREATE TABLE wp_nokey (
		n INT NOT NULL,
		label VARCHAR(50) NOT NULL,
		KEY k (n)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$src->query(
	'CREATE TABLE wp_bits (
		hash VARBINARY(16) NOT NULL,
		label VARCHAR(50) NOT NULL,
		PRIMARY KEY (hash)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

// 20,000 rows, every seventh one a revision the dump must skip and every 500th one far wider than the rest.
$wide = str_repeat( 'lorem ipsum dolor sit amet ', 6000 );

for ( $first = 1; $first <= 20000; $first += 200 ) {
	$tuples = array();

	for ( $n = $first; $n < $first + 200; $n++ ) {
		$tuples[] = "( '" . ( $n % 7 === 0 ? 'revision' : 'post' ) . "', '" . ( $n % 500 === 0 ? $wide : 'row ' . $n ) . "' )";
	}

	q( $src, 'INSERT INTO wp_posts (post_type, body) VALUES ' . implode( ',', $tuples ) );
}

$slugs   = array( "o'brien", 'back\\slash', 'sp ace', 'ünïcødé', '0', '1e3', '-1', '(paren)', 'AND', "it''s", "two\nlines", '0x1A', ' 1', '1 ', 'quote"mark' );
$pair    = $src->prepare( 'INSERT INTO wp_pair (blog_id, slug, title) VALUES (?, ?, ?)' );
$blog_id = 0;
$title   = '';

foreach ( array( 2, 10, 1 ) as $blog ) {
	foreach ( $slugs as $slug ) {
		$blog_id = $blog;
		$title   = 'row ' . $blog . ' ' . $slug;
		$pair->bind_param( 'iss', $blog_id, $slug, $title );
		$pair->execute();
	}
}

$pair->close();

$tokens = array( '', ' 1', '+1', '-0', '0x1A', '01', '1', '1.0', '1e3', 'A', 'a', "tab\there", "new\nline" );
$token  = $src->prepare( 'INSERT INTO wp_token (token, note) VALUES (?, ?)' );
$note   = '';

foreach ( $tokens as $value ) {
	$note = 'length ' . strlen( $value );
	$token->bind_param( 'ss', $value, $note );
	$token->execute();
}

$token->close();

foreach ( array( '-3.25', '0.00', '0.05', '1.50', '10.00', '99999999.99' ) as $amount ) {
	exec_insert( $src, 'INSERT INTO wp_price (amount, label) VALUES (?, ?)', array( $amount, 'costs ' . $amount ) );
}

for ( $n = 1; $n <= 3000; $n++ ) {
	exec_insert( $src, 'INSERT INTO wp_nokey (n, label) VALUES (?, ?)', array( (string) ( $n % 40 ), 'no key ' . $n ) );
}

$bit = $src->prepare( 'INSERT INTO wp_bits (hash, label) VALUES (?, ?)' );

for ( $n = 0; $n < 300; $n++ ) {
	$hash  = $n < 5 ? str_repeat( chr( array( 0, 39, 92, 255, 126 )[ $n ] ), 16 ) : random_bytes( 16 );
	$label = 'bits ' . $n;
	$bit->bind_param( 'ss', $hash, $label );
	$bit->execute();
}

$bit->close();

$source_driver = new MysqliDriver( wpdb_wrap( $src, 'src', $host ) );
$write         = array();
$one           = tempnam( sys_get_temp_dir(), 'mkone' ) . '.sql';
$handle        = fopen( $one, 'wb' );
$started       = microtime( true );

( new Dumper( $source_driver, 'wp_', 'SERVMASK_PREFIX_' ) )->dump(
	static function ( string $chunk ) use ( $handle ): void {
		fwrite( $handle, $chunk );
	},
	array( 'no_post_revisions' => true )
);

fclose( $handle );

printf( "  one pass: %.2f s, %.1f MB of SQL, peak memory %d MB\n", microtime( true ) - $started, filesize( $one ) / 1048576, memory_get_peak_usage( true ) / 1048576 );

// One page per request, as a site with a short request limit would dump it.
$sliced  = tempnam( sys_get_temp_dir(), 'mksliced' ) . '.sql';
$handle  = fopen( $sliced, 'wb' );
$cursor  = array();
$calls   = 0;
$started = microtime( true );

while ( ! ( new Dumper( $source_driver, 'wp_', 'SERVMASK_PREFIX_' ) )->dump_chunk(
	$cursor,
	static function ( string $chunk ) use ( $handle ): void {
		fwrite( $handle, $chunk );
	},
	array( 'no_post_revisions' => true ),
	microtime( true )
) ) {
	if ( ++$calls > 2000 ) {
		throw new RuntimeException( 'The sliced dump did not finish: paging is not advancing.' );
	}
}

fclose( $handle );

printf( "  page per request: %d requests, %.2f s, peak memory %d MB\n", $calls + 1, microtime( true ) - $started, memory_get_peak_usage( true ) / 1048576 );

same_my( 'dump sliced one page per request is byte-identical to one pass', hash_file( 'sha256', $one ), hash_file( 'sha256', $sliced ) );
check_my( 'large table took pages of many rows, not one row each', $calls < 300 );
check_my( 'wide rows did not pile up in memory', memory_get_peak_usage( true ) < 100 * 1048576 );

$dumped     = (string) file_get_contents( $one );
$dumped_rows = 0;

preg_match_all( '/^INSERT INTO `SERVMASK_PREFIX_posts` .*$/m', $dumped, $statements );

foreach ( $statements[0] as $statement ) {
	$dumped_rows += substr_count( $statement, "),('" ) + 1;
}

same_my( 'large table: every post once, revisions skipped', 20000 - intdiv( 20000, 7 ), $dumped_rows );
check_my( 'large table: the wide rows are in the dump', substr_count( $dumped, $wide ) === 40 - intdiv( 20000, 3500 ) );

$admin = connect_retry( $host, $user, $pass );
$admin->query( 'DROP DATABASE IF EXISTS dst' );
$admin->query( 'CREATE DATABASE dst CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
$admin->close();

$dst = connect_retry( $host, $user, $pass, 'dst' );
$dst->query( "SET SESSION sql_mode=''" );
( new Importer( new MysqliDriver( wpdb_wrap( $dst, 'dst', $host ) ), 'new_', 'SERVMASK_PREFIX_' ) )->import_file( $one );

// The source side applies what the dump skipped, so both sides hold the same rows.
$skipped = array( 'posts' => "`post_type` <> 'revision'" );

foreach ( array( 'posts', 'pair', 'token', 'price', 'nokey', 'bits' ) as $name ) {
	$filter = $skipped[ $name ] ?? '';

	same_my(
		"wp_{$name}: every row survives the dump and the import exactly",
		table_fingerprint( $src, 'wp_' . $name, $filter ),
		table_fingerprint( $dst, 'new_' . $name )
	);
}

$dst->close();
$src->close();

unlink( $one );
unlink( $sliced );

// ---------------------------------------------------------------------------
echo "\n";
echo "passed: {$GLOBALS['my_pass']}, failed: {$GLOBALS['my_fail']}\n";
exit($GLOBALS['my_fail'] === 0 ? 0 : 1);
