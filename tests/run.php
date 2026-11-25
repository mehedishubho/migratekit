<?php
/**
 * Dependency-free smoke suite for the .wpress archive engine.
 *
 * Runs anywhere PHP runs (no Composer/PHPUnit needed), so it can be executed
 * inside a bare php:cli container. Maps one-to-one onto the PHPUnit unit tests
 * added in Phase 0 completion.
 *
 * Usage: php tests/run.php
 *
 * @package MigrateKit
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MigrateKit\Archive\Crc32;
use MigrateKit\Archive\Encryptor;
use MigrateKit\Archive\Header;
use MigrateKit\Archive\Reader;
use MigrateKit\Archive\Writer;

$GLOBALS['mk_pass'] = 0;
$GLOBALS['mk_fail'] = 0;
$GLOBALS['mk_tmp']  = sys_get_temp_dir() . '/migratekit-tests-' . bin2hex(random_bytes(4));

if (! is_dir($GLOBALS['mk_tmp'])) {
	mkdir($GLOBALS['mk_tmp'], 0777, true);
}

function check(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['mk_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['mk_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function same(string $label, $expected, $actual): void {
	if ($expected === $actual) {
		$GLOBALS['mk_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['mk_fail']++;
		echo "FAIL  - {$label}\n";
		echo '        expected: ' . var_export($expected, true) . "\n";
		echo '        actual:   ' . var_export($actual, true) . "\n";
	}
}

function tmp_in(string $name): string {
	return $GLOBALS['mk_tmp'] . '/' . $name;
}

function temp_archive(): string {
	return tmp_in('arch-' . bin2hex(random_bytes(4)) . '.wpress');
}

/**
 * Write records, then read them back and assert every field round-trips.
 *
 * @param array<int,array{0:string,1:string}> $records [archivePath, contents] pairs.
 */
function roundtrip(string $label, ?string $compression, ?string $password, array $records): void {
	$path = temp_archive();
	$writer = new Writer($path, $compression, $password);

	foreach ($records as $record) {
		$writer->add_contents($record[0], $record[1], 1700000000);
	}

	$writer->close();

	$reader = new Reader($path, $compression, $password);

	check($label . ': archive is valid', $reader->is_valid());
	check($label . ': archive CRC verifies', $reader->verify_archive_crc());

	$entries = $reader->entries();
	same($label . ': record count', count($records), count($entries));

	foreach ($entries as $index => $entry) {
		same($label . ': content #' . $index, $records[$index][1], $reader->read($entry));
		same($label . ': crc #' . $index, Crc32::hex($records[$index][1]), $entry->crc());
		same($label . ': mtime #' . $index, 1700000000, $entry->mtime());
	}
}

echo "MigrateKit — archive engine smoke suite\n";
echo "temp: {$GLOBALS['mk_tmp']}\n\n";

// ---------------------------------------------------------------------------
echo "[1] Header round-trip\n";
$block = Header::pack_record('a.jpg', '12345', '1700000000', 'uploads/2024', 'abcdef01');
same('header length', 4377, strlen($block));
$parsed = Header::parse($block);
same('name', 'a.jpg', $parsed['name']);
same('size', '12345', $parsed['size']);
same('mtime', '1700000000', $parsed['mtime']);
same('path', 'uploads/2024', $parsed['path']);
same('crc', 'abcdef01', $parsed['crc']);
check('record block is not an EOF sentinel', ! Header::is_eof($block));

$eof = Header::pack_eof('8789', 'deadbeef');
same('eof length', 4377, strlen($eof));
check('eof v2 detected', Header::is_eof_v2($eof));
check('eof v1 not detected for v2 block', ! Header::is_eof_v1($eof));
check('corrupt eof rejected', ! Header::is_eof_v2('x' . substr($eof, 1)));
check('all-NUL eof is v1', Header::is_eof_v1(str_repeat("\0", 4377)));

// ---------------------------------------------------------------------------
echo "\n[2] CRC32 vector\n";
same('crc32b("123456789") == cbf43926', 'cbf43926', Crc32::hex('123456789'));
same('crc32b("") == 00000000', '00000000', Crc32::hex(''));

// A checksum carried across requests: the CRCs of two parts combine into the CRC of the whole.
$combined_input = random_bytes(300000);

foreach (array( 0, 1, 1000, 299999, 300000 ) as $split) {
	same(
		'crc32 combine, split at ' . $split,
		Crc32::hex($combined_input),
		Crc32::combine(Crc32::hex(substr($combined_input, 0, $split)), Crc32::hex(substr($combined_input, $split)), strlen($combined_input) - $split)
	);
}

$two_parts = Crc32::combine(Crc32::hex(substr($combined_input, 0, 70000)), Crc32::hex(substr($combined_input, 70000, 130000)), 130000);

same('crc32 combine, three parts', Crc32::hex($combined_input), Crc32::combine($two_parts, Crc32::hex(substr($combined_input, 200000)), 100000));
same('crc32 combine, many tiny parts', Crc32::hex($combined_input), (static function (string $input): string {
	$crc = null;

	foreach ( str_split( $input, 700 ) as $piece ) {
		$crc = Crc32::combine( $crc, Crc32::hex( $piece ), strlen( $piece ) );
	}

	return (string) $crc;
})($combined_input));

// ---------------------------------------------------------------------------
echo "\n[3] Encryption primitive\n";
$encryptor = new Encryptor('correct horse battery staple');
$plaintext = random_bytes(1000);
$ciphertext = $encryptor->encrypt($plaintext);
same('decrypt(encrypt(x)) == x', $plaintext, $encryptor->decrypt($ciphertext));
check('ciphertext differs from plaintext', $ciphertext !== $plaintext);
$signature = $encryptor->sign('migratekit-signature');
check('signature verifies', $encryptor->verify($signature, 'migratekit-signature'));
check('wrong text rejected', ! $encryptor->verify($signature, 'nope'));
check('wrong password rejected', ! (new Encryptor('wrong'))->verify($signature, 'migratekit-signature'));

// ---------------------------------------------------------------------------
echo "\n[4] Plain archive round-trip\n";
$big = random_bytes(1200000); // crosses the 512000-byte chunk boundary
roundtrip(
	'plain',
	null,
	null,
	array(
		array('package.json', '{"Plugin":{"Version":"1.0.0"}}'),
		array('uploads/2024/logo.bin', $big),
		array('database.sql', "-- dump\nSELECT 1;\n"),
	)
);

// ---------------------------------------------------------------------------
echo "\n[5] Gzip archive round-trip\n";
roundtrip(
	'gzip',
	'gzip',
	null,
	array(
		array('package.json', '{"Plugin":{"Version":"1.0.0"}}'),
		array('uploads/2024/logo.bin', $big),
	)
);

// ---------------------------------------------------------------------------
echo "\n[6] Encrypted archive round-trip\n";
roundtrip(
	'encrypted',
	null,
	'hunter2',
	array(
		array('package.json', '{"Plugin":{"Version":"1.0.0"},"Encrypted":true}'),
		array('uploads/2024/logo.bin', $big),
	)
);

// ---------------------------------------------------------------------------
echo "\n[7] Gzip + encrypted archive round-trip\n";
roundtrip(
	'gzip+encrypted',
	'gzip',
	'hunter2',
	array(
		array('package.json', '{"Plugin":{"Version":"1.0.0"},"Encrypted":true}'),
		array('database.sql', "-- dump\nSELECT 1;\n" . $big),
	)
);

// ---------------------------------------------------------------------------
echo "\n[8] Bzip2 archive round-trip\n";
if (function_exists('bzcompress')) {
	roundtrip(
		'bzip2',
		'bzip2',
		null,
		array(
			array('package.json', '{"Plugin":{"Version":"1.0.0"}}'),
			array('database.sql', str_repeat('SELECT 1;', 5000)),
		)
	);
} else {
	echo "  --  - bz2 extension unavailable, skipping\n";
}

// ---------------------------------------------------------------------------
echo "\n[9] Config records stay raw under compression\n";
$path = temp_archive();
$writer = new Writer($path, 'gzip', null);
$package = '{"Plugin":{"Version":"1.0.0"}}';
$writer->add_contents('package.json', $package, 1700000000);
$writer->add_contents('database.sql', str_repeat('A', 250000), 1700000000);
$writer->close();
$reader = new Reader($path, 'gzip', null);
$entry = $reader->find('package.json');
check('package.json record found', $entry !== null);
same('package.json stored raw (size == plaintext length)', strlen($package), $entry->size());
same('package.json content readable', $package, $reader->read($entry));

// ---------------------------------------------------------------------------
echo "\n[10] Exact byte layout\n";
$path = temp_archive();
$writer = new Writer($path, null, null);
$writer->add_contents('package.json', 'AAAA', 1700000000);
$writer->add_contents('uploads/x.bin', 'HELLO', 1700000123);
$writer->close();

same('first record name', 'package.json', Header::parse((string) file_get_contents($path, false, null, 0, 4377))['name']);
same('total file size', 4377 + 4 + 4377 + 5 + 4377, filesize($path));
$reader = new Reader($path);
$entries = $reader->entries();
same('record 0 block offset', 0, $entries[0]->block_offset());
same('record 1 block offset', 4377 + 4, $entries[1]->block_offset());
same('record 0 size', 4, $entries[0]->size());
same('record 1 size', 5, $entries[1]->size());
same('record 1 relative path', 'uploads/x.bin', $entries[1]->relative_path());
same('eof sentinel offset field', (string) (4377 + 4 + 4377 + 5), Header::parse(substr((string) file_get_contents($path), -4377))['size']);
same('archive crc field', Crc32::of_file($path, 0, 4377 + 4 + 4377 + 5), (string) $reader->archive_crc());

// ---------------------------------------------------------------------------
echo "\n[11] Extraction to disk\n";
$path = temp_archive();
$payload = random_bytes(700000);
$writer = new Writer($path, 'gzip', null);
$writer->add_contents('package.json', '{}', 1700000000);
$writer->add_contents('uploads/big.bin', $payload, 1699999999);
$writer->close();
$reader = new Reader($path, 'gzip', null);
$dest = tmp_in('extracted.bin');
$reader->extract($reader->find('uploads/big.bin'), $dest);
same('extracted bytes match', hash('sha256', $payload), hash_file('sha256', $dest));
same('extracted mtime preserved', 1699999999, filemtime($dest));

// ---------------------------------------------------------------------------
echo "\n[12] Empty file record\n";
$path = temp_archive();
$writer = new Writer($path, null, null);
$writer->add_contents('empty.txt', '', 1700000000);
$writer->close();
$reader = new Reader($path);
$entry = $reader->find('empty.txt');
same('empty record size', 0, $entry->size());
same('empty record crc', '00000000', $entry->crc());
same('empty record content', '', $reader->read($entry));

// ---------------------------------------------------------------------------
echo "\n[13] Corruption and wrong password\n";
$path = temp_archive();
$writer = new Writer($path, null, 'secret');
$writer->add_contents('package.json', '{}', 1700000000);
$writer->add_contents('a.bin', random_bytes(100), 1700000000);
$writer->close();

// Truncate the final byte: sentinel no longer matches.
$bytes = (string) file_get_contents($path);
file_put_contents($path, substr($bytes, 0, -1));
check('truncated archive is invalid', ! (new Reader($path))->is_valid());

$path = temp_archive();
$writer = new Writer($path, null, 'secret');
$writer->add_contents('package.json', '{}', 1700000000);
$writer->add_contents('a.bin', random_bytes(100), 1700000000);
$writer->close();
$wrong = new Reader($path, null, 'not-secret');
$threw = false;
try {
	$wrong->read($wrong->find('a.bin'));
} catch (\RuntimeException $e) {
	$threw = true;
}
check('wrong password triggers decryption failure', $threw);

// ---------------------------------------------------------------------------
echo "\n[14] Field-length guards\n";
$writer = new Writer(temp_archive(), null, null);
$nameThrew = false;
try {
	$writer->add_contents(str_repeat('n', 300) . '.txt', 'x');
} catch (\InvalidArgumentException $e) {
	$nameThrew = true;
}
check('over-long name rejected', $nameThrew);
$writer->close();

// ---------------------------------------------------------------------------
echo "\n";
echo "passed: {$GLOBALS['mk_pass']}, failed: {$GLOBALS['mk_fail']}\n";

exit($GLOBALS['mk_fail'] === 0 ? 0 : 1);
