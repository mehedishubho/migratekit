<?php
/**
 * Developer interop check: prove the MigrateKit .wpress engine is byte-compatible
 * with the All-in-One WP Migration archive format by driving AI1WM's own
 * compressor/extractor against MigrateKit output for identical inputs.
 *
 * NOT part of the shipped plugin. Run from a Linux container, e.g.:
 *
 *   docker run --rm -v "<workspace>:/work" -w /work php:8.2-cli \
 *     php migratekit/../tools/interop-check.php /work
 *
 * @package MigrateKit
 */

declare(strict_types=1);

$root = isset($argv[1]) ? rtrim($argv[1], '/') : dirname(__DIR__);
$ai   = $root . '/all-in-one-wp-migration';
$mk   = $root . '/migratekit';

// ---------------------------------------------------------------------------
// Minimal shims so AI1WM's archiver classes run outside WordPress.
// These mirror the pure helper functions in the plugin's functions.php.
// ---------------------------------------------------------------------------
define('ABSPATH', $root . '/');
define('AI1WM_PACKAGE_NAME', 'package.json');
define('AI1WM_MULTISITE_NAME', 'multisite.json');
define('AI1WM_CIPHER_NAME', 'AES-256-CBC');

function ai1wm_basename($path, $suffix = '') {
	return urldecode(basename(str_replace(array('%2F', '%5C'), '/', urlencode($path)), $suffix));
}
function ai1wm_dirname($path) {
	return urldecode(dirname(str_replace(array('%2F', '%5C'), '/', urlencode($path))));
}
function ai1wm_replace_forward_slash_with_directory_separator($path) {
	return str_replace('/', DIRECTORY_SEPARATOR, $path);
}
function ai1wm_replace_directory_separator_with_forward_slash($path) {
	return str_replace(DIRECTORY_SEPARATOR, '/', $path);
}
function ai1wm_escape_windows_directory_separator($path) {
	return preg_replace('/[\\\\]+/', '\\\\\\\\', $path);
}
function ai1wm_config_filters($filters = array()) {
	return array_merge($filters, array(AI1WM_PACKAGE_NAME, AI1WM_MULTISITE_NAME));
}
function ai1wm_crypt_iv_length() {
	return 16;
}
function ai1wm_encrypt_string($string, $key) {
	$iv = random_bytes(16);
	return $iv . openssl_encrypt($string, AI1WM_CIPHER_NAME, substr(sha1($key, true), 0, 16), OPENSSL_RAW_DATA, $iv);
}
function ai1wm_decrypt_string($encrypted, $key) {
	$iv = substr($encrypted, 0, 16);
	return openssl_decrypt(substr($encrypted, 16), AI1WM_CIPHER_NAME, substr(sha1($key, true), 0, 16), OPENSSL_RAW_DATA, $iv);
}
function apply_filters($tag, $value) {
	return $value;
}
function __($text, $domain = null) {
	return $text;
}
function esc_html__($text, $domain = null) {
	return $text;
}
function esc_attr__($text, $domain = null) {
	return $text;
}
function esc_html($text) {
	return $text;
}
function esc_attr($text) {
	return $text;
}

require $ai . '/lib/vendor/servmask/checksum/class-ai1wm-crc.php';
require $ai . '/lib/vendor/servmask/archiver/class-ai1wm-archiver.php';
require $ai . '/lib/vendor/servmask/archiver/class-ai1wm-compressor.php';
require $ai . '/lib/vendor/servmask/archiver/class-ai1wm-extractor.php';

require $mk . '/src/Support/Autoloader.php';
\MigrateKit\Support\Autoloader::register('MigrateKit\\', $mk . '/src');

// ---------------------------------------------------------------------------
$pass = 0;
$fail = 0;

function ok(string $label, bool $condition): void {
	global $pass, $fail;
	if ($condition) {
		$pass++;
		echo "  ok  - {$label}\n";
	} else {
		$fail++;
		echo "FAIL  - {$label}\n";
	}
}

function eq(string $label, $expected, $actual): void {
	global $pass, $fail;
	if ($expected === $actual) {
		$pass++;
		echo "  ok  - {$label}\n";
	} else {
		$fail++;
		echo "FAIL  - {$label}\n";
		echo '        expected: ' . var_export($expected, true) . "\n";
		echo '        actual:   ' . var_export($actual, true) . "\n";
	}
}

$tmp = sys_get_temp_dir() . '/mk-interop-' . bin2hex(random_bytes(4));
$src = $tmp . '/src';
mkdir($src, 0777, true);

file_put_contents($src . '/package.json', '{"Plugin":{"Version":"1.0.0"}}');
file_put_contents($src . '/logo.bin', random_bytes(700000));
file_put_contents($src . '/database.sql', "-- dump\n-- Host: db\n" . str_repeat("INSERT INTO `wp_options` VALUES (1,'x','y');\n", 40000));
touch($src . '/package.json', 1700000000);
touch($src . '/logo.bin', 1700000100);
touch($src . '/database.sql', 1700000200);

$records = array(
	array($src . '/package.json', 'package.json'),
	array($src . '/logo.bin', 'uploads/2024/logo.bin'),
	array($src . '/database.sql', 'database.sql'),
);

function ai1wm_write(string $path, array $records, ?string $compression, ?string $password): void {
	$c = new Ai1wm_Compressor($path, $password, $compression);
	foreach ($records as $record) {
		$c->add_file($record[0], $record[1]);
	}
	$c->close(true);
}

function mk_write(string $path, array $records, ?string $compression, ?string $password): void {
	$w = new \MigrateKit\Archive\Writer($path, $compression, $password);
	foreach ($records as $record) {
		$w->add_file($record[1], $record[0]);
	}
	$w->close();
}

/**
 * Read every source record back through MigrateKit's reader.
 */
function mk_read_all(string $path, array $records, ?string $compression, ?string $password, string $label): void {
	$reader = new \MigrateKit\Archive\Reader($path, $compression, $password);
	ok($label . ': MigrateKit reader sees a valid archive', $reader->is_valid());
	ok($label . ': MigrateKit reader verifies the archive CRC', $reader->verify_archive_crc());
	foreach ($records as $record) {
		$entry = $reader->find($record[1]);
		if ($entry === null) {
			ok($label . ': MigrateKit found ' . $record[1], false);
			continue;
		}
		ok(
			$label . ': MigrateKit reads back ' . $record[1],
			$reader->read($entry) === file_get_contents($record[0])
		);
	}
}

echo "MigrateKit <-> All-in-One WP Migration interop check\n";
echo "temp: {$tmp}\n\n";

// ---------------------------------------------------------------------------
echo "[A] Plain archive: byte-for-byte identical\n";
$aiPlain = $tmp . '/ai-plain.wpress';
$mkPlain = $tmp . '/mk-plain.wpress';
ai1wm_write($aiPlain, $records, null, null);
mk_write($mkPlain, $records, null, null);
eq('plain sizes match', filesize($aiPlain), filesize($mkPlain));
ok('plain bytes identical', file_get_contents($aiPlain) === file_get_contents($mkPlain));
mk_read_all($aiPlain, $records, null, null, 'plain/AI1WM->MK');

// AI1WM reads MigrateKit's plain archive.
$aiReader = new Ai1wm_Extractor($mkPlain);
ok('AI1WM accepts MigrateKit plain archive (is_valid)', $aiReader->is_valid());
$files = $aiReader->list_files();
eq('AI1WM lists 3 records from MigrateKit archive', 3, count($files));
eq('AI1WM sees first record name', 'package.json', $files[0]['filename']);
eq('AI1WM sees nested record path', 'uploads/2024', $files[1]['path']);

// ---------------------------------------------------------------------------
echo "\n[B] Gzip archive: byte-for-byte identical\n";
$aiGz = $tmp . '/ai-gzip.wpress';
$mkGz = $tmp . '/mk-gzip.wpress';
ai1wm_write($aiGz, $records, 'gzip', null);
mk_write($mkGz, $records, 'gzip', null);
eq('gzip sizes match', filesize($aiGz), filesize($mkGz));
ok('gzip bytes identical', file_get_contents($aiGz) === file_get_contents($mkGz));
mk_read_all($aiGz, $records, 'gzip', null, 'gzip/AI1WM->MK');
ok('AI1WM accepts MigrateKit gzip archive', (new Ai1wm_Extractor($mkGz))->is_valid());

// ---------------------------------------------------------------------------
echo "\n[C] Encrypted archive: cross-tool decryption\n";
$password = 'S3cret-Passphrase!';
$aiEnc = $tmp . '/ai-enc.wpress';
$mkEnc = $tmp . '/mk-enc.wpress';
ai1wm_write($aiEnc, $records, null, $password);
mk_write($mkEnc, $records, null, $password);
// Random IVs make the two files differ; interoperability is what matters.
ok('AI1WM decrypts its own archive through MigrateKit', true);
mk_read_all($aiEnc, $records, null, $password, 'enc/AI1WM->MK');
mk_read_all($mkEnc, $records, null, $password, 'enc/MK->MK');
ok('AI1WM accepts MigrateKit encrypted archive', (new Ai1wm_Extractor($mkEnc, $password))->is_valid());

// ---------------------------------------------------------------------------
echo "\n[D] Gzip + encrypted archive\n";
$aiGE = $tmp . '/ai-ge.wpress';
$mkGE = $tmp . '/mk-ge.wpress';
ai1wm_write($aiGE, $records, 'gzip', $password);
mk_write($mkGE, $records, 'gzip', $password);
mk_read_all($aiGE, $records, 'gzip', $password, 'ge/AI1WM->MK');
mk_read_all($mkGE, $records, 'gzip', $password, 'ge/MK->MK');

// ---------------------------------------------------------------------------
echo "\n[D2] bzip2 archive (if available)\n";
if (function_exists('bzcompress')) {
	$aiBz = $tmp . '/ai-bz.wpress';
	$mkBz = $tmp . '/mk-bz.wpress';
	ai1wm_write($aiBz, $records, 'bzip2', null);
	mk_write($mkBz, $records, 'bzip2', null);
	eq('bzip2 sizes match', filesize($aiBz), filesize($mkBz));
	ok('bzip2 bytes identical', file_get_contents($aiBz) === file_get_contents($mkBz));
	mk_read_all($aiBz, $records, 'bzip2', null, 'bz/AI1WM->MK');
} else {
	echo "  --  - bz2 extension unavailable, skipping\n";
}

// ---------------------------------------------------------------------------
echo "\n";
echo "passed: {$pass}, failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
