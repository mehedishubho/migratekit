<?php
/**
 * Backup download endpoint tests (admin-post stream handler).
 *
 * Runs the real AdminPostController::download()/download_entry() in a child
 * PHP process with WordPress shims, capturing headers and the streamed body.
 *
 * Usage: php tests/run-download.php
 *
 * @package MigrateKit
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MigrateKit\Archive\Writer;

$GLOBALS['dl_pass'] = 0;
$GLOBALS['dl_fail'] = 0;

function check_dl(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['dl_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['dl_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function same_dl(string $label, $expected, $actual): void {
	if ($expected === $actual) {
		$GLOBALS['dl_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['dl_fail']++;
		echo "FAIL  - {$label}\n";
		echo '        expected: ' . var_export($expected, true) . "\n";
		echo '        actual:   ' . var_export($actual, true) . "\n";
	}
}

/**
 * Run one download scenario in a child process and capture the response.
 *
 * @param string $mode  download|entry|junk.
 * @param string $name  Backup file name.
 * @param string $entry Archive entry path (entry mode).
 * @param bool   $nocap Disable the capability check.
 * @param bool   $badnonce Fail the nonce check.
 * @return array{exit:int,stdout:string,stderr:string,headers:array<int,string>}
 */
function run_child(string $mode, string $name, string $entry = '', bool $nocap = false, bool $badnonce = false): array {
	$headers_file = $GLOBALS['dl_tmp'] . '/headers-' . bin2hex(random_bytes(3)) . '.txt';
	@unlink($headers_file);

	$cmd = escapeshellarg(PHP_BINARY)
		. ' -n -d error_reporting=E_ERROR -d display_errors=0 '
		. escapeshellarg($GLOBALS['dl_child'])
		. ' ' . escapeshellarg($mode)
		. ' ' . escapeshellarg($name)
		. ' ' . escapeshellarg($entry)
		. ' ' . escapeshellarg($GLOBALS['dl_content_dir'])
		. ' ' . escapeshellarg($headers_file)
		. ' ' . ( $nocap ? '1' : '0' )
		. ' ' . ( $badnonce ? '1' : '0' );

	$pipes = array();
	$process = proc_open($cmd, array(
		0 => array('pipe', 'r'),
		1 => array('pipe', 'w'),
		2 => array('pipe', 'w'),
	), $pipes);

	if (! is_resource($process)) {
		return array('exit' => -1, 'stdout' => '', 'stderr' => "child failed to start\n", 'headers' => array());
	}

	fclose($pipes[0]);
	$stdout = (string) stream_get_contents($pipes[1]);
	$stderr = (string) stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	$code = proc_close($process);

	$raw = is_file($headers_file) ? (string) file_get_contents($headers_file) : '';
	@unlink($headers_file);

	$headers = array_values(array_filter(array_map('trim', explode("\n", $raw))));

	return array(
		'exit' => $code,
		'stdout' => $stdout,
		'stderr' => $stderr,
		'headers' => $headers,
	);
}

$tmp = sys_get_temp_dir() . '/mk-download-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

$content_dir = $tmp . '/wp-content';
$backups_dir = $content_dir . '/migratekit-backups';
mkdir($backups_dir, 0777, true);

// A real archive the entry browser can read.
$sample = "MigrateKit single-entry download payload\n" . str_repeat("0123456789", 300);
$archive_path = $backups_dir . '/download-test.wpress';

$writer = new Writer($archive_path, null, null, true);
$writer->add_contents('package.json', '{"SiteURL":"http://old.test","Plugin":{"Version":"0.0.1"}}');
$writer->add_contents('uploads/sample.txt', $sample);
$writer->close(true);

$archive_bytes = (string) file_get_contents($archive_path);
$archive_size = strlen($archive_bytes);

// Child runner: WordPress shims plus a namespaced header() capture. The
// header() shadow only affects MigrateKit\Http code, exactly what the real
// handler calls; readfile() keeps writing to stdout for byte comparison.
$child_template = <<<'PHP'
<?php
declare(strict_types=1);

namespace {
	define('WP_CONTENT_DIR', $argv[4]);

	require __MK_BOOTSTRAP__;

	$GLOBALS['mk_headers_file'] = $argv[5];
	$GLOBALS['mk_can'] = $argv[6] !== '1';
	$GLOBALS['mk_nonce_ok'] = $argv[7] !== '1';

	function is_multisite() { return false; }
	function is_super_admin() { return true; }
	function current_user_can($cap) { return ! empty($GLOBALS['mk_can']); }
	function wp_unslash($value) { return $value; }
	function nocache_headers() {}
	function wp_raise_memory_limit($context = '') {}
	function wp_tempnam($name = '') { return tempnam(sys_get_temp_dir(), 'mkentry'); }
	function sanitize_text_field($str) { return trim(strip_tags((string) $str)); }

	function wp_die($message = '', $title = '', $args = array()) {
		fwrite(STDERR, 'WPDIE: ' . (is_string($message) ? $message : '') . "\n");
		exit(3);
	}

	function check_admin_referer($action = -1, $query_arg = '_wpnonce') {
		if (! empty($GLOBALS['mk_nonce_ok']) && isset($_REQUEST[ $query_arg ])) {
			return true;
		}

		wp_die('The link you followed has expired.');
	}

	$mode = isset($argv[1]) ? $argv[1] : 'download';
	$name = isset($argv[2]) ? $argv[2] : '';
	$entry = isset($argv[3]) ? $argv[3] : '';

	$_GET = array('file' => $name, '_wpnonce' => 'test');
	$_REQUEST = $_GET;

	if ($mode === 'entry' && $entry !== '') {
		$_GET['entry'] = $entry;
		$_REQUEST['entry'] = $entry;
	}

	// Simulate another plugin having left dirty output buffered.
	if ($mode === 'junk') {
		ob_start();
		echo 'LEADING-OUTPUT-FROM-ANOTHER-PLUGIN';
	}

	if ($mode === 'entry') {
		\MigrateKit\Http\AdminPostController::download_entry();
	} else {
		\MigrateKit\Http\AdminPostController::download();
	}
}

namespace MigrateKit\Http {
	function header($value, $replace = true, $response_code = 0) {
		file_put_contents($GLOBALS['mk_headers_file'], $value . "\n", FILE_APPEND);
	}
}
PHP;

$child = $tmp . '/child-download.php';
file_put_contents($child, str_replace(
	'__MK_BOOTSTRAP__',
	var_export(realpath(__DIR__ . '/bootstrap.php'), true),
	$child_template
));

$GLOBALS['dl_tmp'] = $tmp;
$GLOBALS['dl_child'] = $child;
$GLOBALS['dl_content_dir'] = $content_dir;

echo "MigrateKit — download endpoint suite\n\n";

echo "[1] Whole-archive download\n";
$result = run_child('download', 'download-test.wpress');

same_dl('exit code 0', 0, $result['exit']);
same_dl('streamed body matches archive byte-for-byte', md5($archive_bytes), md5($result['stdout']));
check_dl('attachment content-type header', in_array('Content-Type: application/octet-stream', $result['headers'], true));
check_dl(
	'attachment disposition header',
	in_array('Content-Disposition: attachment; filename="download-test.wpress"', $result['headers'], true)
);
check_dl(
	'content-length header',
	in_array('Content-Length: ' . (string) $archive_size, $result['headers'], true)
);
check_dl('no error output', $result['stderr'] === '');

echo "\n[2] Download with dirty output buffers (other plugins)\n";
$result = run_child('junk', 'download-test.wpress');

same_dl('buffered junk stripped, body intact', md5($archive_bytes), md5($result['stdout']));
same_dl('exit code 0', 0, $result['exit']);

echo "\n[3] Single-entry download (backup browser)\n";
$result = run_child('entry', 'download-test.wpress', 'uploads/sample.txt');

same_dl('entry body matches file content', md5($sample), md5($result['stdout']));
check_dl(
	'entry disposition header',
	in_array('Content-Disposition: attachment; filename="sample.txt"', $result['headers'], true)
);
same_dl('exit code 0', 0, $result['exit']);

echo "\n[4] Failure paths\n";
// basename() neutralises directories, so this stays invalid only because of
// the extension — exactly the guard validate_name() provides.
$result = run_child('download', '../../evil.php');
check_dl('invalid name rejected', $result['exit'] === 3 && strpos($result['stderr'], 'WPDIE: ') === 0);

$result = run_child('download', 'missing.wpress');
check_dl('missing backup rejected', $result['exit'] === 3 && strpos($result['stderr'], 'WPDIE: ') === 0);

$result = run_child('download', 'download-test.wpress', '', true, false);
check_dl('missing capability rejected', $result['exit'] === 3 && strpos($result['stderr'], 'WPDIE: ') === 0);

$result = run_child('download', 'download-test.wpress', '', false, true);
check_dl('bad nonce rejected', $result['exit'] === 3 && strpos($result['stderr'], 'WPDIE: ') === 0);

$result = run_child('entry', 'download-test.wpress', 'not/in/archive.txt');
check_dl('unknown entry rejected', $result['exit'] === 3 && strpos($result['stderr'], 'WPDIE: ') === 0);

echo "\n";
echo "passed: {$GLOBALS['dl_pass']}, failed: {$GLOBALS['dl_fail']}\n";

function dl_cleanup(string $dir): void {
	foreach (glob($dir . '/*') ?: array() as $item) {
		if (is_dir($item)) {
			dl_cleanup($item);
		} else {
			@unlink($item);
		}
	}

	@rmdir($dir);
}
dl_cleanup($tmp);

exit($GLOBALS['dl_fail'] === 0 ? 0 : 1);
