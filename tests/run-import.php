<?php
/**
 * End-to-end test for the import pipeline and replacement matrix.
 *
 * Exports a fake source site, then imports the archive into a fake destination
 * with a different URL and table prefix.
 *
 * Usage: php tests/run-import.php
 *
 * @package MigrateKit
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MigrateKit\Archive\Reader;
use MigrateKit\Database\Driver\DriverInterface;
use MigrateKit\Database\Sql\LiteralRewriter;
use MigrateKit\Export\ExportPipeline;
use MigrateKit\Import\ImportPipeline;
use MigrateKit\Import\ReplaceMatrixBuilder;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Site\SiteContext;
use MigrateKit\Support\PathGuard;

$GLOBALS['im_pass'] = 0;
$GLOBALS['im_fail'] = 0;

function check_im(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['im_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['im_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function same_im(string $label, $expected, $actual): void {
	if ($expected === $actual) {
		$GLOBALS['im_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['im_fail']++;
		echo "FAIL  - {$label}\n";
		echo '        expected: ' . var_export($expected, true) . "\n";
		echo '        actual:   ' . var_export($actual, true) . "\n";
	}
}

final class ImportResult {
	/** @var array<int,array<string,mixed>> */
	public $rows;

	public function __construct(array $rows) {
		$this->rows = $rows;
	}
}

final class ImportFakeDriver implements DriverInterface {
	/** @var array<string,array{columns:string[],rows:array<int,array<string,mixed>>}> */
	public $tables = array();

	/** @var string[] */
	public $statements = array();

	private $errno = 0;
	private $error = '';

	public function query(string $sql) {
		$this->statements[] = $sql;

		if (preg_match('/^SELECT \* FROM `([^`]+)`(.*)$/iu', trim($sql), $match) === 1) {
			$rows = isset($this->tables[ $match[1] ]['rows']) ? $this->tables[ $match[1] ]['rows'] : array();

			// The dumper pages through rows; honour the page it asked for.
			if (preg_match('/\bLIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?$/i', $match[2], $limit) === 1) {
				$rows = array_slice($rows, isset($limit[2]) && $limit[2] !== '' ? (int) $limit[2] : 0, (int) $limit[1]);
			}

			return new ImportResult($rows);
		}

		return true;
	}

	public function fetch_assoc($result): ?array {
		if (! ($result instanceof ImportResult) || $result->rows === array()) {
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
		return 'wp_new';
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
		return $this->errno;
	}

	public function last_error(): string {
		return $this->error;
	}

	public function close(): void {
	}
}

$tmp = sys_get_temp_dir() . '/mk-import-' . bin2hex(random_bytes(4));
$source = $tmp . '/source';
$dest   = $tmp . '/dest';

$s_content = $source . '/content';
$s_uploads = $s_content . '/uploads';
$s_plugins = $s_content . '/plugins';
$s_themes  = $s_content . '/themes';

mkdir($s_content . '/languages', 0777, true);
mkdir($s_content . '/custom', 0777, true);
mkdir($s_uploads . '/2024/01', 0777, true);
mkdir($s_plugins . '/hello', 0777, true);
mkdir($s_themes . '/themeA', 0777, true);

file_put_contents($s_content . '/advanced-cache.php', "<?php // cache\n");
file_put_contents($s_content . '/languages/en_US.mo', 'translations');
file_put_contents($s_content . '/custom/thing.txt', 'custom content');
file_put_contents($s_uploads . '/2024/01/a.jpg', random_bytes(300000));
file_put_contents($s_plugins . '/hello/hello.php', "<?php // plugin\n");
file_put_contents($s_themes . '/themeA/style.css', "/* theme */\n");

$d_content = $dest . '/content';
$d_uploads = $d_content . '/uploads';
$d_plugins = $d_content . '/plugins';
$d_themes  = $d_content . '/themes';

mkdir($d_content, 0777, true);
mkdir($d_uploads, 0777, true);
mkdir($d_plugins, 0777, true);
mkdir($d_themes, 0777, true);

$source_site = new SiteContext(
	array(
		'site_url'      => 'http://old.test',
		'home_url'      => 'http://old.test',
		'absolute_path' => $source . '/',
		'content_path'  => $s_content,
		'plugins_path'  => $s_plugins,
		'themes_path'   => $s_themes,
		'uploads_path'  => $s_uploads,
		'uploads_url'   => 'http://old.test/wp-content/uploads',
		'wp_version'    => '6.8',
		'db_version'    => '8.0.0',
		'table_prefix'  => 'wp_',
		'plugins'       => array( 'hello/hello.php' ),
		'themes'        => array( 'themeA' ),
		'template'      => 'themeA',
		'stylesheet'    => 'themeA',
	)
);

// Source DB includes plain and serialized URLs.
$source_db = new ImportFakeDriver();
$source_db->tables = array(
	'wp_options' => array(
		'columns' => array( 'option_id', 'option_name', 'option_value' ),
		'rows'    => array(
			array( 'option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'http://old.test' ),
			array( 'option_id' => 2, 'option_name' => 'serialized', 'option_value' => serialize(array( 'u' => 'http://old.test/path' )) ),
			array( 'option_id' => 3, 'option_name' => 'plain', 'option_value' => 'Visit http://old.test/page' ),
		),
	),
);

$archive = $tmp . '/export.wpress';
$export_state = array(
	'archive_path' => $archive,
	'storage_dir'  => $tmp . '/export-storage',
	'options'      => array(),
);

echo "MigrateKit — import pipeline suite\n\n";

echo "[1] Export source site\n";
$export_ctx = new PipelineContext($source_db, $source_site, null, 0.0);
$exported   = ExportPipeline::create()->advance($export_state, $export_ctx);
check_im('export pipeline completes', $exported);
check_im('archive exists', is_file($archive));

echo "\n[2] Import into a different site\n";
$dest_site = new SiteContext(
	array(
		'site_url'      => 'https://new.example',
		'home_url'      => 'https://new.example',
		'absolute_path' => $dest . '/',
		'content_path'  => $d_content,
		'plugins_path'  => $d_plugins,
		'themes_path'   => $d_themes,
		'uploads_path'  => $d_uploads,
		'uploads_url'   => 'https://new.example/wp-content/uploads',
		'wp_version'    => '6.8',
		'db_version'    => '8.0.0',
		'table_prefix'  => 'new_',
		'plugins'       => array( 'hello/hello.php' ),
		'themes'        => array( 'themeA' ),
		'template'      => 'themeA',
		'stylesheet'    => 'themeA',
	)
);

$dest_db = new ImportFakeDriver();
$import_state = array(
	'archive_path' => $archive,
	'storage_dir'  => $tmp . '/import-storage',
	'options'      => array(),
);

$import_ctx = new PipelineContext($dest_db, $dest_site, null, 0.0);
$imported   = ImportPipeline::create()->advance($import_state, $import_ctx);

check_im('import pipeline completes', $imported);
check_im('import state marked done', ! empty($import_state['import_done']));

check_im('content file extracted', is_file($d_content . '/languages/en_US.mo'));
same_im('content file contents', 'translations', (string) file_get_contents($d_content . '/languages/en_US.mo'));
check_im('nested content extracted', is_file($d_content . '/custom/thing.txt'));
check_im('drop-in extracted', is_file($d_content . '/advanced-cache.php'));
check_im('media extracted to uploads', is_file($d_uploads . '/2024/01/a.jpg'));
same_im(
	'media bytes match',
	hash_file('sha256', $s_uploads . '/2024/01/a.jpg'),
	hash_file('sha256', $d_uploads . '/2024/01/a.jpg')
);
check_im('plugin extracted', is_file($d_plugins . '/hello/hello.php'));
check_im('theme extracted', is_file($d_themes . '/themeA/style.css'));
check_im('package.json not extracted as a site file', ! is_file($d_content . '/package.json'));
check_im('database.sql not extracted as a site file', ! is_file($d_content . '/database.sql'));
check_im('database dump stored in storage', is_file($tmp . '/import-storage/database.sql'));

// Database statements.
$joined = implode("\n", array_map('strval', $dest_db->statements));
check_im('statements executed', count($dest_db->statements) > 0);
check_im('placeholder mapped to destination prefix', strpos($joined, '`new_options`') !== false);
check_im('source prefix not present', strpos($joined, '`wp_options`') === false);
check_im('plain URL replaced', strpos($joined, 'https://new.example/page') !== false);
check_im('old URL removed', strpos($joined, 'http://old.test') === false);

// Find the serialized literal and verify it is valid + replaced.
$serialized_ok = false;
foreach ($dest_db->statements as $statement) {
	if (strpos($statement, 'INSERT INTO `new_options`') === false) {
		continue;
	}

	if (preg_match("/VALUES \\((.*)\\)$/s", $statement, $match) !== 1) {
		continue;
	}

	// Extract every single-quoted literal.
	preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/s", $match[1], $literals);

	foreach ($literals[1] as $raw) {
		$decoded = LiteralRewriter::unescape($raw);
		$value   = @unserialize($decoded);

		if (is_array($value) && isset($value['u'])) {
			$serialized_ok = $value['u'] === 'https://new.example/path';
		}
	}
}
check_im('serialized URL replaced with valid lengths', $serialized_ok);

echo "\n[3] Encrypted import\n";
$secure_archive = $tmp . '/secure.wpress';
$secure_state = array(
	'archive_path' => $secure_archive,
	'storage_dir'  => $tmp . '/secure-storage',
	'options'      => array( 'password' => 'correct-secret' ),
);
$secure_ctx = new PipelineContext($source_db, $source_site, null, 0.0);
check_im('encrypted export completes', ExportPipeline::create()->advance($secure_state, $secure_ctx));

$no_password = array(
	'archive_path' => $secure_archive,
	'storage_dir'  => $tmp . '/secure-import-1',
	'options'      => array(),
);
$threw = false;
try {
	$ctx = new PipelineContext($dest_db, $dest_site, null, 0.0);
	ImportPipeline::create()->advance($no_password, $ctx);
} catch (\RuntimeException $e) {
	$threw = strpos($e->getMessage(), 'password') !== false;
}
check_im('missing password is rejected', $threw);

$wrong_password = array(
	'archive_path' => $secure_archive,
	'storage_dir'  => $tmp . '/secure-import-2',
	'options'      => array( 'password' => 'wrong-secret' ),
);
$threw = false;
try {
	$ctx = new PipelineContext($dest_db, $dest_site, null, 0.0);
	ImportPipeline::create()->advance($wrong_password, $ctx);
} catch (\RuntimeException $e) {
	$threw = strpos($e->getMessage(), 'incorrect') !== false;
}
check_im('wrong password is rejected', $threw);

$ok_password = array(
	'archive_path' => $secure_archive,
	'storage_dir'  => $tmp . '/secure-import-3',
	'options'      => array( 'password' => 'correct-secret' ),
);
$ok_ctx = new PipelineContext(new ImportFakeDriver(), $dest_site, null, 0.0);
check_im('correct password imports', ImportPipeline::create()->advance($ok_password, $ok_ctx));

echo "\n[4] Replacement matrix variants\n";
$package = array(
	'SiteURL'  => 'http://old.test',
	'HomeURL'  => 'http://old.test',
	'WordPress' => array(
		'Absolute' => '/var/www/old/',
		'Content'  => '/var/www/old/wp-content/',
	),
	'Replace'  => array(
		'OldValues' => array( 'shop.example' ),
		'NewValues' => array( 'store.example' ),
	),
);
$engine = ReplaceMatrixBuilder::build($package, $dest_site);
same_im('plain URL replaced', 'https://new.example/x', $engine->apply('http://old.test/x'));
same_im('scheme-relative URL replaced', '//new.example/x', $engine->apply('//old.test/x'));
$ser = serialize(array('u' => 'http://old.test/y'));
$decoded_ser = unserialize($engine->apply($ser));
same_im('serialized value replaced', 'https://new.example/y', $decoded_ser['u']);
check_im('urlencoded variant replaced', strpos($engine->apply(urlencode('http://old.test/a b')), urlencode('https://new.example/a b')) !== false);
same_im('user pair replaced', 'store.example', $engine->apply('shop.example'));
same_im('email domain replaced', 'admin@new.example', $engine->apply('admin@old.test'));
same_im('absolute path replaced', $dest . '/file', $engine->apply('/var/www/old/file'));

echo "\n[5] Path guard\n";
same_im('normal join', $dest . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b.txt', PathGuard::join($dest, 'a/b.txt'));
same_im('traversal rejected', null, PathGuard::join($dest, 'a/../../etc/passwd'));
same_im('leading slash neutralised', $dest . DIRECTORY_SEPARATOR . 'etc', PathGuard::join($dest, '/etc'));
same_im('backslashes normalised', $dest . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b', PathGuard::join($dest, 'a\\b'));

echo "\n";
echo "passed: {$GLOBALS['im_pass']}, failed: {$GLOBALS['im_fail']}\n";
exit($GLOBALS['im_fail'] === 0 ? 0 : 1);
