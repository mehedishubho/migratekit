<?php
/**
 * Runs inside WordPress via `wp eval-file` to smoke-test the REST API.
 *
 * @package MigrateKit
 */

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run this file through wp eval-file.\n");
	exit(1);
}

$GLOBALS['rest_pass'] = 0;
$GLOBALS['rest_fail'] = 0;

function rest_ok(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['rest_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['rest_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

wp_set_current_user(1);

echo "MigrateKit — REST smoke test\n";

$routes = rest_get_server()->get_routes();

rest_ok('capabilities route registered', isset($routes['/migratekit/v1/capabilities']));
rest_ok('exports route registered', isset($routes['/migratekit/v1/exports']));
rest_ok('imports route registered', isset($routes['/migratekit/v1/imports']));
rest_ok('backups route registered', isset($routes['/migratekit/v1/backups']));

$request  = new WP_REST_Request('GET', '/migratekit/v1/capabilities');
$response = rest_do_request($request);
$data     = $response->get_data();

rest_ok('capabilities returns 200', $response->get_status() === 200);
rest_ok('capabilities reports export permission', ! empty($data['canExport']));
rest_ok('capabilities reports upload limit', isset($data['maxUpload']));

$request = new WP_REST_Request('POST', '/migratekit/v1/exports');
$request->set_body_params(array( 'compression' => '' ));
$response = rest_do_request($request);
$data     = $response->get_data();
$job_id   = isset($data['id']) ? (string) $data['id'] : '';

rest_ok('create export returns 201', $response->get_status() === 201);
rest_ok('export job id returned', $job_id !== '');

$done = false;

for ($i = 0; $i < 400; $i++) {
	$request  = new WP_REST_Request('POST', '/migratekit/v1/exports/' . $job_id . '/step');
	$response = rest_do_request($request);
	$data     = $response->get_data();

	if (! empty($data['done'])) {
		$done = true;
		break;
	}
}

rest_ok('export completes through REST', $done);

$request  = new WP_REST_Request('GET', '/migratekit/v1/exports/' . $job_id . '/log');
$response = rest_do_request($request);
$data     = $response->get_data();

rest_ok('job log is available', isset($data['log']) && is_string($data['log']) && strlen($data['log']) > 0);

$request  = new WP_REST_Request('GET', '/migratekit/v1/backups');
$response = rest_do_request($request);
$data     = $response->get_data();
$backups  = isset($data['backups']) && is_array($data['backups']) ? $data['backups'] : array();

rest_ok('backups listed over REST', count($backups) >= 1);

$name = count($backups) > 0 ? (string) $backups[0]['name'] : '';

if ($name !== '') {
	$request  = new WP_REST_Request('POST', '/migratekit/v1/backups/' . rawurlencode($name) . '/restore');
	$response = rest_do_request($request);
	$data     = $response->get_data();
	$restore  = isset($data['id']) ? (string) $data['id'] : '';

	rest_ok('one-click restore creates a job', $response->get_status() === 201 && $restore !== '');

	if ($restore !== '') {
		$request  = new WP_REST_Request('DELETE', '/migratekit/v1/imports/' . $restore);
		$response = rest_do_request($request);
		rest_ok('restore job can be cancelled', $response->get_status() === 200);
	}
}

wp_set_current_user(0);

$request  = new WP_REST_Request('GET', '/migratekit/v1/capabilities');
$response = rest_do_request($request);

rest_ok('anonymous access is denied', in_array($response->get_status(), array( 401, 403 ), true));

wp_set_current_user(1);

echo "\n";
echo 'passed: ' . $GLOBALS['rest_pass'] . ', failed: ' . $GLOBALS['rest_fail'] . "\n";
exit($GLOBALS['rest_fail'] === 0 ? 0 : 1);
