<?php
/**
 * Runs inside WordPress via `wp eval-file`, once per role, to smoke-test
 * moving a site to another address on a host that took link() away: what
 * becomes of the links in a backup of https://wpmhs.com when a site at
 * https://devsroom.com fetches it from the cloud and restores it in one go.
 *
 * Before each run give the site that role's address, so that WordPress has
 * booted with it:
 *
 *   wp eval 'update_option("siteurl","https://wpmhs.com"); update_option("home","https://wpmhs.com");'
 *   MK_ROLE=source wp eval-file tools/wp-domain-smoke.php --allow-root
 *   wp eval 'update_option("siteurl","https://devsroom.com"); update_option("home","https://devsroom.com");'
 *   MK_ROLE=target wp eval-file tools/wp-domain-smoke.php --allow-root
 *
 * Set MK_CLOUD_ENDPOINT (e.g. http://minio:9000) plus MK_CLOUD_ACCESS_KEY,
 * MK_CLOUD_SECRET_KEY and MK_CLOUD_BUCKET for a live S3-compatible server.
 * Take link() away (disable_functions=link) to play the host on which the
 * restore failed with "Call to undefined function MigrateKit\Job\link()".
 *
 * @package MigrateKit
 */

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run this file through wp eval-file.\n");
	exit(1);
}

$domain_role = (string) getenv('MK_ROLE');

if ($domain_role !== 'source' && $domain_role !== 'target') {
	fwrite(STDERR, "Set MK_ROLE to source or target.\n");
	exit(1);
}

const DOMAIN_SOURCE = 'https://wpmhs.com';
const DOMAIN_TARGET = 'https://devsroom.com';

/** In the web root, outside wp-content: the backup only carries wp-content, so it cannot bring this from one role to the other. */
const DOMAIN_STATE = '/var/www/html/mk-domain-source.json';

const DOMAIN_PARAGRAPH = '<!-- wp:paragraph -->
<p>Plain <a href="https://wpmhs.com/about/">About</a>, insecure <a href="http://wpmhs.com/shop/">Shop</a>, relative <a href="//wpmhs.com/guide/">Guide</a>, mail <a href="mailto:hello@wpmhs.com">hello@wpmhs.com</a>.</p>
<!-- /wp:paragraph -->';

const DOMAIN_IMAGE = '<!-- wp:image {"id":123,"url":"https:\/\/wpmhs.com\/wp-content\/uploads\/2026\/11\/pic.jpg"} -->
<figure class="wp-block-image"><img src="https://wpmhs.com/wp-content/uploads/2026/11/pic.jpg" alt=""/></figure>
<!-- /wp:image -->';

const DOMAIN_WIDGET = '<!-- wp:paragraph -->
<p>Widget <a href="https://wpmhs.com/widget/">link</a>, image https://wpmhs.com/wp-content/uploads/widget.jpg</p>
<!-- /wp:paragraph -->';

/** What the source stores in post meta, and what the target is to find there. */
const DOMAIN_PROBES = array(
	'mk_serialized' => array(
		'site'    => 'https://wpmhs.com',
		'nested'  => array( '//wpmhs.com/a', 'http://wpmhs.com/b', 'https:\/\/wpmhs.com\/c' ),
		'email'   => 'editor@wpmhs.com',
		'unicode' => 'Grüße von https://wpmhs.com/grüße',
		'none'    => 'no address here',
	),
	'mk_json'    => '{"url":"https:\/\/wpmhs.com\/x","img":"https:\/\/wpmhs.com\/wp-content\/uploads\/p.png"}',
	'mk_b64'     => 'see https://wpmhs.com/b64',
	'mk_encoded' => 'https%3A%2F%2Fwpmhs.com%2Fencoded',
	'mk_raw'     => 'https://wpmhs.com/raw',
	'mk_upper'   => 'HTTPS://WPMHS.COM/upper',
	'mk_www'     => 'https://www.wpmhs.com/www',
	'mk_sub'     => 'https://shop.wpmhs.com/sub',
	'mk_bare'    => 'wpmhs.com',
);

const DOMAIN_EXPECTED = array(
	'mk_serialized' => array(
		'site'    => 'https://devsroom.com',
		'nested'  => array( '//devsroom.com/a', 'https://devsroom.com/b', 'https:\/\/devsroom.com\/c' ),
		'email'   => 'editor@devsroom.com',
		'unicode' => 'Grüße von https://devsroom.com/grüße',
		'none'    => 'no address here',
	),
	'mk_json'    => '{"url":"https:\/\/devsroom.com\/x","img":"https:\/\/devsroom.com\/wp-content\/uploads\/p.png"}',
	'mk_b64'     => 'see https://devsroom.com/b64',
	'mk_encoded' => 'https%3A%2F%2Fdevsroom.com%2Fencoded',
	'mk_raw'     => 'https://devsroom.com/raw',
	'mk_upper'   => 'HTTPS://WPMHS.COM/upper',
	'mk_www'     => 'https://www.wpmhs.com/www',
	'mk_sub'     => 'https://shop.wpmhs.com/sub',
	'mk_bare'    => 'wpmhs.com',
);

const DOMAIN_PROBE_LABELS = array(
	'mk_serialized' => 'a serialized value keeps its structure and takes the new address',
	'mk_json'       => 'links escaped the way JSON, block and page-builder data hold them take the new address',
	'mk_b64'        => 'a link inside base64-encoded data takes the new address',
	'mk_encoded'    => 'a URL-encoded link takes the new address',
	'mk_raw'        => 'a plain link takes the new address',
	'mk_upper'      => 'an address written in capitals is left as it is',
	'mk_www'        => 'a www. address is left as it is',
	'mk_sub'        => 'a subdomain is left as it is',
	'mk_bare'       => 'the bare domain, with no scheme, // or @ before it, is left as it is',
);

$GLOBALS['domain_pass'] = 0;
$GLOBALS['domain_fail'] = 0;

function domain_ok(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['domain_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['domain_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function domain_same(string $label, $expected, $actual): void {
	if ($expected === $actual) {
		domain_ok($label, true);
		return;
	}

	domain_ok($label, false);
	echo '        expected: ' . var_export($expected, true) . "\n";
	echo '        actual:   ' . var_export($actual, true) . "\n";
}

/** What a link becomes, worked out here in the plainest way there is, for the plugin's result to be held against. */
function domain_moved(string $text): string {
	return str_replace(
		array( 'https:\/\/wpmhs.com', 'https://wpmhs.com', 'http://wpmhs.com', '//wpmhs.com', '@wpmhs.com' ),
		array( 'https:\/\/devsroom.com', 'https://devsroom.com', 'https://devsroom.com', '//devsroom.com', '@devsroom.com' ),
		$text
	);
}

function domain_remember(string $key, $value): void {
	$file = is_file(DOMAIN_STATE) ? json_decode((string) file_get_contents(DOMAIN_STATE), true) : array();

	file_put_contents(DOMAIN_STATE, wp_json_encode(array_merge(is_array($file) ? $file : array(), array( $key => $value ))));
}

function domain_recalled(string $key) {
	$file = is_file(DOMAIN_STATE) ? json_decode((string) file_get_contents(DOMAIN_STATE), true) : array();

	return is_array($file) && array_key_exists($key, $file) ? $file[ $key ] : null;
}

/** Thrown in place of the exit an AJAX reply ends with, so one process can play many page loads. */
final class DomainReplySent extends \Exception {
}

function domain_reply_sent($message, $title = '', $args = array()): void {
	throw new DomainReplySent();
}

/** What the filter for the handler of a wp_die() is given, and what it answers: the name of the handler above. */
function domain_die_handler($handler): string {
	return 'domain_reply_sent';
}

/** What a handler answers after catching the ending thrown in place of its exit: an artefact of this harness, not a reply. */
const DOMAIN_AFTERMATH = '{"success":false,"data":{"message":""}}';

/**
 * The first JSON document in what a handler printed: its reply.
 *
 * @param string $raw Everything the handler printed.
 * @return array {success: bool, data: array}
 */
function domain_reply(string $raw): array {
	$length    = strlen($raw);
	$depth     = 0;
	$in_string = false;
	$escaped   = false;
	$end       = 0;

	for ($i = 0; $i < $length; $i++) {
		$char = $raw[ $i ];

		if ($in_string) {
			if ($escaped) {
				$escaped = false;
			} elseif ($char === '\\') {
				$escaped = true;
			} elseif ($char === '"') {
				$in_string = false;
			}

			continue;
		}

		if ($char === '"') {
			$in_string = true;
		} elseif ($char === '{' || $char === '[') {
			$depth++;
		} elseif ($char === '}' || $char === ']') {
			$depth--;

			if ($depth === 0) {
				$end = $i + 1;
				break;
			}
		}
	}

	if ($end === 0) {
		return array( 'success' => false, 'data' => array( 'message' => 'The handler printed no JSON: ' . substr($raw, 0, 200) ) );
	}

	$after = trim(substr($raw, $end));

	if ($after !== '' && $after !== DOMAIN_AFTERMATH) {
		echo "        the handler went on after its reply: {$after}\n";
	}

	$json = json_decode(substr($raw, 0, $end), true);

	if (! is_array($json)) {
		return array( 'success' => false, 'data' => array( 'message' => 'The handler printed no JSON: ' . substr($raw, 0, 200) ) );
	}

	if (! isset($json['data']) || ! is_array($json['data'])) {
		$json['data'] = array();
	}

	return $json;
}

/**
 * Call one admin-AJAX handler as admin-ajax.php would and return its JSON reply.
 *
 * @param string $action  Action name without the wp_ajax_ prefix.
 * @param array  $payload What the page posted.
 * @return array {success: bool, data: array}
 */
function domain_ajax(string $action, array $payload = array()): array {
	$_POST    = array_merge(array( 'action' => $action, 'nonce' => wp_create_nonce('migratekit_ajax'), 'secret' => '' ), $payload);
	$_REQUEST = $_POST;

	ob_start();

	try {
		do_action('wp_ajax_' . $action);
	} catch (DomainReplySent $sent) {
		// The reply was sent: that is the handler ending, as it would end a request.
	}

	return domain_reply((string) ob_get_clean());
}

/**
 * Send step requests until the job ends or a step fails, and return that last reply as it came.
 *
 * @param string $action Step handler.
 * @param string $job_id Job identifier.
 * @return array The last reply.
 */
function domain_drive(string $action, string $job_id): array {
	$reply = array();

	for ($i = 0; $i < 600; $i++) {
		$reply = domain_ajax($action, array( 'job_id' => $job_id ));

		if (empty($reply['success']) || ! empty($reply['data']['done'])) {
			if (empty($reply['success'])) {
				echo '        a step failed: ' . (string) ( $reply['data']['message'] ?? '(no reason given)' ) . "\n";
			}

			return $reply;
		}
	}

	echo "        the job did not end in time: {$job_id}\n";

	return $reply;
}

/** A slice so short that a stage ends its request, as it does on a site too large for one. */
function domain_short_steps($seconds): float {
	return 0.001;
}

/** A value straight from the database, past whatever this process still has in its caches. */
function domain_stored(string $sql): string {
	global $wpdb;

	return (string) $wpdb->get_var($sql);
}

function domain_backup_path(string $name): string {
	return rtrim(\MigrateKit\Support\Paths::backups(), '/\\') . DIRECTORY_SEPARATOR . $name;
}

/**
 * Continue a restore by its step handler until it ends or a step fails, in slices short enough for a
 * small site to be cut across requests as a large one is.
 *
 * @param string $job_id Job identifier.
 * @return array The last reply.
 */
function domain_restore(string $job_id): array {
	add_filter('migratekit_step_seconds', 'domain_short_steps');

	$GLOBALS['domain_steps'] = 0;

	for ($i = 0; $i < 600; $i++) {
		$reply = domain_ajax('migratekit_import_step', array( 'job_id' => $job_id ));
		$GLOBALS['domain_steps']++;

		if (empty($reply['success']) || ! empty($reply['data']['done'])) {
			if (empty($reply['success'])) {
				echo '        a restore step failed: ' . (string) ( $reply['data']['message'] ?? '(no reason given)' ) . "\n";
			}

			break;
		}
	}

	remove_filter('migratekit_step_seconds', 'domain_short_steps');

	return $reply;
}

wp_set_current_user(1);

if (! defined('DOING_AJAX')) {
	define('DOING_AJAX', true);
}

add_filter('wp_die_ajax_handler', 'domain_die_handler', PHP_INT_MAX);

// The plugin wires its AJAX handlers for admin requests only, and this is not one.
\MigrateKit\Http\AjaxController::register();

$domain_endpoint = (string) getenv('MK_CLOUD_ENDPOINT');
$domain_access   = (string) getenv('MK_CLOUD_ACCESS_KEY');
$domain_secret   = (string) getenv('MK_CLOUD_SECRET_KEY');
$domain_bucket   = getenv('MK_CLOUD_BUCKET') ? (string) getenv('MK_CLOUD_BUCKET') : 'smokekit';

if ($domain_endpoint === '' || $domain_access === '' || $domain_secret === '') {
	fwrite(STDERR, "Set MK_CLOUD_ENDPOINT, MK_CLOUD_ACCESS_KEY, MK_CLOUD_SECRET_KEY and, if it is not smokekit, MK_CLOUD_BUCKET.\n");
	exit(1);
}

$domain_address = $domain_role === 'source' ? DOMAIN_SOURCE : DOMAIN_TARGET;

if (home_url() !== $domain_address || site_url() !== $domain_address) {
	fwrite(STDERR, 'Give the site the address ' . $domain_address . " before running this role, as shown at the top of this file.\n");
	exit(1);
}

echo 'MigrateKit — a site moved to another address smoke test (' . $domain_role . " role)\n";
echo 'site: ' . home_url() . ' | link(): ' . (function_exists('link') ? 'present' : 'taken away by the host') . ' | PHP ' . PHP_VERSION . ' | WordPress ' . get_bloginfo('version') . "\n\n";

// A run that was cut short leaves its jobs waiting on the server: dismiss them first, as the page would offer to.
foreach (domain_ajax('migratekit_jobs_active')['data']['jobs'] as $leftover) {
	domain_ajax('migratekit_job_cancel', array( 'job_id' => (string) ( $leftover['id'] ?? '' ) ));
}

// ===========================================================================
if ($domain_role === 'source') {
	echo "[1] The source site carries its address in the forms a site can\n";

	$post_id = wp_insert_post(array(
		'post_title'   => 'About the source site',
		'post_name'    => 'about-source',
		'post_status'  => 'publish',
		'post_content' => DOMAIN_PARAGRAPH . "\n\n" . DOMAIN_IMAGE,
		'post_excerpt' => 'Read more at https://wpmhs.com/about/.',
	), true);

	if (is_wp_error($post_id)) {
		fwrite(STDERR, 'The probe post could not be made: ' . $post_id->get_error_message() . "\n");
		exit(1);
	}

	$comment_id = (int) wp_insert_comment(array(
		'comment_post_ID'    => $post_id,
		'comment_author'     => 'A source reader',
		'comment_author_url' => 'https://wpmhs.com/reader/',
		'comment_content'    => 'Read this: https://wpmhs.com/about/ and write to reader@wpmhs.com.',
		'comment_approved'   => 1,
	));

	global $wpdb;

	// Written straight to the table, the way page builders leave their data behind: a save through
	// update_post_meta() undoes the escaping, as it does for everything that comes in with a request.
	$stored_right = true;

	foreach (DOMAIN_PROBES as $key => $value) {
		$raw = 'mk_serialized' === $key ? serialize($value) : $value;

		if ('mk_b64' === $key) {
			$raw = base64_encode($value);
		}

		$wpdb->insert($wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $raw ));

		$stored_right = $stored_right && domain_stored("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = {$post_id} AND meta_key = '{$key}'") === $raw;
	}

	domain_ok('every form of the address is stored the way it was written', $stored_right);

	update_option('blogname', 'Source site');
	update_option('mk_probe', array( 'url' => 'https://wpmhs.com/option', 'list' => array( 'https://wpmhs.com/one', 'wpmhs.com/two' ) ));
	update_option('mk_probe_json', wp_json_encode(array( 'url' => 'https://wpmhs.com/json', 'n' => 5 )));
	update_option('widget_block', array( 2 => array( 'content' => DOMAIN_WIDGET ) ));
	update_option('admin_email', 'admin@wpmhs.com');

	wp_update_user(array( 'ID' => 1, 'user_email' => 'admin@wpmhs.com' ));

	domain_remember('post_id', $post_id);
	domain_remember('comment_id', $comment_id);
	domain_remember('content', domain_stored("SELECT post_content FROM {$GLOBALS['wpdb']->posts} WHERE ID = {$post_id}"));
	domain_remember('excerpt', domain_stored("SELECT post_excerpt FROM {$GLOBALS['wpdb']->posts} WHERE ID = {$post_id}"));
	domain_remember('guid', domain_stored("SELECT guid FROM {$GLOBALS['wpdb']->posts} WHERE ID = {$post_id}"));
	domain_remember('widget', (string) ( get_option('widget_block')[2]['content'] ?? '' ));

	// What only the source has, to see whether the backup brings it along.
	$saved = domain_ajax('migratekit_cloud_save', array( 'provider' => 'dropbox', 'client_id' => 'source-only-app', 'folder' => 'source-folder' ));

	domain_ok('the source has a storage set up that the target never will', ! empty($saved['success']));

	$schedule = domain_ajax('migratekit_schedule_save', array(
		'name'            => 'Weekly on the source site',
		'enabled'         => 1,
		'interval'        => 'weekly',
		'cloud_provider'  => 's3',
		'retention_count' => 3,
	));

	domain_ok('and a schedule of its own', ! empty($schedule['success']));

	// No working folder of the source's own job may travel inside the backup of the plugin.
	echo "\n[2] Its backup is made and pushed to the cloud\n";

	$export = domain_ajax('migratekit_export_start', array());
	$export = domain_drive('migratekit_export_step', (string) ( $export['data']['id'] ?? '' ));
	$backup = (string) ( $export['data']['backupName'] ?? '' );

	domain_ok('the backup is made', ! empty($export['success']) && ! empty($export['data']['done']) && $backup !== '' && is_file(domain_backup_path($backup)));

	$package = \MigrateKit\Archive\Inspector::package(domain_backup_path($backup));

	domain_same('the backup records the address it was taken on', DOMAIN_SOURCE, (string) ( $package['SiteURL'] ?? '' ));
	domain_same('and the address its files are served from', DOMAIN_SOURCE . '/wp-content/uploads/', (string) ( $package['UploadsURL'] ?? '' ));

	$saved = domain_ajax('migratekit_cloud_save', array(
		'provider'   => 's3',
		'endpoint'   => $domain_endpoint,
		'bucket'     => $domain_bucket,
		'prefix'     => 'backups',
		'region'     => 'us-east-1',
		'access_key' => $domain_access,
		'secret_key' => $domain_secret,
	));

	domain_ok('the cloud settings are saved the way the Cloud page saves them', ! empty($saved['success']));

	$push = domain_ajax('migratekit_cloud_push', array( 'provider' => 's3', 'name' => $backup ));
	$push = domain_drive('migratekit_cloud_step', (string) ( $push['data']['id'] ?? '' ));

	domain_ok('the backup is pushed to its end', ! empty($push['success']) && ! empty($push['data']['done']));

	$files = domain_ajax('migratekit_cloud_files', array( 'provider' => 's3' ));

	domain_ok('the cloud lists the backup', in_array($backup, array_column($files['data']['objects'] ?? array(), 'name'), true));

	domain_remember('backup', $backup);

	echo "\n";
	printf("passed: %d, failed: %d\n", $GLOBALS['domain_pass'], $GLOBALS['domain_fail']);
	exit($GLOBALS['domain_fail'] > 0 ? 1 : 0);
}

// ===========================================================================
$backup  = (string) ( domain_recalled('backup') ?? '' );
$post_id = (int) ( domain_recalled('post_id') ?? 0 );

if ($backup === '' || $post_id === 0) {
	fwrite(STDERR, "Run the source role first: nothing is recorded of a source site.\n");
	exit(1);
}

global $wpdb;

echo "[3] The target site looks like a site of its own\n";

// Nothing of what was recorded of the source is to be here beforehand, so that only the restore can bring it.
wp_delete_post($post_id, true);
delete_option('mk_probe');
delete_option('mk_probe_json');
update_option('widget_block', array( 2 => array( 'content' => '<!-- wp:paragraph --><p>Widget of the target site</p><!-- /wp:paragraph -->' ) ));
update_option('blogname', 'Target site');
update_option('admin_email', 'admin@devsroom.com');
wp_update_user(array( 'ID' => 1, 'user_email' => 'admin@devsroom.com' ));

foreach (array_column(\MigrateKit\Scheduler\Schedule::all(), 'id') as $schedule_id) {
	\MigrateKit\Scheduler\Schedule::delete((string) $schedule_id);
}

delete_option(\MigrateKit\Cloud\Manager::OPTION);

domain_ok('it has an address of its own', domain_stored("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'home'") === DOMAIN_TARGET && domain_stored("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl'") === DOMAIN_TARGET);
domain_ok('and nothing of the source site', domain_stored("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name = 'about-source'") === '0'
	&& domain_stored("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'mk_probe'") === ''
	&& (string) ( maybe_unserialize(domain_stored("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'widget_block'"))[2]['content'] ?? '' ) !== (string) domain_recalled('widget')
	&& \MigrateKit\Scheduler\Schedule::all() === array()
	&& \MigrateKit\Cloud\Manager::settings() === array()
);

$saved = domain_ajax('migratekit_cloud_save', array(
	'provider'   => 's3',
	'endpoint'   => $domain_endpoint,
	'bucket'     => $domain_bucket,
	'prefix'     => 'backups',
	'region'     => 'us-east-1',
	'access_key' => $domain_access,
	'secret_key' => $domain_secret,
));

domain_ok('it connects the same storage, the way the Cloud page does', ! empty($saved['success']));

$files = domain_ajax('migratekit_cloud_files', array( 'provider' => 's3' ));

domain_ok('which lists the source\'s backup', in_array($backup, array_column($files['data']['objects'] ?? array(), 'name'), true));

echo "\n[4] Restore is pressed on the Cloud page\n";

// No local copy to restore from instead: the backup has to be fetched.
@unlink(domain_backup_path($backup));

$pull = domain_ajax('migratekit_cloud_pull', array( 'provider' => 's3', 'remote' => $backup, 'restore' => 1 ));
$pull = domain_drive('migratekit_cloud_step', (string) ( $pull['data']['id'] ?? '' ));

$restore_id = (string) ( $pull['data']['restoreJob'] ?? '' );

domain_ok('the fetch ends having handed over to a restore', ! empty($pull['success']) && ! empty($pull['data']['done']) && $restore_id !== '');
domain_ok('the restore is put down for whatever page loads next', \MigrateKit\Job\ActiveJobs::has($restore_id));

$state = \MigrateKit\Job\Status::read($restore_id);

domain_ok('the restore says its archive came from the cloud', ( $state['source'] ?? '' ) === 'cloud' && ( $state['cloud'] ?? '' ) === 's3');

// A small site restores within a single request; the slices here end each stage, as on a large site.
$restored = domain_restore($restore_id);

domain_ok('the restore is cut across several requests, as it is on a real site', $GLOBALS['domain_steps'] > 3);
domain_ok('the restore runs to its end', ! empty($restored['success']) && ! empty($restored['data']['done']));
domain_same('the archive it worked on says where it was taken', DOMAIN_SOURCE, (string) ( \MigrateKit\Job\Status::read($restore_id)['package']['SiteURL'] ?? '' ));
domain_ok('nothing is left listed', \MigrateKit\Job\Runner::open_jobs() === array());
domain_same('the site is the source site now', 'Source site', domain_stored("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogname'"));

echo "\n[5] What became of the links\n";

domain_same('the site address is still the target\'s', DOMAIN_TARGET, domain_stored("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl'"));
domain_same('the home address is still the target\'s', DOMAIN_TARGET, domain_stored("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'home'"));

$content = domain_stored("SELECT post_content FROM {$wpdb->posts} WHERE ID = {$post_id}");
$excerpt = domain_stored("SELECT post_excerpt FROM {$wpdb->posts} WHERE ID = {$post_id}");
$guid    = domain_stored("SELECT guid FROM {$wpdb->posts} WHERE ID = {$post_id}");

domain_same('links in post content take the new address, the insecure and the scheme-relative ones included', domain_moved((string) domain_recalled('content')), $content);
domain_same('links in an excerpt take the new address', domain_moved((string) domain_recalled('excerpt')), $excerpt);
domain_same('the address WordPress keeps for the post takes the new address', domain_moved((string) domain_recalled('guid')), $guid);
domain_ok('no address of the source site is left in the post', strpos($content . $excerpt . $guid, 'wpmhs.com') === false);

foreach (DOMAIN_EXPECTED as $key => $expected) {
	$stored = domain_stored("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = {$post_id} AND meta_key = '{$key}'");

	if ('mk_b64' === $key) {
		$stored = (string) base64_decode($stored, true);
	}

	domain_same(DOMAIN_PROBE_LABELS[ $key ], $expected, 'mk_b64' === $key ? $stored : maybe_unserialize($stored));
}

$option_url  = domain_stored("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'mk_probe'");
$option_json = domain_stored("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'mk_probe_json'");
$widget      = (string) ( maybe_unserialize(domain_stored("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'widget_block'"))[2]['content'] ?? '' );
$comment     = domain_stored("SELECT comment_content FROM {$wpdb->comments} WHERE comment_ID = " . (int) domain_recalled('comment_id'));
$author_url  = domain_stored("SELECT comment_author_url FROM {$wpdb->comments} WHERE comment_ID = " . (int) domain_recalled('comment_id'));

domain_same('a serialized option keeps its structure and takes the new address', array( 'url' => 'https://devsroom.com/option', 'list' => array( 'https://devsroom.com/one', 'wpmhs.com/two' ) ), maybe_unserialize($option_url));
domain_same('a JSON option takes the new address', wp_json_encode(array( 'url' => 'https://devsroom.com/json', 'n' => 5 )), $option_json);
domain_same('links in a block widget take the new address', domain_moved((string) domain_recalled('widget')), $widget);
domain_same('links in a comment take the new address', 'Read this: https://devsroom.com/about/ and write to reader@devsroom.com.', $comment);
domain_same('and in the commenter\'s own address', 'https://devsroom.com/reader/', $author_url);

echo "        e.g. the JSON option now reads: {$option_json}\n";

echo "\n[6] What else the backup brought\n";

domain_same('the admin user\'s e-mail address is on the new domain', 'admin@devsroom.com', domain_stored("SELECT user_email FROM {$wpdb->users} WHERE ID = 1"));
domain_same('the site\'s e-mail setting is the source\'s, on the new domain', 'admin@devsroom.com', domain_stored("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'admin_email'"));
domain_same('the Dropbox app set up on the source is set up here now', 'source-only-app', (string) ( \MigrateKit\Cloud\Manager::settings()['dropbox']['client_id'] ?? '' ));
domain_ok('the source\'s schedule is one of this site\'s schedules now', in_array('Weekly on the source site', array_column(\MigrateKit\Scheduler\Schedule::all(), 'name'), true));

$files = domain_ajax('migratekit_cloud_files', array( 'provider' => 's3' ));

domain_ok('the storage the target connected goes on listing its backups', ! empty($files['success']));

echo "\n[7] Where that failure left the site, and how the restore is finished all the same\n";

$failed_id      = 'aaaa000011112222';
$failed_restore = 'aaaa333344445555';

// As 0.0.14 left it on the site that reported the failure: the backup had arrived and the restore's id
// was reserved; the step then stopped and said why. Its working folder is gone, cleaned up since.
\MigrateKit\Job\Status::write($failed_id, array(
	'id'              => $failed_id,
	'type'            => 'pull',
	'storage_dir'     => rtrim(\MigrateKit\Support\Paths::storage(), '/\\') . DIRECTORY_SEPARATOR . $failed_id,
	'cloud'           => 's3',
	'remote'          => $backup,
	'size'            => (int) @filesize(domain_backup_path($backup)),
	'options'         => array(),
	'stage'           => 3,
	'percent'         => 66,
	'message'         => 'Fetched from cloud',
	'created'         => time() - 3 * DAY_IN_SECONDS,
	'done'            => true,
	'restore'         => true,
	'restore_options' => array(),
	'restore_job'     => $failed_restore,
	'error'           => 'The server stopped this step with an internal error.',
));

\MigrateKit\Job\ActiveJobs::add($failed_id);

$list = domain_ajax('migratekit_backups_list');

domain_ok('the backup it had fetched is on the Backups page', in_array($backup, array_column($list['data']['backups'] ?? array(), 'name'), true));
domain_same('a page that loads later does not list that fetch under unfinished tasks: the fetch itself counts as done', array(), domain_ajax('migratekit_jobs_active')['data']['jobs']);
domain_ok('and the restore it was to hand over to was never made', \MigrateKit\Job\Status::read($failed_restore) === array());

// The page that saw it fail still holds the fetch and offers Resume, which sends its step again.
$arrived = domain_backup_path($backup);
$stamp   = (int) filemtime($arrived);
$bytes   = (int) filesize($arrived);

update_option('blogname', 'Changed since the restore');

$again = domain_ajax('migratekit_cloud_step', array( 'job_id' => $failed_id ));

domain_ok('Resume is answered without an error', ! empty($again['success']));
domain_same('and makes the restore the fetch had reserved, not another one', $failed_restore, (string) ( $again['data']['restoreJob'] ?? '' ));
domain_ok('from the backup that had already arrived, which is not fetched again', filemtime($arrived) === $stamp && filesize($arrived) === $bytes);

$restored = domain_restore($failed_restore);

domain_ok('that restore runs to its end', ! empty($restored['success']) && ! empty($restored['data']['done']));
domain_same('and puts the source site back', 'Source site', domain_stored("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogname'"));

// A page opened after that finds no task to resume. The backup is on the Backups page, though: Restore there.
update_option('blogname', 'Changed since the restore');

$from_backups = domain_ajax('migratekit_backup_restore', array( 'name' => $backup ));
$backups_id   = (string) ( $from_backups['data']['id'] ?? '' );

domain_ok('Restore on the Backups page starts', ! empty($from_backups['success']) && $backups_id !== '');
domain_same('from the copy already on the server', 'backup', (string) ( $from_backups['data']['source'] ?? '' ));
domain_ok('and does not fetch again either', filemtime($arrived) === $stamp && filesize($arrived) === $bytes);

$restored = domain_restore($backups_id);

domain_ok('that restore runs to its end', ! empty($restored['success']) && ! empty($restored['data']['done']));
domain_same('and puts the source site back', 'Source site', domain_stored("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogname'"));
domain_ok('nothing is left listed', \MigrateKit\Job\Runner::open_jobs() === array());

\MigrateKit\Job\Runner::cancel($failed_restore);
\MigrateKit\Job\Runner::cancel($backups_id);
\MigrateKit\Job\Status::delete($failed_id);

echo "\n";
printf("passed: %d, failed: %d\n", $GLOBALS['domain_pass'], $GLOBALS['domain_fail']);
exit($GLOBALS['domain_fail'] > 0 ? 1 : 0);
