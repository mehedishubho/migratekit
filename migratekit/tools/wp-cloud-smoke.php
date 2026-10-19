<?php
/**
 * Runs inside WordPress via `wp eval-file` to smoke-test the cloud layer:
 * REST routes, settings persistence, a live S3-compatible round trip and
 * the push/pull jobs.
 *
 * Set MK_CLOUD_ENDPOINT (e.g. http://mkminio:9000) plus MK_CLOUD_ACCESS_KEY
 * and MK_CLOUD_SECRET_KEY when a live storage server is available.
 *
 * @package MigrateKit
 */

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run this file through wp eval-file.\n");
	exit(1);
}

$GLOBALS['cloud_pass'] = 0;
$GLOBALS['cloud_fail'] = 0;

function cloud_ok(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['cloud_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['cloud_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

wp_set_current_user(1);

echo "MigrateKit — cloud smoke test\n\n";

// ---------------------------------------------------------------------------
echo "[1] REST routes and provider status\n";

$routes = rest_get_server()->get_routes();

cloud_ok('providers route registered', isset($routes['/migratekit/v1/cloud/providers']));
cloud_ok('push route registered', isset($routes['/migratekit/v1/cloud/providers/(?P<provider_id>[a-z0-9]{2,12})/push']));
cloud_ok('pull route registered', isset($routes['/migratekit/v1/cloud/providers/(?P<provider_id>[a-z0-9]{2,12})/pull']));
cloud_ok('cloud job route registered', isset($routes['/migratekit/v1/cloud-jobs/(?P<job_id>[a-f0-9]{13,40})']));
cloud_ok('cloud job step route registered', isset($routes['/migratekit/v1/cloud-jobs/(?P<job_id>[a-f0-9]{13,40})/step']));

$request  = new WP_REST_Request('GET', '/migratekit/v1/cloud/providers');
$response = rest_do_request($request);
$data     = $response->get_data();

cloud_ok('providers returns 200', $response->get_status() === 200);
cloud_ok('provider list has s3', array_search('s3', array_column($data['providers'], 'id'), true) !== false);
cloud_ok('provider list has dropbox', array_search('dropbox', array_column($data['providers'], 'id'), true) !== false);
cloud_ok('provider list has gdrive', array_search('gdrive', array_column($data['providers'], 'id'), true) !== false);

$raw_cloud = (array) get_option('migratekit_cloud');
$secret_for = array( 's3' => 'secret_key', 'dropbox' => 'client_secret', 'gdrive' => 'client_secret' );
$secret_leak = false;

foreach ($data['providers'] as $provider) {
	$id    = (string) $provider['id'];
	$field = isset($secret_for[ $id ]) ? $secret_for[ $id ] : '';
	$shown = $field !== '' ? (string) ( $provider['settings'][ $field ] ?? '' ) : '';

	if ($shown === '') {
		continue;
	}

	if (! in_array($shown, array('********'), true)) {
		$secret_leak = true;
	}

	$real = isset($raw_cloud[ $id ][ $field ]) ? (string) $raw_cloud[ $id ][ $field ] : '';

	if ($real !== '' && $shown === $real) {
		$secret_leak = true;
	}
}

cloud_ok('no provider echoes stored secrets', ! $secret_leak);

// ---------------------------------------------------------------------------
echo "\n[2] Settings persistence\n";

$request = new WP_REST_Request('POST', '/migratekit/v1/cloud/providers/s3/settings');
$request->set_body_params(array(
	'endpoint'   => getenv('MK_CLOUD_ENDPOINT') ?: 'http://mkminio:9000',
	'bucket'     => getenv('MK_CLOUD_BUCKET') ?: 'smokekit',
	'prefix'     => 'backups',
	'region'     => 'us-east-1',
	'access_key' => getenv('MK_CLOUD_ACCESS_KEY') ?: 'mkadmin',
	'secret_key' => getenv('MK_CLOUD_SECRET_KEY') ?: 'mk-cloud-secret',
));

$response = rest_do_request($request);
cloud_ok('s3 settings save returns 200', $response->get_status() === 200);

$plain_secret = getenv('MK_CLOUD_SECRET_KEY') ?: 'mk-cloud-secret';

$response  = rest_do_request(new WP_REST_Request('GET', '/migratekit/v1/cloud/providers'));
$providers = $response->get_data()['providers'];
$s3        = $providers[array_search('s3', array_column($providers, 'id'), true)];

cloud_ok('s3 ready after save', ! empty($s3['ready']));
cloud_ok('s3 secret masked in status', (string) $s3['settings']['secret_key'] === '********');

$stored = get_option('migratekit_cloud');
cloud_ok('s3 secret stored unmasked', (string) $stored['s3']['secret_key'] === $plain_secret);

$masked_save = new WP_REST_Request('POST', '/migratekit/v1/cloud/providers/s3/settings');
$masked_save->set_body_params(array(
	'endpoint'   => (string) $stored['s3']['endpoint'],
	'bucket'     => (string) $stored['s3']['bucket'],
	'prefix'     => (string) $stored['s3']['prefix'],
	'region'     => (string) $stored['s3']['region'],
	'access_key' => (string) $stored['s3']['access_key'],
	'secret_key' => '********',
));

$response = rest_do_request($masked_save);
cloud_ok('masked re-save keeps the stored secret', $response->get_status() === 200);

$stored_after = get_option('migratekit_cloud');
cloud_ok('stored secret survives masked save', (string) $stored_after['s3']['secret_key'] === (string) $stored['s3']['secret_key']);

// Unknown provider rejected.
$request = new WP_REST_Request('POST', '/migratekit/v1/cloud/providers/nope/settings');
$request->set_body_params(array());

$response = rest_do_request($request);
cloud_ok('unknown provider rejected', $response->get_status() === 400);

// OAuth providers keep tokens through re-saves.
update_option('migratekit_cloud', array_merge((array) get_option('migratekit_cloud'), array(
	'dropbox' => array(
		'client_id'     => 'cid',
		'client_secret' => 'cs',
		'access_token'  => 'tok',
		'refresh_token' => 'ref',
		'account'       => 'me@example.test',
	),
)));

$request = new WP_REST_Request('POST', '/migratekit/v1/cloud/providers/dropbox/settings');
$request->set_body_params(array( 'client_id' => 'cid2', 'client_secret' => '', 'folder' => 'backups' ));

$response = rest_do_request($request);
cloud_ok('dropbox settings save returns 200', $response->get_status() === 200);

$stored_dropbox = get_option('migratekit_cloud')['dropbox'];
cloud_ok('dropbox token survives save', (string) $stored_dropbox['access_token'] === 'tok');
cloud_ok('dropbox refresh token survives', (string) $stored_dropbox['refresh_token'] === 'ref');
cloud_ok('dropbox client id replaced', (string) $stored_dropbox['client_id'] === 'cid2');

// Clean the dropbox stub again.
$reset = new WP_REST_Request('DELETE', '/migratekit/v1/cloud/providers/dropbox');
rest_do_request($reset);

$all = get_option('migratekit_cloud');

cloud_ok(
	'dropbox disconnect wipes settings',
	! isset($all['dropbox']) || ( is_array($all['dropbox']) && $all['dropbox'] === array() )
);

// ---------------------------------------------------------------------------
echo "\n[3] Files route pre-connection\n";

$request  = new WP_REST_Request('DELETE', '/migratekit/v1/cloud/providers/s3');
rest_do_request($request);

$request  = new WP_REST_Request('GET', '/migratekit/v1/cloud/providers/s3/objects');
$response = rest_do_request($request);
$data     = $response->get_data();

cloud_ok('objects on unready provider returns 200', $response->get_status() === 200);
cloud_ok('objects empty and not ready', empty($data['objects']) && empty($data['ready']));

// ---------------------------------------------------------------------------
echo "\n[4] Live storage round trip through WordPress\n";

$endpoint = (string) getenv('MK_CLOUD_ENDPOINT');
$access   = (string) getenv('MK_CLOUD_ACCESS_KEY');
$secret   = (string) getenv('MK_CLOUD_SECRET_KEY');
$bucket   = getenv('MK_CLOUD_BUCKET') ? (string) getenv('MK_CLOUD_BUCKET') : 'smokekit';
$live     = $endpoint !== '' && $access !== '' && $secret !== '';

if ($live) {
	$request = new WP_REST_Request('POST', '/migratekit/v1/cloud/providers/s3/settings');
	$request->set_body_params(array(
		'endpoint'   => $endpoint,
		'bucket'     => $bucket,
		'prefix'     => 'backups',
		'region'     => 'us-east-1',
		'access_key' => $access,
		'secret_key' => $secret,
	));
	rest_do_request($request);

	$request  = new WP_REST_Request('POST', '/migratekit/v1/cloud/providers/s3/test');
	$response = rest_do_request($request);

	cloud_ok('live connection test returns 200', $response->get_status() === 200);

	// Create a real export, then push it to the bucket.
	$request = new WP_REST_Request('POST', '/migratekit/v1/exports');
	$request->set_body_params(array( 'compression' => '' ));
	$response = rest_do_request($request);
	$data     = $response->get_data();
	$job_id   = (string) ( isset($data['id']) ? $data['id'] : '' );

	cloud_ok('smoke export created', $job_id !== '');

	for ($i = 0; $i < 400; $i++) {
		$response = rest_do_request(new WP_REST_Request('POST', '/migratekit/v1/exports/' . $job_id . '/step'));
		$data     = $response->get_data();

		if (! empty($data['done'])) {
			break;
		}
	}

	$archive = (string) ( isset($data['archiveName']) ? $data['archiveName'] : '' );

	cloud_ok('smoke export completed', ! empty($data['done']) && $archive !== '');

	// Push it.
	$request = new WP_REST_Request('POST', '/migratekit/v1/cloud/providers/s3/push');
	$request->set_body_params(array( 'name' => $archive ));
	$response = rest_do_request($request);
	$data     = $response->get_data();
	$push_id  = (string) ( isset($data['id']) ? $data['id'] : '' );

	cloud_ok('push job created', $push_id !== '');

	$push_done = false;

	for ($i = 0; $i < 400; $i++) {
		$response = rest_do_request(new WP_REST_Request('POST', '/migratekit/v1/cloud-jobs/' . $push_id . '/step'));
		$data     = $response->get_data();

		if (! empty($data['done'])) {
			$push_done = true;
			break;
		}

		if ($response->get_status() !== 200) {
			break;
		}
	}

	cloud_ok('push job completed', $push_done);

	// Listing shows it.
	$request  = new WP_REST_Request('GET', '/migratekit/v1/cloud/providers/s3/objects');
	$response = rest_do_request($request);
	$objects  = $response->get_data()['objects'];

	cloud_ok('pushed backup visible in listing', in_array($archive, array_column($objects, 'name'), true));

	// Delete the local copy, then pull it back.
	$local_path = rtrim((string) WP_CONTENT_DIR, '/\\') . '/migratekit-backups/' . $archive;

	if (is_file($local_path)) {
		unlink($local_path);
	}

	cloud_ok('local copy removed before pull', ! is_file($local_path));

	$request = new WP_REST_Request('POST', '/migratekit/v1/cloud/providers/s3/pull');
	$request->set_body_params(array( 'remote' => $archive ));
	$response = rest_do_request($request);
	$data     = $response->get_data();
	$pull_id  = (string) ( isset($data['id']) ? $data['id'] : '' );

	cloud_ok('pull job created', $pull_id !== '');

	$pull_done = false;

	for ($i = 0; $i < 400; $i++) {
		$response = rest_do_request(new WP_REST_Request('POST', '/migratekit/v1/cloud-jobs/' . $pull_id . '/step'));
		$data     = $response->get_data();

		if (! empty($data['done'])) {
			$pull_done = true;
			break;
		}

		if ($response->get_status() !== 200) {
			echo '        pull error: ' . (string) ( $data['message'] ?? '' ) . "\n";
			break;
		}
	}

	cloud_ok('pull job completed', $pull_done);
	cloud_ok('pulled archive back in backups dir', is_file($local_path));

	// Remote delete.
	$request = new WP_REST_Request('POST', '/migratekit/v1/cloud/providers/s3/objects');
	$request->set_body_params(array( 'name' => $archive ));
	$response = rest_do_request($request);

	cloud_ok('remote delete returns 200', $response->get_status() === 200);

	$request  = new WP_REST_Request('GET', '/migratekit/v1/cloud/providers/s3/objects');
	$response = rest_do_request($request);
	$objects  = $response->get_data()['objects'];

	cloud_ok('deleted remote gone from listing', ! in_array($archive, array_column($objects, 'name'), true));
} else {
	echo "        (live storage not configured — MK_CLOUD_ENDPOINT/ACCESS/SECRET skipped)\n";
}

// ---------------------------------------------------------------------------
echo "\n";
printf("passed: %d, failed: %d\n", $GLOBALS['cloud_pass'], $GLOBALS['cloud_fail']);
exit($GLOBALS['cloud_fail'] > 0 ? 1 : 0);
