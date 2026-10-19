<?php
/**
 * Real-storage S3 round-trip against a live endpoint (MinIO, AWS, any
 * S3-compatible server).
 *
 * Verifies the SigV4 signer, multipart upload, ranged download, listing
 * and delete against a real server. No WordPress boot needed.
 *
 * Usage: S3_ENDPOINT=http://minio:9000 S3_ACCESS_KEY=... S3_SECRET_KEY=... \
 *        [S3_BUCKET=migratekit] S3_REGION=us-east-1 \
 *        php tools/minio-integration.php
 *
 * @package MigrateKit
 */

declare(strict_types=1);

require __DIR__ . '/../tests/bootstrap.php';

use MigrateKit\Cloud\S3Provider;
use MigrateKit\Cloud\SigV4;
use MigrateKit\Cloud\Transport;

$endpoint = getenv('S3_ENDPOINT');
$access   = getenv('S3_ACCESS_KEY');
$secret   = getenv('S3_SECRET_KEY');
$bucket   = getenv('S3_BUCKET') ?: 'migratekit';
$region   = getenv('S3_REGION') ?: 'us-east-1';

if ($endpoint === false || $access === false || $secret === false) {
	fwrite(STDERR, "S3_ENDPOINT, S3_ACCESS_KEY and S3_SECRET_KEY are required.\n");
	exit(2);
}

$suicides = 0;

function check_mio(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['mio_pass'] = ( $GLOBALS['mio_pass'] ?? 0 ) + 1;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['mio_fail'] = ( $GLOBALS['mio_fail'] ?? 0 ) + 1;
		echo "FAIL  - {$label}\n";
	}
}

function request_mio(string $method, string $url, array $query = array(), ?string $body = null, string $payload = ''): array {
	$parsed = parse_url($url);
	$host   = (string) $parsed['host'] . ( isset($parsed['port']) ? ':' . $parsed['port'] : '' );
	$path   = (string) ( $parsed['path'] ?? '' );

	$signed = SigV4::sign(
		strtoupper($method),
		$host,
		$path,
		$query,
		array(),
		(string) getenv('S3_REGION') ?: 'us-east-1',
		(string) getenv('S3_ACCESS_KEY'),
		(string) getenv('S3_SECRET_KEY'),
		$payload === '' && $body !== null ? 'UNSIGNED-PAYLOAD' : ( $payload ?: hash('sha256', '') )
	);

	$send = $url . ( $query !== array() ? '?' . SigV4::canonical_query($query) : '' );

	return Transport::send(strtoupper($method), $send, $signed, $body, 30);
}

// ---------------------------------------------------------------------------
echo "MigrateKit — real S3 round-trip ({$endpoint})\n\n";

// 1. Bucket bootstrap: create if missing (idempotent PUT returns 200/409).
$parsed = parse_url($endpoint);
$host   = (string) $parsed['host'] . ( isset($parsed['port']) ? ':' . $parsed['port'] : '' );
$base   = $endpoint . '/' . $bucket;

$response = request_mio('PUT', $base);
check_mio('bucket reachable or created', $response['status'] === 200 || $response['status'] === 409);

// 2. Provider round-trip.
$content = random_bytes(1024 * 1024);
$local   = sys_get_temp_dir() . '/mk-minio-' . bin2hex(random_bytes(4)) . '.wpress';
file_put_contents($local, $content);

$provider = new S3Provider(array(
	'endpoint'   => $endpoint,
	'bucket'     => $bucket,
	'prefix'     => 'backups',
	'region'     => $region,
	'access_key' => $access,
	'secret_key' => $secret,
));

$threw = false;

try {
	$provider->test();
} catch (\RuntimeException $e) {
	$threw = true;
	echo '        test failed: ' . $e->getMessage() . "\n";
}

check_mio('connection test passes', ! $threw);

$session = $provider->upload_start('minio-trip.wpress');
$total   = 0;

while (true) {
	$bytes = $provider->upload_chunk($session, $local, $total, $provider->chunk_size());

	if ($bytes === 0) {
		break;
	}

	$total += $bytes;
}

$provider->upload_finish($session);

check_mio('multipart upload finished', $total === strlen($content));

$objects = $provider->list();
check_mio('listing shows the upload', isset($objects['minio-trip.wpress']));

$session = $provider->download_start('minio-trip.wpress');
check_mio('head reports the size', (int) $session['size'] === strlen($content));

$pulled  = '';
$offset  = 0;

while (true) {
	$bytes = $provider->download_chunk($session, $offset, $provider->chunk_size());

	if ($bytes === '') {
		break;
	}

	$pulled .= $bytes;
	$offset += strlen($bytes);
}

check_mio('round-tripped bytes identical', hash_equals($content, $pulled));

$provider->delete('minio-trip.wpress');
check_mio('delete removed the object', ! isset($provider->list()['minio-trip.wpress']));

unlink($local);

echo "\n";
printf("passed: %d, failed: %d\n", $GLOBALS['mio_pass'] ?? 0, $GLOBALS['mio_fail'] ?? 0);
exit(( $GLOBALS['mio_fail'] ?? 0 ) > 0 ? 1 : 0);