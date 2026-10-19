<?php
/**
 * Parity-audit suite: export filters, chunked upload, archive browser,
 * labels, prune, table scoping and secret checks — all offline.
 *
 * Usage: php tests/run-parity.php
 *
 * @package MigrateKit
 */

declare(strict_types=1);

define('MIGRATEKIT_TESTING', true);

require __DIR__ . '/bootstrap.php';

use MigrateKit\Archive\Encryptor;
use MigrateKit\Archive\Inspector;
use MigrateKit\Archive\Protocol;
use MigrateKit\Archive\Reader;
use MigrateKit\Archive\Writer;
use MigrateKit\Export\ExportConfig;
use MigrateKit\Export\Stages\EnumerateFilesStage;
use MigrateKit\Http\Options;
use MigrateKit\Import\Stages\DatabaseStage as ImportDatabaseStage;
use MigrateKit\Job\JobId;
use MigrateKit\Job\Runner;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Scheduler\Cron;
use MigrateKit\Site\SiteContext;
use MigrateKit\Storage\BackupsRepository;
use MigrateKit\Support\Maintenance;
use MigrateKit\Support\OptionStore;
use MigrateKit\Support\Paths;
use MigrateKit\Support\SecretKey;

$GLOBALS['pw_pass'] = 0;
$GLOBALS['pw_fail'] = 0;

function check_pw(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['pw_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['pw_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function same_pw(string $label, $expected, $actual): void {
	if ($expected === $actual) {
		$GLOBALS['pw_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['pw_fail']++;
		echo "FAIL  - {$label}\n";
		echo '        expected: ' . var_export($expected, true) . "\n";
		echo '        actual:   ' . var_export($actual, true) . "\n";
	}
}

/** Minimal WP shim for the Runner's storage dir mkdir. */
if (! function_exists('wp_mkdir_p')) {
	function wp_mkdir_p(string $dir): bool {
		return is_dir($dir) || mkdir($dir, 0777, true);
	}
}

$tmp    = sys_get_temp_dir() . '/mk-parity-' . bin2hex(random_bytes(4));
$paths  = $tmp . '/plugin';
$sanity = $tmp . '/content';
mkdir($paths, 0777, true);
mkdir($sanity, 0777, true);

define('MIGRATEKIT_PATH', $paths);
if (! defined('WP_CONTENT_DIR')) {
	define('WP_CONTENT_DIR', $sanity);
}

OptionStore::reset();

echo "MigrateKit — parity audit suite\n";
echo "temp: {$tmp}\n\n";

// ---------------------------------------------------------------------------
echo "[1] Export option normalization\n";

$normalized = Options::export(array(
	'excluded_tables' => array( 'wp_log!', 'wp_logs', 'wp_logs', '', 'wp债务' ),
	'included_tables' => array( 'wp_posts', 'wp-postmeta', 'wp_postmeta' ),
	'no_email_replace' => 'yes',
	'replace_old'     => array( 'http://old.test' ),
	'replace_new'     => array( 'http://new.test' ),
));

same_pw('excluded tables sanitized and deduplicated', array( 'wp_logs' ), $normalized['excluded_tables']);
same_pw('included tables sanitized and deduplicated', array( 'wp_posts', 'wp-postmeta', 'wp_postmeta' ), $normalized['included_tables']);
same_pw('truthy flag accepted', true, $normalized['no_email_replace']);
same_pw('replace pairs preserved', array( 'http://old.test' ), $normalized['replace_old']);

$empty = Options::export(array());
same_pw('empty request has empty table filters', array( '', '' ), array( $empty['excluded_tables'] === array() ? '' : 'x', $empty['included_tables'] === array() ? '' : 'y' ));

// ---------------------------------------------------------------------------
echo "\n[2] Table scoping reaches the manifest\n";

$table_site = new SiteContext(array(
	'site_url'     => 'https://whitebox.test',
	'home_url'     => 'https://whitebox.test',
	'table_prefix' => 'wp_',
	'wp_version'   => '7.1.2',
));

$manifest = ExportConfig::build($table_site, array(
	'excluded_tables' => array( 'wp_log_entries' ),
	'included_tables' => array( 'wp_posts' ),
));

same_pw('manifest records exclusions', array( 'wp_log_entries' ), $manifest['Database']['ExcludedTables']);
same_pw('manifest records inclusions', array( 'wp_posts' ), $manifest['Database']['IncludedTables']);

$plain_manifest = ExportConfig::build($table_site, array());
same_pw('no filters unset in plain manifest', array( array(), array() ), array( $plain_manifest['Database']['ExcludedTables'], $plain_manifest['Database']['IncludedTables'] ));

// ---------------------------------------------------------------------------
echo "\n[3] Active-only file filters\n";

$enum_tmp = $tmp . '/files';
mkdir($enum_tmp . '/wp-content/plugins/alpha/inc', 0777, true);
mkdir($enum_tmp . '/wp-content/plugins/beta', 0777, true);
mkdir($enum_tmp . '/wp-content/themes/twenty', 0777, true);
mkdir($enum_tmp . '/wp-content/themes/classic', 0777, true);

file_put_contents($enum_tmp . '/wp-content/plugins/alpha/inc/main.php', 'a');
file_put_contents($enum_tmp . '/wp-content/plugins/hello.php', 'h');
file_put_contents($enum_tmp . '/wp-content/plugins/beta/loader.php', 'b');
file_put_contents($enum_tmp . '/wp-content/themes/twenty/style.css', 't');
file_put_contents($enum_tmp . '/wp-content/themes/classic/style.css', 'c');

$enum_site = new SiteContext(array(
	'plugins_path'    => trail_test($enum_tmp . '/wp-content/plugins'),
	'themes_path'     => trail_test($enum_tmp . '/wp-content/themes'),
	'plugins'         => array( 'alpha/alpha.php', 'hello.php' ),
	'template'        => 'twenty',
	'stylesheet'      => 'twenty',
	'network_plugins' => array(),
));

$ctx = new PipelineContext(null, $enum_site, null, 0.0);

$state = array( 'paths' => array(
	'plugins_path' => trail_test($enum_tmp . '/wp-content/plugins'),
	'themes_path'  => trail_test($enum_tmp . '/wp-content/themes'),
), 'options' => array( 'no_inactive_plugins' => true ) );

(new EnumerateFilesStage('plugins', 'plugins_path', 'plugins', array(), array(), '', 'no_inactive_plugins', 'plugins'))->run($state, $ctx);

$same = array_map('basename', $state['manifests']['plugins']);
sort($same);
same_pw('inactive plugins filtered out', array( 'hello.php', 'main.php' ), $same);

$ctx = new PipelineContext(null, $enum_site, null, 0.0);
$theme_state = $state;
$theme_state['options'] = array( 'no_inactive_themes' => true );

(new EnumerateFilesStage('themes', 'themes_path', 'themes', array(), array(), '', 'no_inactive_themes', 'themes'))->run($theme_state, $ctx);

$themes = array_map('basename', $theme_state['manifests']['themes']);
sort($themes);
same_pw('inactive themes filtered out', array( 'style.css' ), $themes);

$wide_state = array( 'paths' => $theme_state['paths'], 'options' => array() );
(new EnumerateFilesStage('plugins', 'plugins_path', 'plugins'))->run($wide_state, $ctx);
same_pw('filter off keeps everything', 3, count($wide_state['manifests']['plugins']));

$network_state = array( 'paths' => $state['paths'], 'options' => array( 'no_inactive_plugins' => true ) );
$network_site = new SiteContext(array(
	'plugins_path'    => trail_test($enum_tmp . '/wp-content/plugins'),
	'themes_path'     => trail_test($enum_tmp . '/wp-content/themes'),
	'plugins'         => array(),
	'network_plugins' => array( 'alpha/alpha.php' ),
	'template'        => 'twenty',
	'stylesheet'      => 'twenty',
));
(new EnumerateFilesStage('plugins', 'plugins_path', 'plugins', array(), array(), '', 'no_inactive_plugins', 'plugins'))->run($network_state, new PipelineContext(null, $network_site, null, 0.0));
same_pw('network-activated plugins count as active', 1, count($network_state['manifests']['plugins']));

// ---------------------------------------------------------------------------
echo "\n[4] Chunked import job state\n";

$archive_bytes = make_archive_bytes();

$job = Runner::create_import_job(array( 'password' => '' ));

check_pw('reserved job id matches the REST pattern', preg_match(JobId::PATTERN, $job['id']) === 1);
check_pw('reserved job awaits upload', $job['uploaded'] === false);
check_pw('server reports zero received', $job['received'] === 0);

$chunk1 = tmpfile_name($tmp, 'c1');
file_put_contents($chunk1, substr($archive_bytes, 0, 512));
$part = (int) floor(512 / 2);
$chunk2 = tmpfile_name($tmp, 'c2');
file_put_contents($chunk2, substr($archive_bytes, 512, $part));
$chunk3 = tmpfile_name($tmp, 'c3');
file_put_contents($chunk3, substr($archive_bytes, 512 + $part));

Runner::append_import_chunk($job['id'], $chunk1, 0);

$wp_state_after = Runner::status($job['id']);
same_pw('first chunk accepted', 512, $wp_state_after['received']);

// Retried (duplicate) send is skipped without moving the offset.
$dup = Runner::append_import_chunk($job['id'], $chunk1, 0);
same_pw('duplicate chunk leaves offset untouched', 512, $dup['received']);

$gap_threw = false;
try {
	Runner::append_import_chunk($job['id'], $chunk2, 512 + $part + 1);
} catch (\RuntimeException $caught) {
	$gap_threw = strpos($caught->getMessage(), 'out of sync') !== false || strpos($caught->getMessage(), 'sync') !== false;
}
check_pw('gap chunk rejected', $gap_threw);

Runner::append_import_chunk($job['id'], $chunk2, 512);
Runner::append_import_chunk($job['id'], $chunk3, 512 + $part);

$pre_finish = Runner::status($job['id']);
same_pw('all chunks assembled', strlen($archive_bytes), $pre_finish['received']);

$short = false;
try {
	Runner::finish_import_chunks($job['id'], strlen($archive_bytes) + 10, 'upload.wpress');
} catch (\RuntimeException $caught) {
	$short = strpos($caught->getMessage(), 'incomplete') !== false;
}
check_pw('size mismatch rejected', $short);

$finished = Runner::finish_import_chunks($job['id'], strlen($archive_bytes), 'upload.wpress');
check_pw('finish validates and starts the job', $finished['uploaded'] === true);
check_pw('declared upload name recorded', $finished['archiveName'] === 'upload.wpress');

$reader = new Reader(Paths::storage() . DIRECTORY_SEPARATOR . $job['id'] . DIRECTORY_SEPARATOR . 'upload.wpress');
check_pw('staged archive passes validation', $reader->is_valid());

$second = false;
try {
	Runner::append_import_chunk($job['id'], $chunk1, 512);
} catch (\RuntimeException $caught) {
	$second = strpos($caught->getMessage(), 'already has an archive') !== false;
}
check_pw('chunks refused after finish', $second);

Runner::cancel($job['id']);

// ---------------------------------------------------------------------------
echo "\n[5] Archive inspector\n";

$plain_path = $tmp . '/plain.wpress';
$writer     = new Writer($plain_path, null, null, false);
$writer->add_contents('package.json', json_encode(array( 'SiteURL' => 'https://plain.test' )), 1700000000);
$writer->add_contents('wp-content/uploads/2024/photo.jpg', 'jpeg-bytes', 1700000000);
$writer->close(true);

$plain_pkg = Inspector::package($plain_path);
check_pw('plain manifest decoded', isset($plain_pkg['SiteURL']) && $plain_pkg['SiteURL'] === 'https://plain.test');
check_pw('plain archive not encrypted', Inspector::is_encrypted($plain_pkg) === false);
check_pw('Inspector::verify_password accepts unencrypted', (function (): bool {
	try {
		Inspector::verify_password(array(), '');
		return true;
	} catch (\Throwable $caught) {
		return false;
	}
})());

$entries = Inspector::entries($plain_path);
same_pw('entries sorted by path', 'package.json', $entries[0]['path']);
same_pw('entry sizes match', 10, $entries[1]['size']);

$missing = false;
try {
	Inspector::locate($plain_path, 'nope/missing.jpg');
} catch (\RuntimeException $caught) {
	$missing = strpos($caught->getMessage(), 'not part') !== false;
}
check_pw('missing entry rejected', $missing);

$password = 'correct horse battery staple';
$enc_path = $tmp . '/encrypted.wpress';
$enc_w    = new Writer($enc_path, null, $password);
$enc_sig  = ( new Encryptor($password) )->sign(Protocol::SIGN_TEXT);
$enc_w->add_contents('package.json', json_encode(array(
	'SiteURL'            => 'https://enc.test',
	'Encrypted'          => true,
	'EncryptedSignature' => $enc_sig,
)), 1700000000);
$enc_w->add_contents('uploads/data.txt', 'encrypted-payload', 1700000000);
$enc_w->close(true);

$enc_pkg = Inspector::package($enc_path);
check_pw('encrypted manifest readable without password', Inspector::is_encrypted($enc_pkg) === true);

$no_password_threw = false;
try {
	Inspector::verify_password($enc_pkg, '');
} catch (\RuntimeException $caught) {
	$no_password_threw = strpos($caught->getMessage(), 'Provide the decryption password') !== false;
}
check_pw('missing password flagged', $no_password_threw);

$wrong = false;
try {
	Inspector::verify_password($enc_pkg, 'wrong-password');
} catch (\RuntimeException $caught) {
	$wrong = strpos($caught->getMessage(), 'incorrect') !== false;
}
check_pw('wrong password rejected', $wrong);

Inspector::verify_password($enc_pkg, $password);
check_pw('correct password verified', true);

$listed = Inspector::entries($enc_path, $password);
check_pw('encrypted entries listed with password', isset($listed[1]['path']) && $listed[1]['path'] === 'uploads/data.txt');

// ---------------------------------------------------------------------------
echo "\n[6] Backup labels\n";

$archive_dir = $tmp . '/backups';
mkdir($archive_dir, 0777, true);
$repo = new BackupsRepository($archive_dir);
copy($plain_path, $archive_dir . '/alpha.wpress');

check_pw('set label on existing backup', BackupsRepository::set_label('alpha.wpress', 'Sunday snapshot', $archive_dir));
same_pw('label readable', 'Sunday snapshot', BackupsRepository::label_for('alpha.wpress'));
check_pw('empty label clears', BackupsRepository::set_label('alpha.wpress', '', $archive_dir));
same_pw('label cleared', '', BackupsRepository::label_for('alpha.wpress'));
check_pw('set label on missing backup fails', ! BackupsRepository::set_label('ghost.wpress', 'x', $archive_dir));
check_pw('invalid name rejected', ! BackupsRepository::set_label('../alpha.wpress', 'x', $archive_dir));

BackupsRepository::set_label('alpha.wpress', 'Kept', $archive_dir);
check_pw('delete removes the label too', $repo->delete('alpha.wpress') && BackupsRepository::label_for('alpha.wpress') === '');

// ---------------------------------------------------------------------------
echo "\n[7] Job storage pruning\n";

$prune_root = Paths::storage(); // prune scans this directory directly.

foreach (array( 'job-aaa', 'job-bbb', 'job-ccc', 'job-ddd', 'job-eee' ) as $id) {
	mkdir($prune_root . '/' . $id, 0777, true);
	file_put_contents($prune_root . '/' . $id . '/log.log', 'x');
}

$now = time();
touch($prune_root . '/job-aaa', $now - 9 * 86400);
touch($prune_root . '/job-bbb', $now - 2 * 3600);
touch($prune_root . '/job-ccc', $now - 8 * 86400);
touch($prune_root . '/job-ddd', $now - 3 * 86400);
touch($prune_root . '/job-eee', $now - 30 * 86400);

// job-aaa + job-eee: finished and older than the finished grace window.
OptionStore::put('migratekit_status_job-aaa', array( 'done' => true, 'type' => 'export' ));
OptionStore::put('migratekit_status_job-eee', array( 'done' => true, 'type' => 'export' ));
// job-ddd finished but brand new (fresh grace), job-bbb unfinished and fresh,
// job-ccc unfinished and ancient (retention window).
OptionStore::put('migratekit_status_job-bbb', array( 'type' => 'export' ));

$removed = Cron::prune($now);
same_pw('pruned old finished, fresh finished kept, abandoned removed', 3, $removed);
check_pw('unfinished ancient job removed', ! is_dir($prune_root . '/job-ccc'));
check_pw('finished ancient job removed', ! is_dir($prune_root . '/job-aaa'));
check_pw('finished ancient job (second) removed', ! is_dir($prune_root . '/job-eee'));
check_pw('unfinished fresh job kept', is_dir($prune_root . '/job-bbb'));
check_pw('unfinished 3-day job kept', is_dir($prune_root . '/job-ddd'));

$removed2 = Cron::prune($now);
same_pw('fresh dirs survive the next run', 0, $removed2);

// ---------------------------------------------------------------------------
echo "\n[8] Secret key\n";

$secret = SecretKey::get();
check_pw('secret available', strlen($secret) >= 16);
same_pw('secret stable across reads', $secret, SecretKey::get());
check_pw('wrong secret rejected', ! SecretKey::verify($secret . '-flip'));
check_pw('right secret accepted', SecretKey::verify($secret));

// ---------------------------------------------------------------------------
echo "\n[9] NoEmailReplace manifest honored on import\n";

same_pw('request flag alone skips email replacement', true, ImportDatabaseStage::skip_email_replace(array( 'no_email_replace' => true ), array()));
same_pw('manifest flag alone skips email replacement', true, ImportDatabaseStage::skip_email_replace(array(), array( 'NoEmailReplace' => true )));
same_pw('nothing set keeps replacement on', false, ImportDatabaseStage::skip_email_replace(array(), array()));
same_pw('both set still skips', true, ImportDatabaseStage::skip_email_replace(array( 'no_email_replace' => true ), array( 'NoEmailReplace' => true )));

// ---------------------------------------------------------------------------
echo "\n[10] Maintenance screen\n";

OptionStore::drop(Maintenance::OPTION);
check_pw('maintenance is off by default', Maintenance::is_on() === false);

Maintenance::start();
check_pw('maintenance turns on for import jobs', Maintenance::is_on());
check_pw('fresh flag is not expired', Maintenance::expired() === false);
check_pw('flag expires after the TTL', Maintenance::expired(time() + Maintenance::TTL + 5));

Maintenance::stop();
check_pw('maintenance turns off when the job finishes', Maintenance::is_on() === false);
check_pw('expired reads as true after stop', Maintenance::expired());

// ---------------------------------------------------------------------------

echo "\n";
echo 'passed: ' . $GLOBALS['pw_pass'] . ', failed: ' . $GLOBALS['pw_fail'] . "\n";

if ($GLOBALS['pw_fail'] > 0) {
	exit(2);
}

exit(0);

function trail_test(string $path): string {
	return rtrim($path, '/\\') . '/';
}

function tmpfile_name(string $base, string $name): string {
	return $base . '/' . $name . '.bin';
}

function make_archive_bytes(): string {
	$path = sys_get_temp_dir() . '/mk-chunk-' . bin2hex(random_bytes(4)) . '.wpress';
	$w    = new Writer($path, null, null, false);
	$w->add_contents('package.json', json_encode(array( 'SiteURL' => 'https://chunk.test' )), 1700000000);
	$w->add_contents('payload.bin', str_repeat('0123456789', 40), 1700000000);
	$w->close(true);

	$bytes = (string) file_get_contents($path);
	@unlink($path);

	return $bytes;
}
