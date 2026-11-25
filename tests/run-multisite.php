<?php
/**
 * Dependency-free test suite for the multisite layer.
 *
 * Usage: php tests/run-multisite.php
 *
 * @package MigrateKit
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MigrateKit\Archive\Reader;
use MigrateKit\Archive\Writer;
use MigrateKit\Database\Dumper;
use MigrateKit\Database\Driver\DriverInterface;
use MigrateKit\Database\Importer;
use MigrateKit\Export\ExportConfig;
use MigrateKit\Export\Stages\DatabaseStage;
use MigrateKit\Http\Options;
use MigrateKit\Import\ReplaceMatrixBuilder;
use MigrateKit\Import\Stages\ValidateStage;
use MigrateKit\Import\TableMappings;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Site\SiteContext;

$GLOBALS['ms_pass'] = 0;
$GLOBALS['ms_fail'] = 0;

function check_ms(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['ms_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['ms_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function same_ms(string $label, $expected, $actual): void {
	if ($expected === $actual) {
		$GLOBALS['ms_pass']++;
		echo "  ok  - {$label}\n";
		return;
	}

	$GLOBALS['ms_fail']++;
	echo "FAIL  - {$label}\n";
	echo '        expected: ' . var_export($expected, true) . "\n";
	echo '        actual:   ' . var_export($actual, true) . "\n";
}

/**
 * In-memory result handle.
 */
final class MtResult {

	/** @var array<int,array<string,mixed>> */
	public $rows;

	public function __construct(array $rows) {
		$this->rows = $rows;
	}
}

/**
 * In-memory driver.
 */
final class MtFakeDriver implements DriverInterface {

	/** @var array<string,array<string,mixed>> */
	public $tables = array();

	/** @var string[] */
	public $statements = array();

	public function query(string $sql) {
		$this->statements[] = $sql;

		if (preg_match('/^SELECT \* FROM `([^`]+)`(.*)$/iu', trim($sql), $match) === 1) {
			$table = $match[1];
			$rows  = isset($this->tables[ $table ]['rows']) ? $this->tables[ $table ]['rows'] : array();

			if (stripos($match[2], 'post_type') !== false) {
				$rows = array_values(array_filter($rows, static function (array $row): bool {
					return ! isset($row['post_type']) || $row['post_type'] !== 'revision';
				}));
			}

			if (stripos($match[2], 'comment_approved') !== false) {
				$rows = array_values(array_filter($rows, static function (array $row): bool {
					return ! isset($row['comment_approved']) || $row['comment_approved'] !== 'spam';
				}));
			}

			// The dumper pages through rows; honour the page it asked for.
			if (preg_match('/\bLIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?$/i', $match[2], $limit) === 1) {
				$rows = array_slice($rows, isset($limit[2]) && $limit[2] !== '' ? (int) $limit[2] : 0, (int) $limit[1]);
			}

			return new MtResult($rows);
		}

		return true;
	}

	public function fetch_assoc($result): ?array {
		if (! ($result instanceof MtResult) || $result->rows === array()) {
			return null;
		}

		return array_shift($result->rows);
	}

	public function free_result($result): void {
	}

	public function escape(string $value): string {
		return $value;
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

/**
 * Seed tables covering a three-site simulation.
 *
 * @return array<string,array<string,mixed>>
 */
function mt_seed_tables(): array {
	return array(
		'wp_posts'          => array( 'rows' => array( array( 'ID' => 1, 'post_type' => 'post' ), array( 'ID' => 2, 'post_type' => 'revision' ) ) ),
		'wp_options'        => array( 'rows' => array( array( 'option_name' => 'blogname', 'option_value' => 'Main' ) ) ),
		'wp_users'          => array( 'rows' => array( array( 'ID' => 1, 'user_login' => 'admin' ) ) ),
		'wp_usermeta'       => array( 'rows' => array( array( 'user_id' => 1, 'meta_key' => 'rich_editing' ) ) ),
		'wp_2_posts'        => array( 'rows' => array( array( 'ID' => 11, 'post_type' => 'post' ), array( 'ID' => 12, 'post_type' => 'revision' ) ) ),
		'wp_2_options'      => array( 'rows' => array( array( 'option_name' => 'blogname', 'option_value' => 'Sub' ) ) ),
		'wp_2_custom'       => array( 'rows' => array( array( 'k' => 'x' ) ) ),
		'wp_3_posts'        => array( 'rows' => array( array( 'ID' => 21, 'post_type' => 'post' ) ) ),
		'otherplugin_table' => array( 'rows' => array( array( 'q' => 1 ) ) ),
	);
}

// ---------------------------------------------------------------------------
echo "[1] Dumper table selection and placeholder mapping\n";

$driver = new MtFakeDriver();
$driver->tables = mt_seed_tables();

$sql_all = '';
(new Dumper($driver, 'wp_'))->dump(static function (string $chunk) use (&$sql_all): void {
	$sql_all .= $chunk;
});

check_ms('unscooped dump includes every table', strpos($sql_all, '`otherplugin_table`') !== false && strpos($sql_all, '`SERVMASK_PREFIX_2_options`') !== false);
check_ms('subsite tables keep the numeric segment in placeholder form', strpos($sql_all, 'CREATE TABLE `SERVMASK_PREFIX_2_posts`') !== false);

$driver = new MtFakeDriver();
$driver->tables = mt_seed_tables();

$sql_network = '';
(new Dumper($driver, 'wp_'))->dump(static function (string $chunk) use (&$sql_network): void {
	$sql_network .= $chunk;
}, array(
	'table_scope'       => array( 'wp_', 'wp_2_', 'wp_3_' ),
	'no_post_revisions' => true,
	'no_spam_comments'  => true,
));

check_ms('scoped dump drops foreign prefixes', strpos($sql_network, 'otherplugin_table') === false);
check_ms('scoped dump keeps base tables', strpos($sql_network, '`SERVMASK_PREFIX_posts`') !== false && strpos($sql_network, '`SERVMASK_PREFIX_users`') !== false);
check_ms('scoped dump keeps every requested subsite', strpos($sql_network, '`SERVMASK_PREFIX_3_posts`') !== false);
check_ms('revision filter also hits subsite posts tables', strpos($sql_network, 'VALUES (2,\'revision\')') === false && strpos($sql_network, 'VALUES (12,\'revision\')') === false);

$driver = new MtFakeDriver();
$driver->tables = mt_seed_tables();

$sql_subsite = '';
(new Dumper($driver, 'wp_'))->dump(static function (string $chunk) use (&$sql_subsite): void {
	$sql_subsite .= $chunk;
}, array(
	'table_scope'  => array( 'wp_2_' ),
	'extra_tables' => array( 'wp_users', 'wp_usermeta' ),
));

check_ms('subsite scope drops the main site tables', strpos($sql_subsite, '`wp_posts`') === false && strpos($sql_subsite, '`SERVMASK_PREFIX_options`') === false);
check_ms('subsite scope keeps subsite and extra tables', strpos($sql_subsite, '`SERVMASK_PREFIX_2_posts`') !== false && strpos($sql_subsite, '`SERVMASK_PREFIX_users`') !== false && strpos($sql_subsite, '`SERVMASK_PREFIX_2_custom`') !== false);

// ---------------------------------------------------------------------------
echo "\n[2] Export manifest network blocks\n";

/**
 * @return array<string,mixed>
 */
function mt_source_context(): array {
	return array(
		'site_url'        => 'https://src.test',
		'home_url'        => 'https://src.test',
		'absolute_path'   => '/srv/www/wordpress/',
		'content_path'    => '/srv/www/wordpress/content/',
		'plugins_path'    => '/srv/www/wordpress/content/plugins/',
		'themes_path'     => '/srv/www/wordpress/content/themes/',
		'uploads_path'    => '/srv/www/wordpress/content/uploads/',
		'uploads_url'     => 'https://src.test/content/uploads/',
		'table_prefix'    => 'wp_',
		'base_prefix'     => 'wp_',
		'wp_version'      => '6.9',
		'is_multisite'    => true,
		'current_blog_id' => 1,
		'network_sites'   => array(
			array(
				'blog_id'      => 1,
				'is_main'      => true,
				'site_url'     => 'https://src.test',
				'home_url'     => 'https://src.test',
				'table_prefix' => 'wp_',
				'domain'       => 'src.test',
				'path'         => '/',
				'uploads_path' => '/srv/www/wordpress/content/uploads/',
				'uploads_url'  => 'https://src.test/content/uploads/',
			),
			array(
				'blog_id'      => 3,
				'is_main'      => false,
				'site_url'     => 'https://src.test/site3',
				'home_url'     => 'https://src.test/site3',
				'table_prefix' => 'wp_3_',
				'domain'       => 'src.test',
				'path'         => '/site3/',
				'uploads_path' => '/srv/www/wordpress/content/uploads/sites/3/',
				'uploads_url'  => 'https://src.test/content/uploads/sites/3/',
			),
			array(
				'blog_id'      => 9,
				'is_main'      => false,
				'site_url'     => 'https://store.alt-source.test',
				'home_url'     => 'https://store.alt-source.test',
				'table_prefix' => 'wp_9_',
				'domain'       => 'store.alt-source.test',
				'path'         => '/',
				'uploads_path' => '/srv/www/wordpress/content/uploads/',
				'uploads_url'  => 'https://store.alt-source.test/content/uploads/',
			),
		),
	);
}

$network_site = new SiteContext(mt_source_context());
$manifest     = ExportConfig::build($network_site, array( 'network' => 'whole' ));

check_ms('network manifest declares the network', isset($manifest['Network']) && isset($manifest['Sites']));
same_ms('network mode reads subdirectory when a subsite uses a path', 'subdirectory', $manifest['Network']['Mode']);
same_ms('main blog id recorded', 1, $manifest['Network']['MainBlogId']);
same_ms('every site listed', 3, count($manifest['Sites']));
same_ms('subsite entry carries its prefix and upload paths', array( 'wp_3_', '/srv/www/wordpress/content/uploads/sites/3/' ), array( $manifest['Sites'][1]['Prefix'], $manifest['Sites'][1]['Uploads'] ));
same_ms('subdomain subsite recognised', 'store.alt-source.test', $manifest['Sites'][2]['Domain']);

$plain = ExportConfig::build($network_site);
check_ms('non-network manifest stays single-site shaped', ! isset($plain['Network']) && ! isset($plain['Sites']));

$subsite_data                 = mt_source_context();
$subsite_data['site_url']     = 'https://src.test/site3';
$subsite_data['home_url']     = 'https://src.test/site3';
$subsite_data['table_prefix'] = 'wp_3_';
$subsite_data['current_blog_id'] = 3;
$subsite_data['uploads_path'] = '/srv/www/wordpress/content/uploads/sites/3/';
$subsite_data['uploads_url']  = 'https://src.test/content/uploads/sites/3/';

$subsite_site      = new SiteContext($subsite_data);
$subsite_manifest  = ExportConfig::build($subsite_site, array( 'network' => 'subsite' ));

check_ms('subsite export manifest stays single shaped', ! isset($subsite_manifest['Network']) && ! isset($subsite_manifest['Sites']));
same_ms('subsite manifest carries the subsite identity', 'https://src.test/site3', $subsite_manifest['SiteURL']);
same_ms('subsite manifest prefix is the subsite prefix', 'wp_3_', $subsite_manifest['Database']['Prefix']);

// ---------------------------------------------------------------------------
echo "\n[3] Database stage scope resolution\n";

$reflection    = new ReflectionClass(DatabaseStage::class);
$scope_method  = $reflection->getMethod('table_scope');
$scope_method->setAccessible(true);
$extra_method = $reflection->getMethod('extra_tables');
$extra_method->setAccessible(true);

$state = array( 'options' => array( 'network' => 'whole' ) );
same_ms('whole network scope lists every prefix', array( 'wp_', 'wp_3_', 'wp_9_' ), $scope_method->invoke(null, $subsite_site, $state));

$state = array( 'options' => array( 'network' => 'subsite' ) );
same_ms('subsite scope lists the subsite prefix only', array( 'wp_3_' ), $scope_method->invoke(null, $subsite_site, $state));
same_ms('subsite extras are the shared user tables', array( 'wp_users', 'wp_usermeta' ), $extra_method->invoke(null, $subsite_site, $state));

$dry = new SiteContext(array( 'site_url' => 'https://single.test', 'table_prefix' => 'wp_' ));
same_ms('plain installs ignore the network option', array(), $scope_method->invoke(null, $dry, $state));

// ---------------------------------------------------------------------------
echo "\n[4] Option normalisation\n";

same_ms('unknown network value is dropped', '', Options::export(array( 'network' => 'x' ))['network']);
same_ms('whole network option kept', 'whole', Options::export(array( 'network' => 'whole' ))['network']);
same_ms('subsite option kept', 'subsite', Options::export(array( 'network' => 'subsite' ))['network']);
same_ms('absent network option is empty', '', Options::export(array())['network']);

// ---------------------------------------------------------------------------
echo "\n[5] Import mapping and replacement\n";

$engine = ReplaceMatrixBuilder::build(
	array(
		'SiteURL'    => 'https://src.test',
		'HomeURL'    => 'https://src.test',
		'Network'    => array( 'Mode' => 'subdirectory', 'MainBlogId' => 1 ),
		'Sites'      => array(
			array( 'BlogId' => 1, 'IsMain' => true, 'SiteURL' => 'https://src.test', 'Path' => '/' ),
			array( 'BlogId' => 3, 'IsMain' => false, 'SiteURL' => 'https://src.test/site3', 'Path' => '/site3/', 'UploadsURL' => 'https://src.test/content/uploads/sites/3/' ),
			array( 'BlogId' => 9, 'IsMain' => false, 'SiteURL' => 'https://store.alt-source.test', 'Path' => '/', 'UploadsURL' => 'https://store.alt-source.test/content/uploads/' ),
			array( 'BlogId' => 21, 'IsMain' => false, 'SiteURL' => 'https://beta.src.test', 'Path' => '/', 'UploadsURL' => 'https://beta.src.test/content/uploads/' ),
		),
		'UploadsURL' => 'https://src.test/content/uploads/',
		'Uploads'    => '/srv/www/wordpress/content/uploads/',
	),
	new SiteContext(array(
		'site_url'     => 'http://dest.test',
		'home_url'     => 'http://dest.test',
		'uploads_url'  => 'http://dest.test/wp-content/uploads/',
		'uploads_path' => '/srv/dest/uploads/',
	))
);;

$search = $engine->map();

same_ms('subdirectory subsite maps under the destination root', 'http://dest.test/site3', $search['https://src.test/site3'] ?? '');
same_ms('main mapping untouched by subsite pairs', 'http://dest.test', $search['https://src.test'] ?? '');
same_ms('encoded variant also replaced', 'http%3A%2F%2Fdest.test%2Fsite3', $search['https%3A%2F%2Fsrc.test%2Fsite3'] ?? '');
check_ms('domain-mapped subsite earns no derived pair', ! isset($search['https://store.alt-source.test']) && ! isset($search['https://beta.src.test']));
check_ms('subdirectory subsite uploads move into uploads/sites/{id}', ($search['https://src.test/content/uploads/sites/3/'] ?? '') === 'http://dest.test/wp-content/uploads/sites/3/');

$subdomain_engine = ReplaceMatrixBuilder::build(
	array(
		'SiteURL' => 'https://src.test',
		'Network' => array( 'Mode' => 'subdomain' ),
		'Sites'   => array(
			array( 'BlogId' => 1, 'IsMain' => true, 'SiteURL' => 'https://src.test', 'Path' => '/' ),
			array( 'BlogId' => 21, 'IsMain' => false, 'SiteURL' => 'https://beta.src.test', 'Path' => '/', 'UploadsURL' => 'https://beta.src.test/wp-content/uploads/' ),
array( 'BlogId' => 9, 'IsMain' => false, 'SiteURL' => 'https://store.alt-source.test', 'Path' => '/' ),
		),
	),
	new SiteContext(array(
		'site_url'    => 'http://dest.test',
		'uploads_url' => 'http://dest.test/wp-content/uploads/',
	))
);

$subdomain_search = $subdomain_engine->map();

same_ms('subdomain subsite maps onto the destination host', 'http://beta.dest.test', $subdomain_search['https://beta.src.test'] ?? '');
same_ms('subdomain uploads keep the uploads base path', 'http://beta.dest.test/wp-content/uploads/', $subdomain_search['https://beta.src.test/wp-content/uploads/'] ?? '');
check_ms('subdomain domain-mapped subsite earns no pair', ! isset($subdomain_search['https://store.alt-source.test']));

/**
 * Multisite table placeholders land on numbered destination prefixes.
 */
$mount      = new MtFakeDriver();
$mount->tables = array(
	'wp_posts'   => array( 'rows' => array( array( 'ID' => 1 ) ) ),
	'wp_2_posts' => array( 'rows' => array( array( 'ID' => 7 ) ) ),
);

$importer = new Importer($mount, 'dst_');
$importer->add_mappings(array(
	array( 'placeholder' => 'SERVMASK_PREFIX_2_', 'real' => 'dst_2_' ),
));
$importer->import_sql(
	"CREATE TABLE `SERVMASK_PREFIX_posts` (id int);\n"
	. "INSERT INTO `SERVMASK_PREFIX_posts` VALUES (1);\n"
	. "CREATE TABLE `SERVMASK_PREFIX_2_posts` (id int);\n"
	. "INSERT INTO `SERVMASK_PREFIX_2_posts` VALUES (7);\n"
);

$creates = array_values(array_filter($mount->statements, static function (string $sql): bool {
	return stripos($sql, 'CREATE TABLE') === 0;
}));

same_ms('bare placeholder lands on the destination prefix', 'CREATE TABLE `dst_posts` (id int)', $creates[0] ?? null);
same_ms('subsite placeholder lands on the numbered destination prefix', 'CREATE TABLE `dst_2_posts` (id int)', $creates[1] ?? null);
check_ms('numbered tables find their rows', in_array('INSERT INTO `dst_2_posts` VALUES (7)', array_map(static function (string $sql): string {
	return trim($sql, "; \n");
}, $mount->statements), true));

// ---------------------------------------------------------------------------
echo "\n[5b] Placeholder mapping derivation\n";

$mapping_site = new SiteContext(array(
	'site_url'     => 'http://dest.test',
	'table_prefix' => 'dst_',
	'base_prefix'  => 'dst_',
));

same_ms(
	'plain archive derives no mappings',
	array(),
	TableMappings::build(array( 'Database' => array( 'Prefix' => 'wp_' ) ), $mapping_site)
);

same_ms(
	'network archive maps every source subsite',
	array(
		array( 'placeholder' => 'SERVMASK_PREFIX_3_', 'real' => 'dst_3_' ),
		array( 'placeholder' => 'SERVMASK_PREFIX_7_', 'real' => 'dst_7_' ),
	),
	TableMappings::build(array(
		'Database' => array( 'Prefix' => 'wp_' ),
		'Sites'    => array(
			array( 'BlogId' => 1, 'IsMain' => true ),
			array( 'BlogId' => 3, 'IsMain' => false, 'Prefix' => 'wp_3_' ),
			array( 'BlogId' => 7, 'IsMain' => false, 'Prefix' => 'wp_7_' ),
		),
	), $mapping_site)
);

same_ms(
	'subsite archive folds onto a plain site prefix',
	array( array( 'placeholder' => 'SERVMASK_PREFIX_3_', 'real' => 'dst_' ) ),
	TableMappings::build(array( 'Database' => array( 'Prefix' => 'wp_3_' ) ), $mapping_site)
);

$network_target = new SiteContext(array(
	'site_url'     => 'http://dest.test',
	'table_prefix' => 'dst_',
	'base_prefix'  => 'dst_',
	'is_multisite' => true,
));

same_ms(
	'subsite archive folds onto the network plain prefix',
	array( array( 'placeholder' => 'SERVMASK_PREFIX_3_', 'real' => 'dst_' ) ),
	TableMappings::build(array( 'Database' => array( 'Prefix' => 'wp_3_' ) ), $network_target)
);

// ---------------------------------------------------------------------------
echo "\n[6] Network archive guard on single sites\n";

$tmp = sys_get_temp_dir() . '/mk-ms-' . bin2hex(random_bytes(4));
mkdir($tmp . '/arc', 0777, true);
mkdir($tmp . '/content', 0777, true);

if (! defined('WP_CONTENT_DIR')) {
	define('WP_CONTENT_DIR', $tmp . '/content');
}

$writer = new Writer($tmp . '/arc/network.wpress', null, null, false);
$writer->add_contents('package.json', (string) json_encode(array(
	'SiteURL' => 'https://src.test',
	'Network' => array( 'Mode' => 'subdirectory' ),
	'Sites'   => array(
		array( 'BlogId' => 1, 'IsMain' => true, 'SiteURL' => 'https://src.test', 'Path' => '/' ),
	),
)), time());
$writer->close(true);

$validate   = new ValidateStage();
$work_state = array( 'archive_path' => $tmp . '/arc/network.wpress', 'options' => array() );

$single_ctx = new PipelineContext(null, new SiteContext(array( 'is_multisite' => false )), null, 0.0);

try {
	$validate->run($work_state, $single_ctx);
	check_ms('network archive rejected on single site', false);
} catch (\RuntimeException $caught) {
	check_ms('network archive rejected on single site', strpos($caught->getMessage(), 'multisite network') !== false);
}

$network_ctx = new PipelineContext(null, new SiteContext(array(
	'is_multisite'    => true,
	'site_url'        => 'http://dest.test',
	'table_prefix'    => 'dst_',
	'base_prefix'     => 'dst_',
	'current_blog_id' => 1,
	'network_sites'   => array(
		array( 'blog_id' => 1, 'is_main' => true, 'site_url' => 'http://dest.test', 'table_prefix' => 'dst_' ),
	),
)), null, 0.0);

$validated = $validate->run($work_state, $network_ctx);

check_ms('network archive accepted on networks', $validated === true && isset($work_state['package']['Network']));
check_ms('package manifest carried into job state', ($work_state['package']['Sites'][0]['BlogId'] ?? 0) === 1);

$reading = new Reader($tmp . '/arc/network.wpress');
check_ms('fixture archive itself is readable', $reading->is_valid());

$sub_writer = new Writer($tmp . '/arc/subsite.wpress', null, null, false);
$sub_writer->add_contents('package.json', (string) json_encode(array(
	'SiteURL'  => 'https://src.test/site3',
	'Database' => array( 'Prefix' => 'wp_3_' ),
)), time());
$sub_writer->close(true);

$sub_state = array( 'archive_path' => $tmp . '/arc/subsite.wpress', 'options' => array() );

try {
	$validate->run($sub_state, $network_ctx);
	check_ms('subsite archive rejected on networks', false);
} catch (\RuntimeException $caught) {
	check_ms('subsite archive rejected on networks', strpos($caught->getMessage(), 'standalone WordPress site') !== false);
}

$plain_state = $sub_state;

try {
	$accepted = $validate->run($plain_state, $single_ctx);
	check_ms('subsite archive accepted on plain sites', $accepted === true && ($plain_state['package']['Database']['Prefix'] ?? '') === 'wp_3_');
} catch (\RuntimeException $caught) {
	check_ms('subsite archive accepted on plain sites (unexpected: ' . $caught->getMessage() . ')', false);
}

$main_only_writer = new Writer($tmp . '/arc/main.wpress', null, null, false);
$main_only_writer->add_contents('package.json', (string) json_encode(array(
	'SiteURL'  => 'https://other.test',
	'Database' => array( 'Prefix' => 'ok_' ),
)), time());
$main_only_writer->close(true);

$main_state = array( 'archive_path' => $tmp . '/arc/main.wpress', 'options' => array() );

try {
	$plain_ok = $validate->run($main_state, $network_ctx);
	check_ms('plain archives still restore onto networks', $plain_ok === true);
} catch (\RuntimeException $caught) {
	check_ms('plain archives still restore onto networks (unexpected: ' . $caught->getMessage() . ')', false);
}

// ---------------------------------------------------------------------------

echo "\n";
printf("passed: %d, failed: %d\n", $GLOBALS['ms_pass'], $GLOBALS['ms_fail']);
exit($GLOBALS['ms_fail'] > 0 ? 1 : 0);
