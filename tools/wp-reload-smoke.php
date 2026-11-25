<?php
/**
 * Runs inside WordPress via `wp eval-file` to smoke-test what lets a job
 * outlive the page that started it: the registry of jobs the admin pages
 * started, the AJAX handlers a reloaded page uses to find and continue them,
 * and a backup fetched from the cloud and restored in one go.
 *
 * Each job is continued from the open-jobs listing alone, as a page that was
 * reloaded would, never from what the "first page" still knew.
 *
 * Set MK_CLOUD_ENDPOINT (e.g. http://minio:9000) plus MK_CLOUD_ACCESS_KEY,
 * MK_CLOUD_SECRET_KEY and MK_CLOUD_BUCKET when a live S3-compatible server is
 * available; the cloud part is skipped otherwise.
 *
 * @package MigrateKit
 */

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run this file through wp eval-file.\n");
	exit(1);
}

$GLOBALS['reload_pass'] = 0;
$GLOBALS['reload_fail'] = 0;

function reload_ok(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['reload_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['reload_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function reload_same(string $label, $expected, $actual): void {
	if ($expected === $actual) {
		reload_ok($label, true);
		return;
	}

	reload_ok($label, false);
	echo '        expected: ' . var_export($expected, true) . "\n";
	echo '        actual:   ' . var_export($actual, true) . "\n";
}

/** Thrown in place of the exit an AJAX reply ends with, so one process can play many page loads. */
final class ReloadReplySent extends \Exception {
}

function reload_reply_sent($message, $title = '', $args = array()): void {
	throw new ReloadReplySent();
}

/** What the filter for the handler of a wp_die() is given, and what it answers: the name of the handler above. */
function reload_die_handler($handler): string {
	return 'reload_reply_sent';
}

/** What a handler answers after catching the ending thrown in place of its exit: an artefact of this harness, not a reply. */
const RELOAD_AFTERMATH = '{"success":false,"data":{"message":""}}';

/**
 * The first JSON document in what a handler printed: its reply.
 *
 * A handler that wraps its reply in a try/catch catches the ending thrown in
 * place of its exit and answers an empty error document on top of the real
 * reply. That one is known; anything else a handler went on to print is
 * reported, since in a real request it could not have happened.
 *
 * @param string $raw Everything the handler printed.
 * @return array {success: bool, data: array}
 */
function reload_reply(string $raw): array {
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

	if ($after !== '' && $after !== RELOAD_AFTERMATH) {
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
 * @param string $action Action name without the wp_ajax_ prefix.
 * @param array  $payload What the page posted.
 * @return array {success: bool, data: array}
 */
function reload_ajax(string $action, array $payload = array()): array {
	$_POST    = array_merge(array( 'action' => $action, 'nonce' => wp_create_nonce('migratekit_ajax'), 'secret' => '' ), $payload);
	$_REQUEST = $_POST;

	ob_start();

	try {
		do_action('wp_ajax_' . $action);
	} catch (ReloadReplySent $sent) {
		// The reply was sent: that is the handler ending, as it would end a request.
	}

	return reload_reply((string) ob_get_clean());
}

/**
 * Continue a job by its step handler until it ends or fails.
 *
 * @param string $action Step handler.
 * @param string $job_id Job identifier.
 * @return array The last reply.
 */
/**
 * Send step requests until the job ends or a step fails, and return that last reply as it came.
 *
 * @param string $action Step handler.
 * @param string $job_id Job identifier.
 * @return array The last reply.
 */
function reload_last_step(string $action, string $job_id): array {
	$reply = array();

	for ($i = 0; $i < 600; $i++) {
		$reply = reload_ajax($action, array( 'job_id' => $job_id ));

		if (empty($reply['success']) || ! empty($reply['data']['done'])) {
			return $reply;
		}
	}

	return $reply;
}

/**
 * Continue a job by its step handler until it ends or fails, saying so when a
 * step failed or the job did not end.
 *
 * @param string $action Step handler.
 * @param string $job_id Job identifier.
 * @return array The last reply.
 */
function reload_drive(string $action, string $job_id): array {
	$reply = reload_last_step($action, $job_id);

	if (empty($reply['success'])) {
		echo '        a step failed: ' . (string) ( $reply['data']['message'] ?? '(no reason given)' ) . "\n";
	} elseif (empty($reply['data']['done'])) {
		echo "        the job did not end in time: {$job_id}\n";
	}

	return $reply;
}

function reload_backup_path(string $name): string {
	return rtrim(\MigrateKit\Support\Paths::backups(), '/\\') . DIRECTORY_SEPARATOR . $name;
}

/** A slice so short that a stage ends its request, as it does on a site too large for one. */
function reload_short_steps($seconds): float {
	return 0.001;
}

wp_set_current_user(1);

if (! defined('DOING_AJAX')) {
	define('DOING_AJAX', true);
}

add_filter('wp_die_ajax_handler', 'reload_die_handler', PHP_INT_MAX);

// The plugin wires its AJAX handlers for admin requests only, and this is not one.
\MigrateKit\Http\AjaxController::register();

echo "MigrateKit — jobs outlive their page smoke test\n\n";

// A run that was cut short leaves its jobs waiting on the server: dismiss them first, as the page would offer to.
$leftovers = reload_ajax('migratekit_jobs_active')['data']['jobs'];

foreach ($leftovers as $leftover) {
	reload_ajax('migratekit_job_cancel', array( 'job_id' => (string) ( $leftover['id'] ?? '' ) ));
}

// ---------------------------------------------------------------------------
echo "[1] A backup started from a page is found again by the next one\n";

$listed = reload_ajax('migratekit_jobs_active');

reload_ok('nothing is listed once earlier leftovers are dismissed', ! empty($listed['success']) && $listed['data']['jobs'] === array());

$start = reload_ajax('migratekit_export_start', array());
$id    = (string) ( $start['data']['id'] ?? '' );

reload_ok('the page gets a job back from the start handler', ! empty($start['success']) && $id !== '');
reload_ok('the job is put down for whatever page loads next', \MigrateKit\Job\ActiveJobs::has($id));

$listed = reload_ajax('migratekit_jobs_active');
$open   = $listed['data']['jobs'][0] ?? array();

reload_same('exactly that job is listed', array( $id ), array_column($listed['data']['jobs'], 'id'));
reload_ok(
	'listed with everything a page needs in order to show it',
	( $open['type'] ?? '' ) === 'export'
		&& ( $open['backupName'] ?? '' ) !== ''
		&& ( $open['percent'] ?? -1 ) === 0
		&& ( $open['done'] ?? true ) === false
		&& ( $open['busy'] ?? true ) === false
		&& ( $open['stale'] ?? true ) === false
		&& ( $open['error'] ?? 'x' ) === ''
		&& ( $open['updatedAt'] ?? 0 ) > 0
);

// From here on the job is only ever looked up through the listing: the page that started it is gone.
$last = reload_drive('migratekit_export_step', $id);

reload_ok('the backup runs to its end from the listing alone', ! empty($last['success']) && ! empty($last['data']['done']));

$backup = (string) ( $open['backupName'] ?? '' );

reload_ok('the archive is where the Backups page lists from', $backup !== '' && is_file(reload_backup_path($backup)));
reload_ok('a finished job is no longer listed', \MigrateKit\Job\Runner::open_jobs() === array() && ! \MigrateKit\Job\ActiveJobs::has($id));
reload_ok('the server path of the job never reaches a page', strpos(wp_json_encode($last), realpath(ABSPATH)) === false);

// ---------------------------------------------------------------------------
echo "\n[2] An upload a page was sending is waited for\n";

$reserve = reload_ajax('migratekit_import_chunk', array( 'phase' => 'start', 'name' => 'Holiday Site.wpress', 'size' => 123456 ));
$waiting = (string) ( $reserve['data']['id'] ?? '' );

reload_ok('the upload is reserved for the job', ! empty($reserve['success']) && $waiting !== '');
reload_same('the job knows the name the browser sent', 'Holiday-Site.wpress', (string) ( $reserve['data']['backupName'] ?? '' ));
reload_same('the job knows the size the browser announced', 123456, (int) ( $reserve['data']['total'] ?? 0 ));
reload_ok('the archive has not arrived yet', ( $reserve['data']['uploaded'] ?? true ) === false);

$open = reload_ajax('migratekit_jobs_active')['data']['jobs'][0] ?? array();

reload_ok('a reloaded page finds the upload waiting', ( $open['id'] ?? '' ) === $waiting && ( $open['type'] ?? '' ) === 'import' && ( $open['uploaded'] ?? true ) === false && ( $open['source'] ?? '' ) === 'upload');

reload_ajax('migratekit_job_cancel', array( 'job_id' => $waiting ));

reload_ok('discarding the upload removes it from the server', \MigrateKit\Job\Status::read($waiting) === array() && ! \MigrateKit\Job\ActiveJobs::has($waiting));

// ---------------------------------------------------------------------------
echo "\n[3] A job whose step failed says so, and is not simply run again\n";

$broken_id    = 'aaaabbbbccccddddeeee';
$broken_dir   = rtrim(\MigrateKit\Support\Paths::storage(), '/\\') . DIRECTORY_SEPARATOR . $broken_id;
$broken_state = array(
	'id'            => $broken_id,
	'type'          => 'export',
	'archive_path'  => $broken_dir . '/gone.wpress',
	'storage_dir'   => $broken_dir,
	'final_name'    => 'gone.wpress',
	'options'       => array(),
	'stage'         => 5,
	'archive_offset' => 4096,
	'percent'       => 30,
	'message'       => 'Archiving files',
	'created'       => time() - 600,
);

// Its working folder was cleaned up while no page was driving it.
wp_mkdir_p($broken_dir);
\MigrateKit\Job\Status::write($broken_id, $broken_state);
\MigrateKit\Job\ActiveJobs::add($broken_id);

$failed = reload_ajax('migratekit_export_step', array( 'job_id' => $broken_id ));
$why    = (string) ( $failed['data']['message'] ?? '' );

reload_ok('the failing step answers with its reason', empty($failed['success']) && strpos($why, 'no longer on this server') !== false);

$open = reload_ajax('migratekit_jobs_active')['data']['jobs'][0] ?? array();

reload_ok('the job is still listed: it waits to be dealt with', ( $open['id'] ?? '' ) === $broken_id);
reload_same('the listing tells a failure from a page that merely reloaded', $why, (string) ( $open['error'] ?? '' ));
reload_ok('and it is still too fresh to count as left behind', ( $open['stale'] ?? true ) === false);

$status = reload_ajax('migratekit_status', array( 'job_id' => $broken_id ));

reload_same('the status handler reports the same', $why, (string) ( $status['data']['error'] ?? '' ));

reload_ajax('migratekit_job_cancel', array( 'job_id' => $broken_id ));

reload_ok('dismissing it removes it from the server', \MigrateKit\Job\Status::read($broken_id) === array() && ! is_dir($broken_dir) && \MigrateKit\Job\Runner::open_jobs() === array());

// ---------------------------------------------------------------------------
echo "\n[4] A backup fetched from the cloud and restored in one go\n";

$endpoint = (string) getenv('MK_CLOUD_ENDPOINT');
$access   = (string) getenv('MK_CLOUD_ACCESS_KEY');
$secret   = (string) getenv('MK_CLOUD_SECRET_KEY');
$bucket   = getenv('MK_CLOUD_BUCKET') ? (string) getenv('MK_CLOUD_BUCKET') : 'smokekit';
$live     = $endpoint !== '' && $access !== '' && $secret !== '';

if ($live && $backup !== '') {
	$saved = reload_ajax('migratekit_cloud_save', array(
		'provider'   => 's3',
		'endpoint'   => $endpoint,
		'bucket'     => $bucket,
		'prefix'     => 'backups',
		'region'     => 'us-east-1',
		'access_key' => $access,
		'secret_key' => $secret,
	));

	reload_ok('the cloud settings are saved the way the Cloud page saves them', ! empty($saved['success']));

	$push = reload_ajax('migratekit_cloud_push', array( 'provider' => 's3', 'name' => $backup ));
	$push = reload_drive('migratekit_cloud_step', (string) ( $push['data']['id'] ?? '' ));

	reload_ok('the backup is pushed to its end', ! empty($push['success']) && ! empty($push['data']['done']));

	$files = reload_ajax('migratekit_cloud_files', array( 'provider' => 's3' ));

	reload_ok('the cloud lists the backup', in_array($backup, array_column($files['data']['objects'] ?? array(), 'name'), true));

	// Something for the restore to undo, and no local copy to restore from instead.
	update_option('blogname', 'Changed after the backup');
	unlink(reload_backup_path($backup));

	$pull = reload_ajax('migratekit_cloud_pull', array( 'provider' => 's3', 'remote' => $backup, 'restore' => 1 ));
	$pull = reload_drive('migratekit_cloud_step', (string) ( $pull['data']['id'] ?? '' ));

	$restore_id = (string) ( $pull['data']['restoreJob'] ?? '' );

	reload_ok('the fetch ends having handed over to a restore', ! empty($pull['success']) && ! empty($pull['data']['done']) && $restore_id !== '');
	reload_ok('the restore is put down for whatever page loads next', \MigrateKit\Job\ActiveJobs::has($restore_id) && ! \MigrateKit\Job\ActiveJobs::has((string) ( $pull['data']['id'] ?? 'x' )));

	$listed = reload_ajax('migratekit_jobs_active');
	$open   = $listed['data']['jobs'][0] ?? array();

	reload_same('only the restore is listed', array( $restore_id ), array_column($listed['data']['jobs'], 'id'));
	reload_ok(
		'listed as a restore of that backup from the cloud',
		( $open['type'] ?? '' ) === 'import'
		&& ( $open['source'] ?? '' ) === 'cloud'
		&& ( $open['cloud'] ?? '' ) === 's3'
		&& ( $open['cloudLabel'] ?? '' ) !== ''
		&& ( $open['backupName'] ?? '' ) === $backup
		&& ( $open['uploaded'] ?? false ) === true
		&& ( $open['stale'] ?? true ) === false
	);

	// A small site restores within a single request, where nothing can be reloaded in between. The slice is
	// shortened, so that the stages are cut across requests the way a large site's are.
	add_filter('migratekit_step_seconds', 'reload_short_steps');

	// The page was reloaded between two steps each time, so the listing is all it has. The restore replaces
	// the options table as it goes, the registry with it: the job has to be listed again after every step.
	$restored   = array();
	$steps      = 0;
	$still_list = array();

	for ($i = 0; $i < 600; $i++) {
		$restored = reload_ajax('migratekit_import_step', array( 'job_id' => $restore_id ));
		$steps++;

		if (empty($restored['success'])) {
			echo "        restore step {$steps} failed: " . (string) ( $restored['data']['message'] ?? '(no reason given)' ) . "\n";

			break;
		}

		if (! empty($restored['data']['done'])) {
			break;
		}

		$still_list[] = array_column(reload_ajax('migratekit_jobs_active')['data']['jobs'], 'id') === array( $restore_id );
	}

	remove_filter('migratekit_step_seconds', 'reload_short_steps');

	reload_ok('the restore is cut across several requests, as it is on a real site', $steps > 3);
	reload_ok('it is listed again after every one of its steps, the database import included', count($still_list) === $steps - 1 && ! in_array(false, $still_list, true));
	reload_ok('the restore runs to its end from the listing alone', ! empty($restored['success']) && ! empty($restored['data']['done']));

	// The step that imports the database takes the job's own record with it; a runner that mistook that for a
	// cancellation would answer "finished" here and save nothing.
	reload_ok('it ended by finishing, not by being written off as cancelled', empty($restored['data']['cancelled']));
	reload_ok('its finished record is still on the server', ( \MigrateKit\Job\Status::read($restore_id)['done'] ?? false ) === true);
	reload_same('the registry of jobs is this site\'s own again once the restore is done', array(), \MigrateKit\Job\ActiveJobs::ids());

	// Read past the cache of this long-running process: the import replaced the table underneath it.
	global $wpdb;

	$title = (string) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogname'");

	reload_same('the site is back to what the backup held', 'MigrateKitTest', $title);
	reload_ok('nothing is left listed', \MigrateKit\Job\Runner::open_jobs() === array());

	// A request cut off after the fetch reserved the restore's id but before the restore existed.
	$cut_id        = 'ffff000011112222';
	$cut_restore   = 'ffff333344445555';
	$cut_dir       = rtrim(\MigrateKit\Support\Paths::storage(), '/\\') . DIRECTORY_SEPARATOR . $cut_id;
	$finished_name = (string) ( $push['data']['remote'] ?? $backup );

	wp_mkdir_p($cut_dir);
	\MigrateKit\Job\Status::write($cut_id, array(
		'id'              => $cut_id,
		'type'            => 'pull',
		'storage_dir'     => $cut_dir,
		'cloud'           => 's3',
		'remote'          => $finished_name,
		'size'            => 0,
		'options'         => array(),
		'stage'           => 3,
		'percent'         => 100,
		'message'         => 'Fetched from cloud',
		'created'         => time() - 30,
		'restore'         => true,
		'restore_options' => array(),
		'restore_job'    => $cut_restore,
	));
	\MigrateKit\Job\ActiveJobs::add($cut_id);

	$cut = reload_ajax('migratekit_cloud_step', array( 'job_id' => $cut_id ));

	reload_same('the resumed fetch makes the restore it had reserved, not another one', $cut_restore, (string) ( $cut['data']['restoreJob'] ?? '' ));
	reload_same('exactly that restore is listed', array( $cut_restore ), array_column(reload_ajax('migratekit_jobs_active')['data']['jobs'], 'id'));

	reload_ajax('migratekit_job_cancel', array( 'job_id' => $cut_restore ));
	reload_ajax('migratekit_job_cancel', array( 'job_id' => $cut_id ));

	reload_ok('nothing is left listed once it is dismissed', \MigrateKit\Job\Runner::open_jobs() === array());

	// ---------------------------------------------------------------------------
	echo "\n[5] A restore started again with its archive's password keeps saying where the backup came from\n";

	// An encrypted archive is found to be encrypted only once its restore is under way.
	$locked_start = reload_ajax('migratekit_export_start', array( 'password' => 'smoke-secret' ));
	$locked_id    = (string) ( $locked_start['data']['id'] ?? '' );
	$locked_name  = (string) ( $locked_start['data']['backupName'] ?? '' );
	$locked       = reload_drive('migratekit_export_step', $locked_id);

	reload_ok('an encrypted backup is made', ! empty($locked['success']) && ! empty($locked['data']['done']) && $locked_name !== '' && is_file(reload_backup_path($locked_name)));

	// A page that was asked for the password of a backup fetched from the cloud starts the restore over
	// itself, naming the storage so the row goes on reading "Restoring … from <storage>".
	$again    = reload_ajax('migratekit_backup_restore', array( 'name' => $locked_name, 'password' => 'wrong-on-purpose', 'cloud' => 's3' ));
	$again_id = (string) ( $again['data']['id'] ?? '' );

	reload_ok('the restore handler takes the storage a page names', ! empty($again['success']) && $again_id !== '');
	reload_ok(
		'and the job it makes says the backup was fetched from there',
		( $again['data']['source'] ?? '' ) === 'cloud'
			&& ( $again['data']['cloud'] ?? '' ) === 's3'
			&& ( $again['data']['cloudLabel'] ?? '' ) !== ''
			&& ( $again['data']['remote'] ?? '' ) === $locked_name
	);
	reload_ok('the job is put down for whatever page loads next', \MigrateKit\Job\ActiveJobs::has($again_id));

	$listed = reload_ajax('migratekit_jobs_active');
	$open   = $listed['data']['jobs'][0] ?? array();

	reload_ok('a reloaded page finds it as a restore from that storage', ( $open['id'] ?? '' ) === $again_id && ( $open['source'] ?? '' ) === 'cloud' && ( $open['cloudLabel'] ?? '' ) !== '');

	// The password is wrong: the restore has to stop with its reason, before anything on the site is replaced.
	$step = reload_last_step('migratekit_import_step', $again_id);
	$why  = (string) ( $step['data']['message'] ?? '' );

	if (strpos($why, 'incorrect') === false) {
		echo '        the step answered: ' . ( $why === '' ? '(no reason given)' : $why ) . "\n";
	}

	reload_ok('a wrong password stops the restore with that reason', empty($step['success']) && strpos($why, 'incorrect') !== false);

	$open = reload_ajax('migratekit_jobs_active')['data']['jobs'][0] ?? array();

	reload_same('the job is still listed with that reason, for a page to answer', $why, (string) ( $open['error'] ?? '' ));

	$title = (string) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogname'");

	reload_same('and the site is as the restore left it', 'MigrateKitTest', $title);

	// Asked again, the page drops that attempt and starts the restore with the right password.
	reload_ajax('migratekit_job_cancel', array( 'job_id' => $again_id ));

	$right    = reload_ajax('migratekit_backup_restore', array( 'name' => $locked_name, 'password' => 'smoke-secret', 'cloud' => 's3' ));
	$right_id = (string) ( $right['data']['id'] ?? '' );
	$right    = reload_drive('migratekit_import_step', $right_id);

	reload_ok('with the right password the restore runs to its end', ! empty($right['success']) && ! empty($right['data']['done']));
	reload_ok('and says to the end where the backup came from', ( $right['data']['source'] ?? '' ) === 'cloud' && ( $right['data']['cloudLabel'] ?? '' ) !== '');

	$title = (string) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogname'");

	reload_same('the site is what the encrypted backup held', 'MigrateKitTest', $title);
	reload_ok('nothing is left listed once it is done', \MigrateKit\Job\Runner::open_jobs() === array());

	$plain    = reload_ajax('migratekit_backup_restore', array( 'name' => $backup, 'cloud' => 'not-a-provider' ));
	$plain_id = (string) ( $plain['data']['id'] ?? '' );

	reload_ok(
		'a storage the plugin does not know is not taken for one',
		! empty($plain['success']) && ( $plain['data']['source'] ?? '' ) === 'backup' && ( $plain['data']['cloud'] ?? 'x' ) === ''
	);

	reload_ajax('migratekit_job_cancel', array( 'job_id' => $plain_id ));

	reload_ok('nothing is left listed once it is dismissed', \MigrateKit\Job\Runner::open_jobs() === array());
} else {
	echo "        (live storage not configured, or no backup to push — MK_CLOUD_ENDPOINT/ACCESS/SECRET skipped)\n";
}

// ---------------------------------------------------------------------------
echo "\n";
printf("passed: %d, failed: %d\n", $GLOBALS['reload_pass'], $GLOBALS['reload_fail']);
exit($GLOBALS['reload_fail'] > 0 ? 1 : 0);
