<?php
/**
 * End-to-end test for the export pipeline and resumable archive writing.
 *
 * Usage: php tests/run-pipeline.php
 *
 * @package MigrateKit
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MigrateKit\Archive\Reader;
use MigrateKit\Archive\Writer;
use MigrateKit\Database\Driver\DriverInterface;
use MigrateKit\Export\ExportPipeline;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Site\SiteContext;

$GLOBALS['pl_pass'] = 0;
$GLOBALS['pl_fail'] = 0;

function check_pl(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['pl_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['pl_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function same_pl(string $label, $expected, $actual): void {
	if ($expected === $actual) {
		$GLOBALS['pl_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['pl_fail']++;
		echo "FAIL  - {$label}\n";
		echo '        expected: ' . var_export($expected, true) . "\n";
		echo '        actual:   ' . var_export($actual, true) . "\n";
	}
}

final class PipelineResult {
	/** @var array<int,array<string,mixed>> */
	public $rows;

	public function __construct(array $rows) {
		$this->rows = $rows;
	}
}

final class PipelineFakeDriver implements DriverInterface {
	/** @var array<string,array{columns:string[],rows:array<int,array<string,mixed>>}> */
	public $tables = array();

	/** @var string[] */
	public $statements = array();

	public function query(string $sql) {
		$this->statements[] = $sql;

		if (preg_match('/^SELECT \* FROM `([^`]+)`/iu', trim($sql), $match) === 1) {
			$rows = isset($this->tables[ $match[1] ]['rows']) ? $this->tables[ $match[1] ]['rows'] : array();
			return new PipelineResult($rows);
		}

		return true;
	}

	public function fetch_assoc($result): ?array {
		if (! ($result instanceof PipelineResult) || $result->rows === array()) {
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
		return 0;
	}

	public function last_error(): string {
		return '';
	}

	public function close(): void {
	}
}

$tmp = sys_get_temp_dir() . '/mk-pipeline-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

$content = $tmp . '/content';
$uploads = $content . '/uploads';
$plugins = $content . '/plugins';
$themes  = $content . '/themes';

mkdir($content . '/languages', 0777, true);
mkdir($content . '/custom', 0777, true);
mkdir($uploads . '/2024/01', 0777, true);
mkdir($plugins . '/hello', 0777, true);
mkdir($themes . '/themeA', 0777, true);

file_put_contents($content . '/advanced-cache.php', "<?php // cache\n");
file_put_contents($content . '/languages/en_US.mo', 'translations');
file_put_contents($content . '/custom/thing.txt', 'custom content');
file_put_contents($uploads . '/2024/01/a.jpg', random_bytes(300000));
file_put_contents($plugins . '/hello/hello.php', "<?php // plugin\n");
file_put_contents($themes . '/themeA/style.css', "/* theme */\n");

$site = new SiteContext(
	array(
		'site_url'      => 'http://old.test',
		'home_url'      => 'http://old.test',
		'absolute_path' => $tmp . '/',
		'content_path'  => $content,
		'plugins_path'  => $plugins,
		'themes_path'   => $themes,
		'uploads_path'  => $uploads,
		'uploads_url'   => 'http://old.test/wp-content/uploads',
		'wp_version'    => '6.8',
		'db_version'    => '8.0.0',
		'table_prefix'  => 'wp_',
		'plugins'       => array( 'hello/hello.php' ),
		'themes'        => array( 'themeA' ),
		'template'      => 'themeA',
		'stylesheet'    => 'themeA',
		'server'        => array( '.htaccess' => "deny from all\n" ),
	)
);

$db = new PipelineFakeDriver();
$db->tables = array(
	'wp_options' => array(
		'columns' => array( 'option_id', 'option_name', 'option_value' ),
		'rows'    => array(
			array( 'option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'http://old.test' ),
			array( 'option_id' => 2, 'option_name' => 'blogname', 'option_value' => 'Old Site' ),
		),
	),
	'wp_posts'   => array(
		'columns' => array( 'ID', 'post_type', 'post_title' ),
		'rows'    => array(
			array( 'ID' => 1, 'post_type' => 'post', 'post_title' => 'Hello' ),
		),
	),
);

$archive = $tmp . '/export.wpress';
$storage = $tmp . '/storage';

$state = array(
	'archive_path' => $archive,
	'storage_dir'  => $storage,
	'options'      => array(
		'no_post_revisions' => true,
		'compression'       => '',
		'password'          => '',
	),
);

echo "MigrateKit — export pipeline suite\n\n";

echo "[1] Full export\n";
$pipeline = ExportPipeline::create();
$ctx      = new PipelineContext($db, $site, null, 0.0);
$done     = $pipeline->advance($state, $ctx);

check_pl('pipeline reports completion', $done);
check_pl('state marked done', ! empty($state['done']));
check_pl('archive file exists', is_file($archive));

$reader = new Reader($archive);
check_pl('archive is valid', $reader->is_valid());
check_pl('archive CRC verifies', $reader->verify_archive_crc());

$entries = $reader->entries();
$paths   = array_map(
	static function ($entry): string {
		return $entry->relative_path();
	},
	$entries
);

same_pl('first record is package.json', 'package.json', $paths[0]);
check_pl('content file archived', in_array('languages/en_US.mo', $paths, true));
check_pl('nested content archived', in_array('custom/thing.txt', $paths, true));
check_pl('media archived under uploads/', in_array('uploads/2024/01/a.jpg', $paths, true));
check_pl('plugin archived under plugins/', in_array('plugins/hello/hello.php', $paths, true));
check_pl('theme archived under themes/', in_array('themes/themeA/style.css', $paths, true));
check_pl('database.sql archived', in_array('database.sql', $paths, true));
check_pl('content stage did not duplicate uploads', 1 === count(array_keys($paths, 'uploads/2024/01/a.jpg', true)));

$package = json_decode($reader->read($reader->find('package.json')), true);
same_pl('manifest site URL', 'http://old.test', $package['SiteURL']);
same_pl('manifest plugin version', '0.0.3', $package['Plugin']['Version']);
same_pl('manifest no-post-revisions flag', true, $package['NoPostRevisions']);
same_pl('manifest database prefix', 'wp_', $package['Database']['Prefix']);
same_pl('manifest server htaccess encoded', base64_encode("deny from all\n"), $package['Server']['.htaccess']);

same_pl(
	'media content round-trips',
	hash_file('sha256', $uploads . '/2024/01/a.jpg'),
	hash('sha256', $reader->read($reader->find('uploads/2024/01/a.jpg')))
);
same_pl('content file round-trips', 'custom content', $reader->read($reader->find('custom/thing.txt')));

$sql = $reader->read($reader->find('database.sql'));
check_pl('database dump uses placeholder prefix', strpos($sql, 'SERVMASK_PREFIX_options') !== false);
check_pl('database dump contains option row', strpos($sql, 'Old Site') !== false);
check_pl('database dump contains posts insert', strpos($sql, 'INSERT INTO `SERVMASK_PREFIX_posts`') !== false);
check_pl('archive size recorded', ! empty($state['archive_size']));

// ---------------------------------------------------------------------------
echo "\n[2] Resumable writer across requests\n";
$big = $tmp . '/big.bin';
file_put_contents($big, random_bytes(1200000));
$resume_archive = $tmp . '/resume.wpress';

$offset = 0;
$written = 0;
$header = null;
$calls = 0;

while (true) {
	$writer = new Writer($resume_archive, null, null, $calls === 0);
	// Deadline of "now" forces at most one chunk per call.
	$completed = $writer->add_file_chunk('uploads/big.bin', $big, $offset, $written, 1700000000, $header, microtime(true));
	$writer->close(false);
	++$calls;

	if ($completed) {
		break;
	}

	if ($calls > 100) {
		break;
	}
}

check_pl('resume needed more than one call', $calls > 1);
same_pl('all source bytes consumed', filesize($big), $offset);

$final = new Writer($resume_archive, null, null, false);
$final->close(true);

$resume_reader = new Reader($resume_archive);
check_pl('resumed archive is valid', $resume_reader->is_valid());
same_pl(
	'resumed record content matches',
	hash_file('sha256', $big),
	hash('sha256', $resume_reader->read($resume_reader->find('uploads/big.bin')))
);
same_pl(
	'resumed record crc matches',
	hash('crc32b', (string) file_get_contents($big)),
	$resume_reader->find('uploads/big.bin')->crc()
);

// ---------------------------------------------------------------------------
echo "\n[3] Compressed + encrypted export\n";
$archive2 = $tmp . '/secure.wpress';
$state2 = array(
	'archive_path' => $archive2,
	'storage_dir'  => $tmp . '/storage2',
	'options'      => array(
		'compression' => 'gzip',
		'password'    => 'pipeline-secret',
	),
);

$pipeline2 = ExportPipeline::create();
$ctx2      = new PipelineContext($db, $site, null, 0.0);
check_pl('secure pipeline completes', $pipeline2->advance($state2, $ctx2));

$reader2 = new Reader($archive2, 'gzip', 'pipeline-secret');
check_pl('secure archive is valid', $reader2->is_valid());
check_pl('secure archive CRC verifies', $reader2->verify_archive_crc());
same_pl(
	'secure media round-trips',
	hash_file('sha256', $uploads . '/2024/01/a.jpg'),
	hash('sha256', $reader2->read($reader2->find('uploads/2024/01/a.jpg')))
);

$package2 = json_decode($reader2->read($reader2->find('package.json')), true);
same_pl('manifest records compression', 'gzip', $package2['Compression']['Type']);
same_pl('manifest records encryption', true, $package2['Encrypted']);
check_pl('manifest carries a signature', ! empty($package2['EncryptedSignature']));

// Wrong password fails.
$wrong = new Reader($archive2, 'gzip', 'nope');
$threw = false;
try {
	$wrong->read($wrong->find('uploads/2024/01/a.jpg'));
} catch (\RuntimeException $e) {
	$threw = true;
}
check_pl('wrong password fails to read', $threw);

// ---------------------------------------------------------------------------
echo "\n";
echo "passed: {$GLOBALS['pl_pass']}, failed: {$GLOBALS['pl_fail']}\n";
exit($GLOBALS['pl_fail'] === 0 ? 0 : 1);
