<?php
/**
 * End-to-end test for the reset pipeline: survey, content tables, options,
 * optional purges, media files, foreign-key fallback and resumability.
 *
 * Usage: php tests/run-reset.php
 *
 * @package MigrateKit
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MigrateKit\Database\Driver\DriverInterface;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Reset\ResetPipeline;
use MigrateKit\Site\SiteContext;

$GLOBALS['rs_pass'] = 0;
$GLOBALS['rs_fail'] = 0;

function check_rs(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['rs_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['rs_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function statement_hits(array $statements, string $needle): bool {
	foreach ($statements as $statement) {
		if (strpos((string) $statement, $needle) !== false) {
			return true;
		}
	}

	return false;
}

function contains_rs(string $label, array $statements, string $needle): void {
	check_rs($label, statement_hits($statements, $needle));
}

function missing_rs(string $label, array $statements, string $needle): void {
	check_rs($label, ! statement_hits($statements, $needle));
}

final class ResetRowResult {
	/** @var array<int,array<string,mixed>> */
	public $rows;

	public function __construct(array $rows) {
		$this->rows = $rows;
	}
}

final class ResetFakeDriver implements DriverInterface {
	/** @var array<string,int> */
	public $counts = array();

	/** @var array<string,array<int,array<string,mixed>>> */
	public $selects = array();

	/** @var string[] */
	public $statements = array();

	/** @var array<string,int> Exact statement match => errno. */
	public $failures = array();

	private $last_errno = 0;
	private $last_error = '';

	public function query(string $sql) {
		$this->statements[] = $sql;
		$this->last_errno   = 0;
		$this->last_error   = '';

		if (isset($this->failures[ $sql ])) {
			$this->last_errno = $this->failures[ $sql ];
			$this->last_error = 'simulated ' . $this->failures[ $sql ];

			return false;
		}

		if (preg_match('/^SELECT COUNT\(\*\) AS n FROM `([^`]+)`$/iu', trim($sql), $match) === 1
			&& isset($this->counts[ $match[1] ])) {
			return new ResetRowResult(array( array( 'n' => $this->counts[ $match[1] ] ) ));
		}

		if (preg_match('/^SELECT \* FROM `([^`]+)`/iu', trim($sql), $match) === 1
			&& isset($this->selects[ $match[1] ])) {
			return new ResetRowResult((array) $this->selects[ $match[1] ]);
		}

		return true;
	}

	public function fetch_assoc($result): ?array {
		if (! ($result instanceof ResetRowResult) || $result->rows === array()) {
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
		return array(
			'wp_comments',
			'wp_commentmeta',
			'wp_links',
			'wp_options',
			'wp_postmeta',
			'wp_posts',
			'wp_term_relationships',
			'wp_term_taxonomy',
			'wp_terms',
			'wp_termmeta',
			'wp_usermeta',
			'wp_users',
			'wp_blog_versions',
			'wp_yoast_graphql',
			'wp_wc_orders',
		);
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

$GLOBALS['rs_tmp'] = sys_get_temp_dir() . '/mk-reset-' . bin2hex(random_bytes(4));
mkdir($GLOBALS['rs_tmp'] . '/uploads/2024/01', 0777, true);
mkdir($GLOBALS['rs_tmp'] . '/uploads/in-depth/blog2', 0777, true);

file_put_contents($GLOBALS['rs_tmp'] . '/uploads/2024/01/a.jpg', 'binary-image');
file_put_contents($GLOBALS['rs_tmp'] . '/uploads/in-depth/blog2/b.png', 'binary-image-2');
file_put_contents($GLOBALS['rs_tmp'] . '/uploads/index.php', "<?php\n// Silence is golden.\n");

function rs_site(string $uploads): SiteContext {
	return new SiteContext(array(
		'table_prefix' => 'wp_',
		'uploads_path' => $uploads,
	));
}

function rs_run(array &$state, $db, string $uploads, float $budget = 0.0): bool {
	$static_pipeline = ResetPipeline::create();
	$static_ctx      = new PipelineContext($db, rs_site($uploads), null, $budget);

	return $static_pipeline->advance($state, $static_ctx);
}

// ---------------------------------------------------------------------------
echo "MigrateKit — reset pipeline suite\n\n";

echo "[1] Survey and content tables (defaults: users, media, third-party kept)\n";

$db = new ResetFakeDriver();
$db->counts = array_fill_keys(
	array(
		'wp_posts',
		'wp_postmeta',
		'wp_comments',
		'wp_commentmeta',
		'wp_links',
		'wp_term_relationships',
		'wp_term_taxonomy',
		'wp_terms',
		'wp_termmeta',
	),
	7
);

$state = array( 'options' => array() );
check_rs('pipeline completes', rs_run($state, $db, $GLOBALS['rs_tmp'] . '/uploads'));
check_rs('state marked done', ! empty($state['done']));

contains_rs('posts truncated', $db->statements, 'TRUNCATE TABLE `wp_posts`');
contains_rs('postmeta truncated', $db->statements, 'TRUNCATE TABLE `wp_postmeta`');
contains_rs('comments truncated', $db->statements, 'TRUNCATE TABLE `wp_comments`');
contains_rs('commentmeta truncated', $db->statements, 'TRUNCATE TABLE `wp_commentmeta`');
contains_rs('links truncated', $db->statements, 'TRUNCATE TABLE `wp_links`');
contains_rs('term_relationships truncated', $db->statements, 'TRUNCATE TABLE `wp_term_relationships`');
contains_rs('term_taxonomy truncated', $db->statements, 'TRUNCATE TABLE `wp_term_taxonomy`');
contains_rs('terms truncated', $db->statements, 'TRUNCATE TABLE `wp_terms`');
contains_rs('termmeta truncated', $db->statements, 'TRUNCATE TABLE `wp_termmeta`');

missing_rs('options table untouched', $db->statements, 'TRUNCATE TABLE `wp_options`');
missing_rs('users table untouched', $db->statements, 'TRUNCATE TABLE `wp_users`');
missing_rs('third-party table untouched', $db->statements, 'wp_yoast_graphql');
missing_rs('network table untouched', $db->statements, 'wp_blog_versions');

contains_rs('transient rows cleared', $db->statements, "LIKE '\\_transient\\_%'");
contains_rs('transient timeouts cleared', $db->statements, "LIKE '\\_transient\\_timeout\\_%'");
contains_rs('site transients cleared', $db->statements, "LIKE '\\_site\\_transient\\_%'");
contains_rs('rewrite rules dropped', $db->statements, "option_name = 'rewrite_rules'");

check_rs('summary recorded', ! empty($state['reset_summary']));
check_rs('summary added up the rows', strpos((string) $state['reset_summary'], '63 content rows') !== false);

echo "\n[2] Media files kept by default\n";
check_rs('media file still exists', is_file($GLOBALS['rs_tmp'] . '/uploads/2024/01/a.jpg'));
check_rs('nested media file still exists', is_file($GLOBALS['rs_tmp'] . '/uploads/in-depth/blog2/b.png'));

// ---------------------------------------------------------------------------
echo "\n[3] Full reset with media, users and third-party purge\n";

$db = new ResetFakeDriver();
$db->counts = array(
	'wp_posts'              => 12,
	'wp_postmeta'           => 40,
	'wp_comments'           => 30,
	'wp_commentmeta'        => 10,
	'wp_links'              => 2,
	'wp_term_relationships' => 50,
	'wp_term_taxonomy'      => 9,
	'wp_terms'              => 10,
	'wp_termmeta'           => 3,
);
$db->selects = array(
	'wp_usermeta' => array(
		array( 'user_id' => 42, 'meta_key' => 'wp_capabilities', 'meta_value' => 'a:1:{s:13:"administrator";b:1;}' ),
	),
);

$state = array(
	'options' => array(
		'delete_media_files' => true,
		'delete_users'       => true,
		'purge_third_party'  => true,
	),
	'user_id' => 7,
);
check_rs('full reset completes', rs_run($state, $db, $GLOBALS['rs_tmp'] . '/uploads'));

contains_rs('third-party table purged', $db->statements, 'TRUNCATE TABLE `wp_yoast_graphql`');
contains_rs('plugin commerce table purged', $db->statements, 'TRUNCATE TABLE `wp_wc_orders`');
contains_rs('user rows removed', $db->statements, 'DELETE FROM `wp_users`');
contains_rs('user meta removed', $db->statements, 'DELETE FROM `wp_usermeta`');

$keep_list = null;

foreach ($db->statements as $statement) {
	if (preg_match('/DELETE FROM `wp_users` WHERE ID NOT IN \(([0-9,]+)\)/', (string) $statement, $match) === 1) {
		$keep_list = $match[1];
		break;
	}
}

check_rs(
	'root user, active user and administrators survive',
	$keep_list !== null && in_array($keep_list, array( '1,7,42', '1,42,7', '1,7,24', '1,24,7' ), true)
);

check_rs('media file removed', ! is_file($GLOBALS['rs_tmp'] . '/uploads/2024/01/a.jpg'));
check_rs('nested media file removed', ! is_file($GLOBALS['rs_tmp'] . '/uploads/in-depth/blog2/b.png'));
check_rs('upload guard file removed', ! is_file($GLOBALS['rs_tmp'] . '/uploads/index.php'));

// ---------------------------------------------------------------------------
echo "\n[4] Foreign-key fallback\n";

$db3 = new ResetFakeDriver();
$db3->failures = array( 'TRUNCATE TABLE `wp_posts`' => 1701 );
$db3->counts   = array( 'wp_posts' => 5 );

$state3 = array( 'options' => array() );
check_rs('fallback completion', rs_run($state3, $db3, $GLOBALS['rs_tmp'] . '/uploads'));

contains_rs('delete fallback ran', $db3->statements, 'DELETE FROM `wp_posts`');
contains_rs('auto increment reset', $db3->statements, 'ALTER TABLE `wp_posts` AUTO_INCREMENT = 1');

// ---------------------------------------------------------------------------
echo "\n[5] Resume across requests\n";

$db4 = new ResetFakeDriver();

$state4 = array( 'options' => array() );

$done  = false;
$guard = 0;

do {
	$done = rs_run($state4, $db4, $GLOBALS['rs_tmp'] . '/uploads', 0.000001);

	if (++$guard > 200) {
		break;
	}
} while (! $done);

check_rs('resumed reset completes', $done);
check_rs('resume took more than one call', $guard > 1);
contains_rs('posts truncated exactly once', $db4->statements, 'TRUNCATE TABLE `wp_posts`');
check_rs(
	'no duplicate truncates across resume',
	count(array_keys($db4->statements, 'TRUNCATE TABLE `wp_posts`', true)) === 1
);

// ---------------------------------------------------------------------------
echo "\n";
printf("passed: %d, failed: %d\n", $GLOBALS['rs_pass'], $GLOBALS['rs_fail']);
exit($GLOBALS['rs_fail'] > 0 ? 1 : 0);
