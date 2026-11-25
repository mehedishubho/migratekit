<?php
/**
 * Cloud layer suite: SigV4 against the documented AWS example, S3 provider
 * request shaping, Manager option semantics, OAuth flows and the push/pull
 * pipelines, all with an injected fake transport.
 *
 * Usage: php tests/run-cloud.php
 *
 * @package MigrateKit
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MigrateKit\Cloud\DriveProvider;
use MigrateKit\Cloud\DropboxProvider;
use MigrateKit\Cloud\Manager;
use MigrateKit\Cloud\PullPipeline;
use MigrateKit\Cloud\PushPipeline;
use MigrateKit\Cloud\S3Provider;
use MigrateKit\Cloud\SigV4;
use MigrateKit\Cloud\Transport;
use MigrateKit\Pipeline\Pipeline;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Site\SiteContext;

// --- WordPress polyfills (outside WordPress these functions do not exist) --
//
$GLOBALS['mk_opt'] = array();

function get_option(string $name, $default = false) {
	return array_key_exists($name, $GLOBALS['mk_opt']) ? $GLOBALS['mk_opt'][ $name ] : $default;
}

function update_option(string $name, $value, $autoload = null): bool {
	$GLOBALS['mk_opt'][ $name ] = $value;

	return true;
}

function admin_url(string $path = ''): string {
	return 'http://admin.test/wp-admin/' . $path;
}

function wp_mkdir_p(string $dir): bool {
	return is_dir($dir) || mkdir($dir, 0777, true);
}

function wp_create_nonce(string $action = ''): string {
	return substr(hash('sha256', $action . '|nonce'), 0, 10);
}

/**
 * @return int|false
 */
function wp_verify_nonce(string $nonce, string $action = '') {
	return $nonce !== '' && $nonce === substr(hash('sha256', $action . '|nonce'), 0, 10) ? 1 : false;
}

$GLOBALS['mk_transient'] = array();

function get_transient(string $key) {
	return array_key_exists($key, $GLOBALS['mk_transient']) ? $GLOBALS['mk_transient'][ $key ] : false;
}

function set_transient(string $key, $value, $ttl = 0): bool {
	$GLOBALS['mk_transient'][ $key ] = $value;

	return true;
}

function delete_transient(string $key): bool {
	unset($GLOBALS['mk_transient'][ $key ]);

	return true;
}

$GLOBALS['cl_tmp'] = sys_get_temp_dir() . '/mk-cloud-' . bin2hex(random_bytes(4));
mkdir($GLOBALS['cl_tmp']);

define('MIGRATEKIT_PATH', $GLOBALS['cl_tmp'] . '/plugin');
define('WP_CONTENT_DIR', $GLOBALS['cl_tmp'] . '/wp-content');

// --- Test helpers ----------------------------------------------------------

function check_cl(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['cl_pass'] = ( $GLOBALS['cl_pass'] ?? 0 ) + 1;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['cl_fail'] = ( $GLOBALS['cl_fail'] ?? 0 ) + 1;
		echo "FAIL  - {$label}\n";
	}
}

function same_cl(string $label, $expected, $actual): void {
	if ($expected === $actual) {
		check_cl($label, true);
		return;
	}

	check_cl($label, false);
	echo '        expected: ' . var_export($expected, true) . "\n";
	echo '        actual:   ' . var_export($actual, true) . "\n";
}

/**
 * @param string $haystack Where to look.
 * @param string $needle   Literal needle.
 */
function contains_cl(string $label, string $haystack, string $needle): void {
	check_cl($label, strpos($haystack, $needle) !== false);
}

function headers_get(array $headers, string $name): ?string {
	foreach ($headers as $key => $value) {
		if (strtolower((string) $key) === strtolower($name)) {
			return (string) $value;
		}
	}

	return null;
}

// ---------------------------------------------------------------------------

final class ClRecorder {

	/** @var array<int,array{method:string,url:string,headers:array,body:string}> */
	public $calls = array();

	/**
	 * @param string $method  HTTP method.
	 * @param string $url     Full URL.
	 * @param array  $headers Headers.
	 * @param mixed  $body    Body.
	 * @return void
	 */
	public function record(string $method, string $url, array $headers, $body): void {
		$this->calls[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => is_string($body) ? $body : '',
		);
	}

	/**
	 * @param string $needle Substring looked for in method + url.
	 * @return array|null
	 */
	public function call_hitting(string $needle): ?array {
		foreach ($this->calls as $call) {
			if (strpos($call['method'] . ' ' . $call['url'], $needle) !== false) {
				return $call;
			}
		}

		return null;
	}

	/**
	 * Header of the last call matching needle, case-insensitive.
	 *
	 * @param string $needle Call locator.
	 * @param string $name   Header name.
	 * @return string|null
	 */
	public function header_of(string $needle, string $name): ?string {
		$call = $this->call_hitting($needle);

		if ($call === null) {
			return null;
		}

		return headers_get($call['headers'], $name);
	}

	/**
	 * @param string $needle Call locator.
	 * @return string
	 */
	public function body_of(string $needle): string {
		$call = $this->call_hitting($needle);

		return $call === null ? '' : $call['body'];
	}
}

// ---------------------------------------------------------------------------
echo "MigrateKit — cloud storage suite\n\n";

// ---------------------------------------------------------------------------
echo "[1] SigV4 signer invariants\n";
//
// The exact-value goldens for SigV4 come from the real storage endpoint
// (see tools/minio-integration.php): remembering published hash constants
// is unreliable. Here we pin the signer's structure: scheme, credential
// scope, signed-header ordering, date format and determinism.

$payload_hash = hash('sha256', ''); // empty body

$signed = SigV4::sign_at(
	'GET',
	'examplebucket.s3.amazonaws.com',
	'/test.txt',
	array(),
	array( 'Range' => 'bytes=0-9' ),
	'us-east-1',
	'AKIAIOSFODNN7EXAMPLE',
	'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
	$payload_hash,
	new DateTimeImmutable('2013-05-24 00:00:00', new DateTimeZone('UTC'))
);

contains_cl('authorization scheme present', (string) $signed['Authorization'], 'AWS4-HMAC-SHA256');
contains_cl('credential scope present', (string) $signed['Authorization'], 'Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request');
contains_cl('signed headers present', (string) $signed['Authorization'], 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date');

preg_match('/Signature=([a-f0-9]{64})/', (string) $signed['Authorization'], $sig_match);
check_cl('signature is 64 hex chars', isset($sig_match[1]) && strlen($sig_match[1]) === 64);
check_cl('payload hash in canonical input', $signed['X-Amz-Content-SHA256'] === $payload_hash);

$again = SigV4::sign_at(
	'GET',
	'examplebucket.s3.amazonaws.com',
	'/test.txt',
	array(),
	array( 'Range' => 'bytes=0-9' ),
	'us-east-1',
	'AKIAIOSFODNN7EXAMPLE',
	'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
	$payload_hash,
	new DateTimeImmutable('2013-05-24 00:00:00', new DateTimeZone('UTC'))
);

same_cl('same clock reproduces the signature', (string) $signed['Authorization'], (string) $again['Authorization']);

$other = SigV4::sign_at(
	'GET',
	'examplebucket.s3.amazonaws.com',
	'/test.txt',
	array(),
	array( 'Range' => 'bytes=0-9' ),
	'eu-west-1',
	'AKIAIOSFODNN7EXAMPLE',
	'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
	$payload_hash,
	new DateTimeImmutable('2013-05-24 00:00:00', new DateTimeZone('UTC'))
);

check_cl('region changes the scope', strpos((string) $other['Authorization'], '/20130524/et-west-1') === false && strpos((string) $other['Authorization'], '/20130524/eu-west-1') !== false);

same_cl('x-amz-date formatted', '20130524T000000Z', $signed['X-Amz-Date']);

// ---------------------------------------------------------------------------
echo "\n[2] SigV4 query/path helpers\n";

same_cl('query sorted and encoded', 'a=1&list-type=2&x=z%2Bw', SigV4::canonical_query(array( 'x' => 'z+w', 'list-type' => '2', 'a' => '1' )));
same_cl('empty query', '', SigV4::canonical_query(array()));
same_cl('path escapes once per segment', '/mirror/folds%252Fold/back%20up.wpress', SigV4::encode_path('/mirror/folds%2Fold/back up.wpress'));

// ---------------------------------------------------------------------------
echo "\n[3] S3 provider request shaping\n";

$s3calls = array();

$remote_content = str_repeat('a', 8 * 1024 * 1024) . 'b';

$s3fake = function (string $method, string $url, array $headers, $body, int $timeout) use (&$s3calls, $remote_content): array {
	$s3calls[] = array(
		'method'  => $method,
		'url'     => $url,
		'headers' => $headers,
		'body'    => is_string($body) ? $body : '',
	);

	$parsed  = parse_url($url);
	parse_str((string) ( $parsed['query'] ?? '' ), $query);
	$path    = (string) ( $parsed['path'] ?? '' );

	// Object key inside the path, after the bucket segment.
	$segments = explode('/', ltrim($path, '/'));
	$key      = implode('/', array_slice($segments, 1));

	if (isset($query['uploads'])) {
		return array(
			'status'  => 200,
			'headers' => array( 'content-type' => 'application/xml' ),
			'body'    => '<InitiateMultipartUploadResult><UploadId>mk-upload-1</UploadId></InitiateMultipartUploadResult>',
		);
	}

	if (isset($query['partNumber'])) {
		$part = (int) $query['partNumber'];

		return array(
			'status'  => 200,
			'headers' => array( 'etag' => '"part-etag-' . $part . '"' ),
			'body'    => '',
		);
	}

	if (isset($query['uploadId']) && $method === 'POST') {
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => '<CompleteMultipartUploadResult></CompleteMultipartUploadResult>',
		);
	}

	if ($method === 'HEAD') {
		return array(
			'status'  => 200,
			'headers' => array( 'content-length' => (string) strlen($remote_content) ),
			'body'    => '',
		);
	}

	if ($method === 'GET' && preg_match('/\.wpress$/', $key) === 1 && isset($query['list-type']) === false) {
		$range = headers_get($headers, 'Range') ?? '';

		if (preg_match('/bytes=(\d+)-(\d+)/', $range, $match) === 1) {
			$start = (int) $match[1];
			$end   = (int) $match[2];

			if ($start >= strlen($remote_content)) {
				return array( 'status' => 416, 'headers' => array(), 'body' => '' );
			}

			$end = min($end, strlen($remote_content) - 1);

			return array(
				'status'  => 206,
				'headers' => array( 'content-range' => "bytes {$start}-{$end}/" . strlen($remote_content) ),
				'body'    => substr($remote_content, $start, $end - $start + 1),
			);
		}
	}

	if ($method === 'GET' && $query === array()) {
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => '<ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>',
		);
	}

	if (isset($query['list-type'])) {
		if (! isset($query['continuation-token'])) {
			return array(
				'status'  => 200,
				'headers' => array(),
				'body'    => '<ListBucketResult>'
					. '<IsTruncated>true</IsTruncated><NextContinuationToken>tok-2</NextContinuationToken>'
					. '<Contents><Key>tmp/readme.txt</Key><Size>3</Size><LastModified>2024-01-01T00:00:00.000Z</LastModified></Contents>'
					. '<Contents><Key>backups/alpha.wpress</Key><Size>11</Size><LastModified>2024-01-02T10:00:00.000Z</LastModified></Contents>'
					. '</ListBucketResult>',
			);
		}

		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => '<ListBucketResult>'
				. '<IsTruncated>false</IsTruncated>'
				. '<Contents><Key>backups/beta.wpress</Key><Size>12</Size><LastModified>2024-01-03T10:00:00.000Z</LastModified></Contents>'
				. '</ListBucketResult>',
		);
	}

	if ($method === 'DELETE') {
		return array( 'status' => 204, 'headers' => array(), 'body' => '' );
	}

	return array( 'status' => 500, 'headers' => array(), 'body' => '<Error><Message>unhandled</Message></Error>' );
};

$GLOBALS['mk_opt']['migratekit_cloud'] = array(
	's3' => array(
		'endpoint'   => 'http://s3.local',
		'bucket'     => 'mirror',
		'prefix'     => 'backups',
		'region'     => 'us-east-1',
		'access_key' => 'AKIATEST',
		'secret_key' => 's3cr3t',
		'http'       => $s3fake,
	),
);

$provider = Manager::provider('s3');

check_cl('provider resolves', $provider instanceof S3Provider);
check_cl('provider ready', $provider !== null && $provider->is_ready());

$session = $provider->upload_start('push-me.wpress');
same_cl('upload id parsed', 'mk-upload-1', $session['upload_id']);

$init_call = call_site_aware($s3calls, 'uploads=');
check_cl('initiate is POST ?uploads=', $init_call !== null && $init_call['method'] === 'POST');

$bytes = $provider->upload_chunk($session, cl_local_file($remote_content), 0, $provider->chunk_size());
same_cl('chunk bytes reported', 8 * 1024 * 1024, $bytes);
same_cl('next part advanced', 2, $session['next_part']);

$bytes = $provider->upload_chunk($session, cl_local_file($remote_content), 8 * 1024 * 1024, $provider->chunk_size());
same_cl('final part reported', 1, $bytes);
same_cl('etag captured with quotes', '"part-etag-1"', $session['parts'][0]['etag']);

$provider->upload_finish($session);

$complete = call_complete($s3calls);
contains_cl('complete references part one', $complete, '<PartNumber>1</PartNumber>');
contains_cl('complete references part two', $complete, '<PartNumber>2</PartNumber>');

$part_count = substr_count($complete, '<Part>');
same_cl('exactly two parts completed', 2, $part_count);

// Listing is prefix-relative and .wpress-only.
$objects = $provider->list();
same_cl('list skips non-archives', array( 'alpha.wpress', 'beta.wpress' ), array_keys($objects));
check_cl('pagination crossed the token', $provider->list() !== array());
check_cl('continuation token recorded', array_reduce($s3calls, static function ($carry, $call) {
	return $carry || strpos($call['url'], 'continuation-token=tok-2') !== false;
}, false));
same_cl('mtime parsed', strtotime('2024-01-02T10:00:00.000Z'), $objects['alpha.wpress']['mtime']);

// Ranged download.
$session = $provider->download_start('pull-me.wpress');
same_cl('head size captured', strlen($remote_content), $session['size']);

$bytes = $provider->download_chunk($session, strlen($remote_content) - 1, $provider->chunk_size());
same_cl('tail byte delivered', 'b', $bytes);

$bytes = $provider->download_chunk($session, strlen($remote_content), $provider->chunk_size());
same_cl('past-end yields empty', '', $bytes);

$last_range = call_last_range($s3calls);

check_cl('range header present', is_string($last_range) && strpos($last_range, 'bytes=') === 0);

// Delete is quiet on success, throws on server error.
$provider->delete('alpha.wpress');
check_cl('delete issued' , call_site_aware($s3calls, 'DELETE ') !== null);

// The error path surfaces the S3 XML message.
$failing = new S3Provider(array(
	'endpoint'   => 'http://s3.local',
	'bucket'     => 'mirror',
	'region'     => 'us-east-1',
	'access_key' => 'AKIATEST',
	'secret_key' => 's3cr3t',
	'http'       => static function (string $method, string $url, array $headers, $body, int $timeout): array {
		return array(
			'status'  => 500,
			'headers' => array(),
			'body'    => '<Error><Message>No such bucket</Message></Error>',
			'error'   => '',
		);
	},
));

$threw = false;

try {
	$failing->test();
} catch (\RuntimeException $e) {
	$threw = true;
	contains_cl('server error surfaces the XML message', (string) $e->getMessage(), 'No such bucket');
	contains_cl('server error carries the status', (string) $e->getMessage(), '(HTTP 500:');
}

check_cl('failing bucket listing throws', $threw);
check_cl('etag preserved in complete call', strpos(call_complete($s3calls), '"part-etag-1"') !== false);

// A connection test must prove write access: a read-only key lists the bucket
// fine and is refused only when the push starts.
$before_calls = count($s3calls);
$provider->test();
$test_calls  = array_slice($s3calls, $before_calls);
$probe_calls = array_values(array_filter($test_calls, static function (array $call): bool {
	return strpos($call['url'], 'migratekit-write-check.wpress') !== false;
}));

same_cl('test lists the bucket first', 'GET', $test_calls[0]['method']);
same_cl('test sends three requests', 3, count($test_calls));
same_cl('test opens then cancels one probe upload', array( 'POST', 'DELETE' ), array_column($probe_calls, 'method'));
same_cl(
	'probe is the push request under the prefix',
	'http://s3.local/mirror/backups/migratekit-write-check.wpress?uploads=',
	$probe_calls[0]['url']
);
same_cl('probe is aborted by upload id', 'http://s3.local/mirror/backups/migratekit-write-check.wpress?uploadId=mk-upload-1', $probe_calls[1]['url']);

/**
 * Storage that lets a key read but not write, as a read-only token does.
 *
 * @param array  $calls  Receives one "METHOD URL" line per request.
 * @param string $denial Body of every refused write.
 * @return callable
 */
$read_only_storage = static function (array &$calls, string $denial = ''): callable {
	$denial = $denial !== '' ? $denial : '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>';

	return static function (string $method, string $url, array $headers, $body, int $timeout) use (&$calls, $denial): array {
		$calls[] = $method . ' ' . $url;

		if ($method === 'GET') {
			return array(
				'status'  => 200,
				'headers' => array(),
				'body'    => '<ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>',
				'error'   => '',
			);
		}

		return array( 'status' => 403, 'headers' => array(), 'body' => $denial, 'error' => '' );
	};
};

$reader_settings = static function (callable $http, string $endpoint = 'https://accountid.r2.cloudflarestorage.com'): array {
	return array(
		'endpoint'   => $endpoint,
		'bucket'     => 'backups',
		'prefix'     => '',
		'region'     => 'us-east-1',
		'access_key' => 'AKIATEST',
		'secret_key' => 's3cr3t',
		'http'       => $http,
	);
};

$reader_calls = array();
$reader       = new S3Provider($reader_settings($read_only_storage($reader_calls)));
$threw        = false;

try {
	$reader->test();
} catch (\RuntimeException $e) {
	$threw = true;
	same_cl(
		'read-only key fails the connection test with the fix spelled out',
		'The bucket can be listed, but this access key cannot write to it (HTTP 403: Access Denied). '
			. 'Backups cannot be pushed with these credentials. '
			. 'On Cloudflare R2, create the API token with the "Object Read and Write" permission for this bucket.',
		(string) $e->getMessage()
	);
}

check_cl('read-only key is caught by the connection test', $threw);
same_cl('read-only test stops at the refused write', 2, count($reader_calls));

$reader_calls = array();
$threw        = false;

try {
	$reader->upload_start('site.wpress');
} catch (\RuntimeException $e) {
	$threw = true;
	same_cl(
		'push with a read-only key names the R2 permission',
		'Starting the upload failed (HTTP 403: Access Denied). The access key cannot write to this bucket. '
			. 'On Cloudflare R2, create the API token with the "Object Read and Write" permission for this bucket.',
		(string) $e->getMessage()
	);
}

check_cl('push with a read-only key throws', $threw);

$ignored = array();
$threw   = false;

try {
	(new S3Provider($reader_settings($read_only_storage($ignored), 'https://s3.eu-west-1.amazonaws.com')))->upload_start('site.wpress');
} catch (\RuntimeException $e) {
	$threw = true;
	same_cl(
		'push with a read-only key gives generic advice on other S3 services',
		'Starting the upload failed (HTTP 403: Access Denied). The access key cannot write to this bucket. '
			. 'Give this key read and write access to this bucket.',
		(string) $e->getMessage()
	);
}

check_cl('generic endpoint push throws', $threw);

$threw = false;

try {
	(new S3Provider($reader_settings($read_only_storage($ignored), 'https://accountid.eu.r2.cloudflarestorage.com')))->upload_start('site.wpress');
} catch (\RuntimeException $e) {
	$threw = true;
	contains_cl('jurisdictional R2 endpoints are recognised', (string) $e->getMessage(), 'On Cloudflare R2');
}

check_cl('jurisdictional endpoint push throws', $threw);

$threw = false;

try {
	(new S3Provider($reader_settings($read_only_storage($ignored, '<Error><Message>Access Denied</Message></Error>'))))->upload_start('site.wpress');
} catch (\RuntimeException $e) {
	$threw = true;
	contains_cl('a denial without an error code is still explained', (string) $e->getMessage(), 'Object Read and Write');
}

check_cl('code-less denial throws', $threw);

$threw = false;

try {
	(new S3Provider($reader_settings($read_only_storage($ignored, '<Error><Message>Access Denied.</Message></Error>'))))->upload_start('site.wpress');
} catch (\RuntimeException $e) {
	$threw = true;
	contains_cl('a denial worded with a full stop is still explained', (string) $e->getMessage(), 'Object Read and Write');
}

check_cl('code-less denial with a full stop throws', $threw);

$threw = false;

try {
	(new S3Provider($reader_settings($read_only_storage($ignored, '<Error><Message>Request has expired</Message></Error>'))))->upload_start('site.wpress');
} catch (\RuntimeException $e) {
	$threw = true;
	same_cl('another code-less 403 gets no permission advice', 'Starting the upload failed (HTTP 403: Request has expired)', (string) $e->getMessage());
}

check_cl('code-less expired request throws', $threw);

// A bad signature is also a 403 and must not be reported as missing permission.
$misfit = new S3Provider($reader_settings(static function (string $method, string $url, array $headers, $body, int $timeout): array {
	return array(
		'status'  => 403,
		'headers' => array(),
		'body'    => '<Error><Code>SignatureDoesNotMatch</Code><Message>The request signature we calculated does not match.</Message></Error>',
		'error'   => '',
	);
}));

$threw = false;

try {
	$misfit->test();
} catch (\RuntimeException $e) {
	$threw = true;
	same_cl(
		'a bad signature is not called a permission problem',
		'Listing the bucket failed (HTTP 403: The request signature we calculated does not match.)',
		(string) $e->getMessage()
	);
}

check_cl('bad signature throws', $threw);

// Cancelling the probe is best-effort: a key allowed to start uploads but not
// to abort them still passes, and nothing was written.
$abort_calls  = array();
$abort_denied = array(
	'endpoint'   => 'http://s3.local',
	'bucket'     => 'mirror',
	'prefix'     => 'backups',
	'region'     => 'us-east-1',
	'access_key' => 'AKIATEST',
	'secret_key' => 's3cr3t',
	'http'       => static function (string $method, string $url, array $headers, $body, int $timeout) use (&$abort_calls): array {
		$abort_calls[] = $method . ' ' . $url;
		$query         = (string) parse_url($url, PHP_URL_QUERY);

		if ($method === 'DELETE') {
			return array( 'status' => 403, 'headers' => array(), 'body' => '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>', 'error' => '' );
		}

		if (strpos($query, 'uploads=') === 0) {
			return array( 'status' => 200, 'headers' => array(), 'body' => '<InitiateMultipartUploadResult><UploadId>probe-1</UploadId></InitiateMultipartUploadResult>', 'error' => '' );
		}

		return array( 'status' => 200, 'headers' => array(), 'body' => '<ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>', 'error' => '' );
	},
);

$threw = false;

try {
	(new S3Provider($abort_denied))->test();
} catch (\RuntimeException $e) {
	$threw = true;
}

check_cl('a refused abort does not fail the connection test', ! $threw);
same_cl('a refused abort is attempted once', 3, count($abort_calls));

// A transport failure on the write is reported as a write failure, not a success.
$unreachable = new S3Provider($reader_settings(static function (string $method, string $url, array $headers, $body, int $timeout): array {
	if ($method === 'GET') {
		return array( 'status' => 200, 'headers' => array(), 'body' => '<ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>', 'error' => '' );
	}

	return array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => 'cURL error 28: Operation timed out.' );
}));

$threw = false;

try {
	$unreachable->test();
} catch (\RuntimeException $e) {
	$threw = true;
	contains_cl('a write that cannot reach storage is reported', (string) $e->getMessage(), 'cannot write to it (HTTP 0: cURL error 28');
	check_cl('a transport failure gets no permission advice', strpos((string) $e->getMessage(), 'Object Read and Write') === false);
}

check_cl('unreachable write throws', $threw);

// The saved-settings path behind the Cloud page, REST and CLI reports the same.
$GLOBALS['mk_opt']['migratekit_cloud']['s3']['http'] = $read_only_storage($ignored);
$threw                                                 = false;

try {
	Manager::test('s3');
} catch (\RuntimeException $e) {
	$threw = true;
	contains_cl('Manager::test reports a read-only key', (string) $e->getMessage(), 'Backups cannot be pushed with these credentials');
}

check_cl('Manager::test throws for a read-only key', $threw);

// ---------------------------------------------------------------------------
echo "\n[4] Manager option semantics\n";

unset($GLOBALS['mk_opt']['migratekit_cloud']);

$saved = Manager::save('s3', array( 'endpoint' => 'http://s3.local', 'bucket' => 'b5', 'access_key' => 'A1', 'secret_key' => 'S1', 'region' => 'eu-west-1' ));
same_cl('settings stored raw', 'S1', $saved['secret_key']);

$masked = Manager::save('s3', array( 'endpoint' => 'http://s3.local', 'bucket' => 'b5', 'access_key' => 'A1', 'secret_key' => Manager::MASK, 'region' => 'eu-west-1' ));
same_cl('masked secret keeps stored value', 'S1', $masked['secret_key']);

$blank = Manager::save('s3', array( 'endpoint' => 'http://s3.local', 'bucket' => 'b5', 'access_key' => 'A1', 'secret_key' => '', 'region' => 'eu-west-1' ));
same_cl('blank secret keeps stored value', 'S1', $blank['secret_key']);

Manager::store_tokens('dropbox', array( 'access_token' => 'tok-1', 'refresh_token' => 'ref-1', 'expires_at' => 5000 ));
Manager::save('dropbox', array( 'client_id' => 'cid', 'client_secret' => 'cs', 'folder' => 'backups' ));

$stored   = Manager::settings();
$dropbox  = $stored['dropbox'];
same_cl('client id saved', 'cid', $dropbox['client_id']);
same_cl('tokens survive settings save', 'tok-1', $dropbox['access_token']);
same_cl('refresh token survives', 'ref-1', $dropbox['refresh_token']);

Manager::reset('dropbox');
same_cl('reset wipes provider', array(), Manager::settings()['dropbox']);

check_cl('unknown provider resolves null', Manager::provider('nope') === null);
check_cl('valid remote accepts plain name', Manager::valid_remote('site-2024.wpress'));
check_cl('valid remote rejects traversal', ! Manager::valid_remote('../evil.wpress'));
check_cl('valid remote rejects non archive', ! Manager::valid_remote('notes.txt'));

check_cl('ready without secret is false', ! ( new S3Provider(array( 'endpoint' => 'http://s3.local', 'bucket' => 'b5', 'access_key' => 'A1', 'secret_key' => '' )) )->is_ready());

// ---------------------------------------------------------------------------
echo "\n[5] Dropbox flow\n";

$drop_calls = new ClRecorder();

$dropfake = function (string $method, string $url, array $headers, $body, int $timeout) use ($drop_calls): array {
	$drop_calls->record($method, $url, $headers, $body);

	if (strpos($url, 'oauth2/token') !== false) {
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => json_encode(array( 'access_token' => 'droptok', 'refresh_token' => 'dropref', 'expires_in' => 14400 )),
		);
	}

	if (strpos($url, '/files/upload_session/start') !== false) {
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => json_encode(array( 'session_id' => 'sess-7' )),
		);
	}

	if (strpos($url, '/files/upload_session/append_v2') !== false) {
		return array( 'status' => 200, 'headers' => array(), 'body' => '' );
	}

	if (strpos($url, '/files/upload_session/finish') !== false) {
		return array( 'status' => 200, 'headers' => array(), 'body' => '{"name":"done.wpress"}' );
	}

	if (strpos($url, '/files/download') !== false) {
		$range = headers_get($headers, 'Range') ?? '';

		if (preg_match('/bytes=(\d+)-(\d+)/', $range, $match) === 1) {
			$content = '0123456789abcde';
			$start   = (int) $match[1];
			$end     = min((int) $match[2], strlen($content) - 1);

			if ($start >= strlen($content)) {
				return array( 'status' => 416, 'headers' => array(), 'body' => '' );
			}

			return array(
				'status'  => 206,
				'headers' => array(),
				'body'    => substr($content, $start, $end - $start + 1),
			);
		}
	}

	if (strpos($url, '/files/list_folder/continue') !== false) {
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => json_encode(array( 'has_more' => false, 'entries' => array( array( '.tag' => 'file', 'name' => 'gamma.wpress', 'size' => 9, 'client_modified' => '2024-05-01T00:00:00Z' ) ), 'cursor' => '' )),
		);
	}

	if (strpos($url, '/files/list_folder') !== false) {
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => json_encode(array( 'has_more' => true, 'cursor' => 'cur-2', 'entries' => array(
				array( '.tag' => 'folder', 'name' => 'subdir' ),
				array( '.tag' => 'file', 'name' => 'beta.wpress', 'size' => 12, 'client_modified' => '2024-04-01T00:00:00Z' ),
				array( '.tag' => 'file', 'name' => 'ignore.txt', 'size' => 2, 'client_modified' => '2024-03-01T00:00:00Z' ),
			) )),
		);
	}

	if (strpos($url, '/files/delete_v2') !== false) {
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => json_encode(array( 'metadata' => array( 'name' => 'gone' ) )),
		);
	}

	return array( 'status' => 404, 'headers' => array(), 'body' => '' );
};

$tables = array( 'dropbox' => array( 'client_id' => 'cid', 'client_secret' => 'cs', 'folder' => 'backups', 'http' => $dropfake ) );
$GLOBALS['mk_opt']['migratekit_cloud'] = $tables;

$dropbox = Manager::provider('dropbox');
check_cl('dropbox needs token before ready', $dropbox !== null && ! $dropbox->is_ready());

$dropbox->authorize_code('abc123');
same_cl('access token stored', 'droptok', Manager::settings()['dropbox']['access_token']);
check_cl('dropbox ready after token', $dropbox->is_ready());

$auth_url = $dropbox->authorize_url();
contains_cl('authorize includes redirect', $auth_url, 'redirect_uri=http%3A%2F%2Fadmin.test%2Fwp-admin%2Fadmin-post.php%3Faction%3Dmigratekit_cloud_dropbox_callback');
contains_cl('authorize requests offline tokens', $auth_url, 'token_access_type=offline');

$session = $dropbox->upload_start('beta.wpress');
same_cl('dropbox session id', 'sess-7', $session['session']);

$dropbox->upload_chunk($session, cl_local_file('0123456789'), 0, 10);
same_cl('cursor advanced', 10, $session['offset']);

$dropbox->upload_finish($session);

$finish = $drop_calls->call_hitting('upload_session/finish');
$arg    = headers_get((array) $finish['headers'], 'Dropbox-API-Arg') ?? '';
contains_cl('finish commits to folder', $arg, '"path":"\/backups\/beta.wpress"');
contains_cl('finish cursor complete', $arg, '"offset":10');

$append = $drop_calls->call_hitting('append_v2');
$arg    = headers_get((array) $append['headers'], 'Dropbox-API-Arg') ?? '';
contains_cl('append cursor starts at zero', $arg, '"offset":0');

// Listing paginates and filters.
$objects = $dropbox->list();
same_cl('dropbox list filters', array( 'beta.wpress', 'gamma.wpress' ), array_keys($objects));

// Ranged pull.
$session = $dropbox->download_start('beta.wpress');
$bytes   = $dropbox->download_chunk($session, 14, 8);
same_cl('dropbox tail slice', 'e', $bytes);

$bytes = $dropbox->download_chunk($session, 15, 8);
same_cl('dropbox past-end empty', '', $bytes);

$dropbox->delete('beta.wpress');
check_cl('delete issued to dropbox', $drop_calls->call_hitting('delete_v2') !== null);

$display = $dropbox->display();
same_cl('client secret masked', Manager::MASK, $display['client_secret']);
same_cl('account field wired', true, isset($display['connected']));

// ---------------------------------------------------------------------------
echo "\n[6] Google Drive flow\n";

$g_calls = new ClRecorder();

$gfake = function (string $method, string $url, array $headers, $body, int $timeout) use ($g_calls): array {
	$g_calls->record($method, $url, $headers, $body);

	if (strpos($url, 'googleapis.com/token') !== false) {
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => json_encode(array( 'access_token' => 'gtok', 'refresh_token' => 'gref', 'expires_in' => 3600 )),
		);
	}

	if (strpos($url, 'upload/drive/v3/files?') !== false) {
		return array(
			'status'  => 200,
			'headers' => array( 'location' => 'http://upload.local/session-42' ),
			'body'    => '',
		);
	}

	if (strpos($url, 'http://upload.local/session-42') !== false) {
		$range = headers_get($headers, 'Content-Range') ?? '';

		if (preg_match('/bytes=0-/',$range) === 1) {
			return array( 'status' => 308, 'headers' => array(), 'body' => '' );
		}

		return array( 'status' => 200, 'headers' => array(), 'body' => '{"id":"file-1"}' );
	}

	if (preg_match('#/files/\?#', $url) === 1 || preg_match('#/drive/v3/files\?#', $url) === 1) {
		$is_list = strpos($url, 'pageToken') !== false;

		if ($is_list) {
			return array(
				'status'  => 200,
				'headers' => array(),
				'body'    => json_encode(array(
					'files' => array( array( 'id' => 'file-9', 'name' => 'delta.wpress', 'size' => '7', 'modifiedTime' => '2024-06-01T00:00:00Z' ) ),
				)),
			);
		}

		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => json_encode(array( 'files' => array( array( 'id' => 'file-1', 'name' => 'beta.wpress', 'size' => '15' ) ) )),
		);
	}

	if (strpos($url, 'alt=media') !== false) {
		$range = headers_get($headers, 'Range') ?? '';
		$content = '0123456789abcde';

		if (preg_match('/bytes=(\d+)-(\d+)/', $range, $match) === 1) {
			$start = (int) $match[1];
			$end   = min((int) $match[2], strlen($content) - 1);

			if ($start >= strlen($content)) {
				return array( 'status' => 416, 'headers' => array(), 'body' => '' );
			}

			return array( 'status' => 206, 'headers' => array(), 'body' => substr($content, $start, $end - $start + 1) );
		}
	}

	if ($method === 'GET' && preg_match('#/drive/v3/about#', $url) === 1) {
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => json_encode(array( 'user' => array( 'emailAddress' => 'me@example.test' ) )),
		);
	}

	if ($method === 'DELETE') {
		return array( 'status' => 204, 'headers' => array(), 'body' => '' );
	}

	return array( 'status' => 500, 'headers' => array(), 'body' => '' );
};

$GLOBALS['mk_opt']['migratekit_cloud'] = array(
	'gdrive' => array( 'client_id' => 'gid', 'client_secret' => 'gsec', 'folder' => 'fldr-9', 'http' => $gfake ),
);

$drive = Manager::provider('gdrive');
check_cl('drive needs token before ready', $drive !== null && ! $drive->is_ready());

$drive->authorize_code('abc123');
same_cl('drive token stored', 'gtok', Manager::settings()['gdrive']['access_token']);
check_cl('drive ready after token', $drive->is_ready());

$drive->test();

$g_call_about = $g_calls->call_hitting('/drive/v3/about');
check_cl('about queried', $g_call_about !== null);
same_cl('account stored', 'me@example.test', Manager::settings()['gdrive']['account']);

$session = $drive->upload_start('beta.wpress');
same_cl('drive upload url', 'http://upload.local/session-42', $session['upload_url']);

$metadata  = $g_calls->call_hitting('uploadType=resumable');
$meta_body = (string) $metadata['body'];
contains_cl('metadata names the archive', $meta_body, '"name":"beta.wpress"');
contains_cl('metadata targets folder', $meta_body, '"parents":["fldr-9"]');

// Listing resolves through the same search endpoint shape.
$objects = $drive->list();
same_cl('drive list returns archives', array( 'beta.wpress' ), array_keys($objects));

$bytes = $drive->upload_chunk($session, cl_local_file($remote_content), 0, $drive->chunk_size());
same_cl('first chunk sent', 8 * 1024 * 1024, $bytes);
same_cl('pending after 308', false, $session['done']);

$range_header = null;

foreach ($g_calls->calls as $call) {
	$value = headers_get((array) $call['headers'], 'Content-Range');

	if ($value !== null && strpos($call['url'], 'session-42') !== false) {
		$range_header = $value;
		break;
	}
}

same_cl('content-range covers the part', 'bytes=0-8388607/8388609', $range_header);

$bytes = $drive->upload_chunk($session, cl_local_file($remote_content), 8 * 1024 * 1024, $drive->chunk_size());
same_cl('final chunk sent', 1, $bytes);
same_cl('done after 2xx', true, $session['done']);

$finish_ok = true;

try {
	$drive->upload_finish($session);
} catch (\RuntimeException $e) {
	$finish_ok = false;
}

check_cl('finish after completion is silent', $finish_ok);

// Ranged pull by id.
$session = $drive->download_start('beta.wpress');
same_cl('drive id resolved', 'file-1', $session['id']);
same_cl('drive size resolved', 15, $session['size']);

$bytes = $drive->download_chunk($session, 14, 8);
same_cl('drive tail', 'e', $bytes);

$bytes = $drive->download_chunk($session, 15, 8);
same_cl('drive past-end empty', '', $bytes);

$drive->delete('beta.wpress');
check_cl('delete issued to drive', array_reduce($g_calls->calls, static function ($carry, $call) {
	return $carry || $call['method'] === 'DELETE';
}, false));

// ---------------------------------------------------------------------------
echo "\n[7] Push pipeline (resumable)\n";

$GLOBALS['mk_opt']['migratekit_cloud'] = array(
	's3' => array(
		'endpoint'   => 'http://s3.local',
		'bucket'     => 'mirror',
		'prefix'     => 'backups',
		'region'     => 'us-east-1',
		'access_key' => 'AKIATEST',
		'secret_key' => 's3cr3t',
		'http'       => $s3fake,
	),
);

// Section [3] populated the call log already; pipelines get a clean slate.
$s3calls     = array();
$source_file = cl_local_file($remote_content);
$storage_dir = $GLOBALS['cl_tmp'] . '/job-1';

mkdir($storage_dir, 0777, true);

$GLOBALS['cl_msgs_seen'] = array();

$GLOBALS['cl_capture'] = static function (string $message, ?int $percent = null): void {
	$GLOBALS['cl_msgs_seen'][] = $message;
};

$state = array(
	'cloud'       => 's3',
	'remote'      => 'push-me.wpress',
	'source'      => $source_file,
	'size'        => strlen($remote_content),
	'storage_dir' => $storage_dir,
	'offset'      => 0,
	'stage'       => 0,
	'percent'     => 0,
	'message'     => '',
);

$done  = false;
$guard = 0;

do {
	$pipeline = PushPipeline::create();
	$ctx      = new PipelineContext(null, cl_site(), $GLOBALS['cl_capture'], 0.000001);
	$done     = $pipeline->advance($state, $ctx);

	if (++$guard > 200) {
		break;
	}
} while (! $done);

check_cl('push pipeline completes across requests', $done);
check_cl('push resumed at least once', $guard > 2);
same_cl('push offset final', strlen($remote_content), $state['offset']);

$upload_calls = array_values(array_filter($s3calls, static function ($call) {
	return $call['method'] === 'POST' && strpos($call['url'], 'uploads=') !== false;
}));
check_cl('initiate ran exactly once', count($upload_calls) === 1);

$complete_calls = array_values(array_filter($s3calls, static function ($call) {
	return $call['method'] === 'POST' && strpos($call['url'], 'uploadId=mk-upload-1') !== false && strpos($call['url'], 'uploads=') === false;
}));
check_cl('completion ran exactly once', count($complete_calls) === 1);

echo "\n[8] Pull pipeline publishes into backups\n";

$backups_dir = $GLOBALS['cl_tmp'] . '/backups';
mkdir($backups_dir, 0777, true);

$GLOBALS['mk_opt']['migratekit_backups_path'] = $backups_dir;

$storage_dir = $GLOBALS['cl_tmp'] . '/job-2';
mkdir($storage_dir, 0777, true);

$state = array(
	'cloud'       => 's3',
	'remote'      => 'pull-me.wpress',
	'storage_dir' => $storage_dir,
	'size'        => 0,
	'offset'      => 0,
	'stage'       => 0,
	'percent'     => 0,
	'message'     => '',
);

$done  = false;
$guard = 0;

do {
	$pipeline = PullPipeline::create();
	$ctx      = new PipelineContext(null, cl_site(), $GLOBALS['cl_capture'], 0.000001);
	$done     = $pipeline->advance($state, $ctx);

	if (++$guard > 200) {
		break;
	}
} while (! $done);

check_cl('pull pipeline completes', $done);
check_cl('pull published the archive', ! empty($state['published']));
check_cl('archive exists in backups', is_file($backups_dir . '/pull-me.wpress'));
same_cl('pulled bytes identical', $remote_content, (string) file_get_contents($backups_dir . '/pull-me.wpress'));
check_cl('working copy cleaned up', ! is_file($storage_dir . '/pull.wpress'));

// ---------------------------------------------------------------------------
echo "\n[9] Transport normalisation\n";

$result = Transport::send('GET', 'http://fake.test', array(), null, 5, static function () {
	return array(
		'status'  => '201',
		'headers' => array( 'X-Mixed' => array( 'one', 'two' ), 'PLAIN' => 7 ),
		'body'    => 42,
		'error'   => null,
	);
});

same_cl('status coerced to int', 201, $result['status']);
same_cl('array headers joined', 'one, two', $result['headers']['x-mixed']);
same_cl('scalar headers stringified', '7', $result['headers']['plain']);
same_cl('body stringified', '42', $result['body']);

$result = Transport::send('GET', 'http://fake.test', array(), null, 5, static function () {
	return 'bogus';
});

same_cl('invalid transport result is safe', 0, $result['status']);
check_cl('error recorded', $result['error'] !== '');

// ---------------------------------------------------------------------------
echo "\n[10] Easy connect: Dropbox paste-code and Google service accounts\n";

// The embedded public Dropbox app key must live ahead of provider calls.
if (! defined('MIGRATEKIT_DROPBOX_APP_ID')) {
	define('MIGRATEKIT_DROPBOX_APP_ID', 'migratekit-public-app');
}

$easy_calls = new ClRecorder();

$easyfake = function (string $method, string $url, array $headers, $body) use ($easy_calls): array {
	$easy_calls->record($method, $url, $headers, $body);

	if (strpos($url, 'dropboxapi.com/oauth2/token') !== false) {
		parse_str((string) $body, $sent);

		if (( $sent['grant_type'] ?? '' ) === 'authorization_code') {
			return array(
				'status'  => 200,
				'headers' => array(),
				'body'    => json_encode(array( 'access_token' => 'paste-tok', 'refresh_token' => 'paste-ref', 'expires_in' => 14400 )),
			);
		}

		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => json_encode(array( 'access_token' => 'fresh-tok', 'expires_in' => 14400 )),
		);
	}

	if ($method === 'POST' && strpos($url, '/users/get_current_account') !== false) {
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => json_encode(array( 'email' => 'easy@example.test' )),
		);
	}

	return array( 'status' => 404, 'headers' => array(), 'body' => '' );
};

$GLOBALS['mk_opt']['migratekit_cloud'] = array(
	'dropbox' => array( 'folder' => 'backups', 'http' => $easyfake ),
);

$easy = Manager::provider('dropbox');

check_cl('easy mode with no stored secret', $easy !== null && $easy->easy_mode());
same_cl('embedded app key picked up', 'migratekit-public-app', $easy->app_key());
check_cl('easy connect offered', Manager::easy_connect('dropbox'));

$start = Manager::connecting('dropbox');
check_cl('easy start returns a url', $start['url'] !== '');
contains_cl('easy url carries app key', $start['url'], 'client_id=migratekit-public-app');
contains_cl('easy url binds s256 challenge', $start['url'], 'code_challenge_method=S256');
contains_cl('easy url requests offline tokens', $start['url'], 'token_access_type=offline');
check_cl('easy url has no redirect', strpos($start['url'], 'redirect_uri=') === false);
same_cl('easy start flagged for paste', true, $start['paste']);

$saved_state = $start['state'];
$verifier_box = 'migratekit_cloud_dropbox_' . hash('sha256', $saved_state);
check_cl('verifier stored with state', strlen((string) get_transient($verifier_box)) === 64);

$challenge_expected = rtrim(strtr(base64_encode(hash('sha256', (string) get_transient($verifier_box), true)), '+/', '-_'), '=');
contains_cl('challenge derives from stored verifier', $start['url'], 'code_challenge=' . $challenge_expected);

Manager::exchanging('dropbox', 'paste-code-1', $saved_state);

$exchange = $easy_calls->call_hitting('oauth2/token');
parse_str((string) $exchange['body'], $sent);
same_cl('exchange code travels', 'paste-code-1', $sent['code'] ?? '');
same_cl('exchange uses the app key', 'migratekit-public-app', $sent['client_id'] ?? '');
check_cl('exchange carries the verifier', strlen($sent['code_verifier'] ?? '') === 64);
check_cl('exchange is keyless', strpos((string) $exchange['body'], 'client_secret') === false);
check_cl('exchange has no redirect', strpos((string) $exchange['body'], 'redirect_uri') === false);
same_cl('paste token stored', 'paste-tok', Manager::settings()['dropbox']['access_token']);
check_cl('verifier single-use', get_transient($verifier_box) === false);

$replayed = '';
try {
	Manager::exchanging('dropbox', 'paste-code-2', $saved_state);
} catch (RuntimeException $caught) {
	$replayed = $caught->getMessage();
}

check_cl('replayed state refused', $replayed !== '');

$replayed = '';
try {
	Manager::exchanging('dropbox', 'paste-code-3', 'forged-state');
} catch (RuntimeException $caught) {
	$replayed = $caught->getMessage();
}

check_cl('forged state refused', $replayed !== '');

// Easy-mode tokens refresh with the app key alone.
$refresh_calls = new ClRecorder();

$refreshfake = function (string $method, string $url, array $headers, $body) use ($refresh_calls, &$account_had_401): array {
	$refresh_calls->record($method, $url, $headers, $body);

	if (strpos($url, 'dropboxapi.com/oauth2/token') !== false) {
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => json_encode(array( 'access_token' => 'fresh-tok', 'expires_in' => 14400 )),
		);
	}

	if (strpos($url, '/users/get_current_account') !== false) {
		if (! $account_had_401) {
			$account_had_401 = true;

			return array( 'status' => 401, 'headers' => array(), 'body' => '' );
		}

		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => json_encode(array( 'email' => 'easy@example.test' )),
		);
	}

	return array( 'status' => 404, 'headers' => array(), 'body' => '' );
};

$account_had_401 = false;

$GLOBALS['mk_opt']['migratekit_cloud'] = array(
	'dropbox' => array( 'folder' => '', 'access_token' => 'stale', 'refresh_token' => 'stay', 'http' => $refreshfake ),
);

$easy2 = Manager::provider('dropbox');
check_cl('easy tokens ready on embedded key', $easy2 !== null && $easy2->is_ready());
$easy2->test();

$refresh_post = null;

foreach ($refresh_calls->calls as $call) {
	if (strpos($call['url'], 'oauth2/token') !== false) {
		parse_str($call['body'], $sent2);
		same_cl('easy refresh keeps codes', 'refresh_token', $sent2['grant_type'] ?? '');
		same_cl('easy refresh uses app key', 'migratekit-public-app', $sent2['client_id'] ?? '');
		check_cl('easy refresh without secret', strpos($call['body'], 'client_secret') === false);
	}
}

$last_account = null;

foreach ($refresh_calls->calls as $call) {
	if (strpos($call['url'], 'get_current_account') !== false) {
		$last_account = $call;
	}
}

same_cl('retry after refresh carries fresh token', 'Bearer fresh-tok', (string) headers_get((array) $last_account['headers'], 'Authorization'));
// ---------------------------------------------------------------------------
echo "\n[10b] Google service account (no OAuth app)\n";

$key = openssl_pkey_new(array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ));
openssl_pkey_export($key, $private_pem);
$public_pem = openssl_pkey_get_details($key)['key'];

$sa_json_raw = json_encode(
	array(
		'client_email' => 'migratekit@backups.iam.gserviceaccount.com',
		'private_key'  => $private_pem,
	)
);

$parsed = DriveProvider::settings(array( 'service_account' => (string) $sa_json_raw ));
same_cl('service json email trimmed', 'migratekit@backups.iam.gserviceaccount.com', $parsed['client_email']);
check_cl('service json key parsed', strpos($parsed['private_key'], 'PRIVATE KEY') !== false);

$bad = '';
try {
	DriveProvider::settings(array( 'service_account' => '{"client_email":"x"}' ));
} catch (RuntimeException $caught) {
	$bad = $caught->getMessage();
}

check_cl('incomplete service json refused', $bad !== '');

$sa_calls = new ClRecorder();

$safake = function (string $method, string $url, array $headers, $body) use ($sa_calls): array {
	$sa_calls->record($method, $url, $headers, $body);

	if (strpos($url, 'oauth2.googleapis.com/token') !== false) {
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => json_encode(array( 'access_token' => 'sa-tok', 'expires_in' => 3600 )),
		);
	}

	if (strpos($url, '/drive/v3/about') !== false) {
		return array(
			'status'  => 200,
			'headers' => array(),
			'body'    => json_encode(array( 'user' => array( 'emailAddress' => 'migratekit@backups.iam.gserviceaccount.com' ) )),
		);
	}

	return array( 'status' => 404, 'headers' => array(), 'body' => '' );
};

$GLOBALS['mk_opt']['migratekit_cloud'] = array(
	'gdrive' => array( 'http' => $safake ),
);

$fresh_drive = Manager::provider('gdrive');
same_cl('fresh drive defaults to service mode', 'service', $fresh_drive->auth_mode());
same_cl('fresh drive is not ready yet', false, $fresh_drive->is_ready());

$GLOBALS['mk_opt']['migratekit_cloud'] = array(
	'gdrive' => array(
		'client_email' => 'migratekit@backups.iam.gserviceaccount.com',
		'private_key'  => $private_pem,
		'folder'       => 'shared-folder-id',
		'http'         => $safake,
	),
);

$sa = Manager::provider('gdrive');

check_cl('service account ready without oauth', $sa !== null && $sa->is_ready());
same_cl('service account mode flagged', 'service', $sa->auth_mode());
check_cl('service account has no authorize url', $sa->authorize_url() === '');
check_cl('service account connect is a no-op', $sa->connect()['url'] === '');

$sa->test();

$token_call = $sa_calls->call_hitting('oauth2.googleapis.com/token');
check_cl('service token minted on first use', $token_call !== null);
parse_str((string) $token_call['body'], $sent);
same_cl('service grant is jwt-bearer', 'urn:ietf:params:oauth:grant-type:jwt-bearer', $sent['grant_type'] ?? '');
check_cl('assertion present', strpos($sent['assertion'] ?? '', '.') !== false);

$parts = explode('.', (string) $sent['assertion']);
check_cl('assertion is a compact jwt', count($parts) === 3);

$claims = null;
$ok_sig = false;

if (count($parts) === 3) {
	$claims = json_decode(base64_decode(strtr((string) $parts[1], '-_', '+/')), true);
	$header_json = json_decode(base64_decode(strtr((string) $parts[0], '-_', '+/')), true);
	same_cl('jwt alg is rs256', 'RS256', $header_json['alg'] ?? '');
	same_cl('jwt issuer is the service account', 'migratekit@backups.iam.gserviceaccount.com', $claims['iss'] ?? '');
	same_cl('jwt audience is google token', 'https://oauth2.googleapis.com/token', $claims['aud'] ?? '');
	contains_cl('jwt scope is drive', (string) ( $claims['scope'] ?? '' ), 'auth/drive');

	$published = openssl_pkey_get_public($public_pem);
	$ok_sig    = openssl_verify((string) $parts[0] . '.' . (string) $parts[1], base64_decode(strtr((string) $parts[2], '-_', '+/')), $published, OPENSSL_ALGO_SHA256) === 1;
}

check_cl('service signature verifies rs256', $ok_sig);

$about = $sa_calls->call_hitting('/drive/v3/about');
same_cl('minted token authorises calls', 'Bearer sa-tok', headers_get((array) $about['headers'], 'Authorization'));

$expiry = Manager::settings()['gdrive']['expires_at'] ?? 0;
check_cl('minted expiry remembered', $expiry > time() + 3000);

$mint_count = 0;

foreach ($sa_calls->calls as $call) {
	if (strpos($call['url'], 'oauth2.googleapis.com/token') !== false) {
		++$mint_count;
	}
}

same_cl('token reused while fresh', 1, $mint_count);

$display = $sa->display();
same_cl('service key displayed masked', Manager::MASK, $display['service_account']);
same_cl('service display shows the email', 'migratekit@backups.iam.gserviceaccount.com', $display['client_email']);
same_cl('service display mode', 'service', $display['auth_mode']);

// Re-save with the masked textarea keeps the parsed pair.
$kept = Manager::save('gdrive', array( 'service_account' => Manager::MASK, 'folder' => 'shared-folder-id' ));
same_cl('resave keeps the service email', 'migratekit@backups.iam.gserviceaccount.com', $kept['client_email']);
check_cl('resave keeps the private key', strpos($kept['private_key'], 'PRIVATE KEY') !== false);

// ---------------------------------------------------------------------------
echo "\n[11] Transfers a reload cut off: lost sessions, stale offsets, overlong files\n";

$GLOBALS['cl_capture'] = static function (string $message, ?int $percent = null): void {
	$GLOBALS['cl_msgs_seen'][] = $message;
};

/**
 * Storage server that remembers its multipart uploads and the bytes it holds.
 */
class ClFakeBucket {

	/** @var array<string,string> Upload id => bytes held. */
	public $uploads = array();

	/** @var array<string,string> Finished objects: key => bytes. */
	public $objects = array();

	/** @var array<int,array{method:string,url:string,headers:array<string,string>,body:string}> */
	public $calls = array();

	/** @var int */
	private $sequence = 0;

	public function __invoke(string $method, string $url, array $headers, $body, int $timeout): array {
		$this->calls[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => is_string($body) ? $body : '',
		);

		$parsed = parse_url($url);

		parse_str((string) ( $parsed['query'] ?? '' ), $query);

		$segments = explode('/', ltrim((string) ( $parsed['path'] ?? '' ), '/'));
		$key      = implode('/', array_slice($segments, 1));

		if (isset($query['uploads'])) {
			++$this->sequence;

			$id = 'up-' . $this->sequence;

			$this->uploads[ $id ] = '';

			return array(
				'status'  => 200,
				'headers' => array(),
				'body'    => '<InitiateMultipartUploadResult><UploadId>' . $id . '</UploadId></InitiateMultipartUploadResult>',
			);
		}

		if (isset($query['uploadId']) && ! isset($this->uploads[ $query['uploadId'] ])) {
			return array(
				'status'  => 404,
				'headers' => array(),
				'body'    => '<Error><Code>NoSuchUpload</Code><Message>The specified upload does not exist.</Message></Error>',
			);
		}

		if (isset($query['partNumber'])) {
			$this->uploads[ $query['uploadId'] ] .= is_string($body) ? $body : '';

			return array( 'status' => 200, 'headers' => array( 'etag' => '"etag-' . $query['partNumber'] . '"' ), 'body' => '' );
		}

		if ($method === 'POST') {
			$this->objects[ $key ] = $this->uploads[ $query['uploadId'] ];

			unset($this->uploads[ $query['uploadId'] ]);

			return array( 'status' => 200, 'headers' => array(), 'body' => '<CompleteMultipartUploadResult></CompleteMultipartUploadResult>' );
		}

		if ($method === 'DELETE') {
			unset($this->uploads[ $query['uploadId'] ]);

			return array( 'status' => 204, 'headers' => array(), 'body' => '' );
		}

		$content = $this->objects[ $key ] ?? null;

		if ($method === 'HEAD') {
			return array(
				'status'  => $content === null ? 404 : 200,
				'headers' => $content === null ? array() : array( 'content-length' => (string) strlen($content) ),
				'body'    => '',
			);
		}

		if ($method === 'GET' && $content !== null && preg_match('/bytes=(\d+)-(\d+)/', (string) headers_get($headers, 'Range'), $match) === 1) {
			$start = (int) $match[1];

			if ($start >= strlen($content)) {
				return array( 'status' => 416, 'headers' => array(), 'body' => '' );
			}

			$end = min((int) $match[2], strlen($content) - 1);

			return array( 'status' => 206, 'headers' => array(), 'body' => substr($content, $start, $end - $start + 1) );
		}

		return array( 'status' => 500, 'headers' => array(), 'body' => '<Error><Message>unhandled ' . $method . '</Message></Error>' );
	}

	/**
	 * @param string $needle Literal looked for in every call's method and url.
	 * @return int
	 */
	public function count(string $needle): int {
		$total = 0;

		foreach ($this->calls as $call) {
			if (strpos($call['method'] . ' ' . $call['url'], $needle) !== false) {
				++$total;
			}
		}

		return $total;
	}
}

/** Bucket whose sessions vanish as soon as they open. */
final class ClVanishingBucket extends ClFakeBucket {

	public function __invoke(string $method, string $url, array $headers, $body, int $timeout): array {
		$response = parent::__invoke($method, $url, $headers, $body, $timeout);

		if (strpos($url, 'uploads=') !== false) {
			$this->uploads = array();
		}

		return $response;
	}
}

/**
 * Run a pipeline in tiny slices, as a browser would across requests.
 *
 * @param string $pipeline_class Pipeline with a create() factory.
 * @param array  $state         Pipeline state (by reference).
 * @param int    $limit         Slices before the run is given up.
 */
function cl_steps(string $pipeline_class, array &$state, int $limit = 400): bool {
	$done  = false;
	$slice = 0;

	do {
		$done = cl_slice($pipeline_class, $state);
	} while ( ! $done && ++$slice < $limit );

	return $done;
}

/**
 * One request: long enough to start a stage and stop after its first chunk.
 *
 * @param string $pipeline_class Pipeline with a create() factory.
 * @param array  $state          Pipeline state (by reference).
 */
function cl_slice(string $pipeline_class, array &$state): bool {
	return $pipeline_class::create()->advance($state, new PipelineContext(null, cl_site(), $GLOBALS['cl_capture'], 0.002));
}

$bucket = new ClFakeBucket();

$GLOBALS['mk_opt']['migratekit_cloud'] = array(
	's3' => array(
		'endpoint'   => 'http://s3.local',
		'bucket'     => 'mirror',
		'prefix'     => 'backups',
		'region'     => 'us-east-1',
		'access_key' => 'AKIATEST',
		'secret_key' => 's3cr3t',
		'http'       => $bucket,
	),
);

echo "[11a] A push whose upload session expired starts over and still lands whole\n";

$push_source = cl_local_file($remote_content);
$push_state  = array(
	'cloud'       => 's3',
	'remote'      => 'again.wpress',
	'source'      => $push_source,
	'size'        => strlen($remote_content),
	'storage_dir' => $GLOBALS['cl_tmp'] . '/job-restart',
	'offset'      => 0,
	'stage'       => 0,
	'percent'     => 0,
	'message'     => '',
);

mkdir($push_state['storage_dir'], 0777, true);

// First request: the session opens and one part goes up.
cl_slice('MigrateKit\\Cloud\\PushPipeline', $push_state);

check_cl('part one is on the bucket before the cut', strlen($bucket->uploads[ $push_state['session']['upload_id'] ] ?? '') === S3Provider::CHUNK);
same_cl('slice stops after one part', S3Provider::CHUNK, (int) $push_state['offset']);

// What the Runner's status callback would have blended in for one part in three stages.
$push_state['percent'] = 66;

// The session dies while the job waits: it expired, or a lifecycle rule aborted it.
unset($bucket->uploads[ $push_state['session']['upload_id'] ]);

$done = cl_steps('MigrateKit\\Cloud\\PushPipeline', $push_state);

check_cl('push completes after the session was lost', $done);
same_cl('push restarted from byte zero to the whole file', strlen($remote_content), (int) $push_state['offset']);
same_cl('bucket holds the whole archive', $remote_content, $bucket->objects['backups/again.wpress'] ?? null);
same_cl('two upload sessions were opened', 2, $bucket->count('uploads='));
same_cl('the dead session was discarded', 1, $bucket->count('DELETE '));
check_cl('nothing is left half-uploaded on the bucket', $bucket->uploads === array());
same_cl('the restart took the progress back with it', 0, (int) $push_state['percent']);
check_cl(
	'the restart was reported',
	in_array('The upload to S3-compatible storage could not be continued — starting it again from the beginning…', $GLOBALS['cl_msgs_seen'], true)
);

echo "[11b] A session that keeps disappearing fails the step instead of looping\n";

$vanishing = new ClVanishingBucket();

$GLOBALS['mk_opt']['migratekit_cloud']['s3']['http'] = $vanishing;

$loop_state = $push_state;

$loop_state['storage_dir'] = $GLOBALS['cl_tmp'] . '/job-loop';
$loop_state['remote']      = 'loop.wpress';
$loop_state['offset']      = 0;
$loop_state['stage']       = 1;

// A session from an earlier slice, already gone.
$loop_state['session'] = array( 'upload_id' => 'up-gone', 'remote' => 'loop.wpress', 'parts' => array(), 'next_part' => 1 );

mkdir($loop_state['storage_dir'], 0777, true);

$opened_before = $vanishing->count('uploads=');
$loop_error    = '';

try {
	// One request: the lost session is replaced once, then the replacement is gone too.
	cl_slice('MigrateKit\\Cloud\\PushPipeline', $loop_state);
} catch (\RuntimeException $caught) {
	$loop_error = $caught->getMessage();
}

check_cl('a session that keeps vanishing ends the step as a failure', $loop_error !== '');
check_cl('the failure names the lost session', strpos($loop_error, 'no longer known') !== false);
same_cl('exactly one replacement session per step', 1, $vanishing->count('uploads=') - $opened_before);

$GLOBALS['mk_opt']['migratekit_cloud']['s3']['http'] = $bucket;

echo "[11c] A download resumes exactly where the job saved, never past it\n";

$small = '0123456789abcdefghij';

$bucket->objects['backups/cut.wpress'] = $small;

$pull_state = array(
	'cloud'       => 's3',
	'remote'      => 'cut.wpress',
	'storage_dir' => $GLOBALS['cl_tmp'] . '/job-cut',
	'target'      => $GLOBALS['cl_tmp'] . '/job-cut/pull.wpress',
	'size'        => strlen($small),
	'offset'      => 8,
	'stage'       => 0,
	'percent'     => 40,
	'message'     => '',
	'session'     => array( 'remote' => 'cut.wpress', 'size' => strlen($small) ),
);

mkdir($pull_state['storage_dir'], 0777, true);

$transfer = new Pipeline(
	array(
		new \MigrateKit\Cloud\Stages\PullTransferStage(),
	)
);

// The request that was cut off had written far past the offset the job saved.
file_put_contents($pull_state['target'], $small . 'BYTES-WRITTEN-PAST-THE-SAVED-OFFSET');

$bucket->calls = array();

check_cl('download finishes', $transfer->advance($pull_state, new PipelineContext(null, cl_site(), $GLOBALS['cl_capture'], 0.0)));
same_cl('bytes past the saved offset are dropped, not kept twice', $small, (string) file_get_contents($pull_state['target']));
same_cl('the remote was asked for the rest only', sprintf('bytes=%d-%d', 8, 8 + S3Provider::CHUNK - 1), call_first_range($bucket->calls));

// The bytes already downloaded were lost (a cleanup removed them): take the object from the top.
file_put_contents($pull_state['target'], substr($small, 0, 5));

$pull_state['offset'] = 8;
$pull_state['stage']  = 0;
$bucket->calls        = array();

check_cl('download finishes after its bytes were lost', $transfer->advance($pull_state, new PipelineContext(null, cl_site(), $GLOBALS['cl_capture'], 0.0)));
same_cl('a lost partial file is downloaded whole again', $small, (string) file_get_contents($pull_state['target']));
same_cl('the remote was asked from byte zero', sprintf('bytes=0-%d', S3Provider::CHUNK - 1), call_first_range($bucket->calls));
same_cl('offset is the whole object', strlen($small), (int) $pull_state['offset']);

// An object shorter than the size announced at the start must not be published as a backup.
$bucket->objects['backups/cut.wpress'] = 'only-half';

$pull_state['offset'] = 0;
$pull_state['stage']  = 0;
$bucket->calls        = array();
$early                = '';

try {
	$transfer->advance($pull_state, new PipelineContext(null, cl_site(), $GLOBALS['cl_capture'], 0.0));
} catch (\RuntimeException $caught) {
	$early = $caught->getMessage();
}

check_cl('a download that ends early fails', strpos($early, 'stopped early') !== false);
check_cl('the early end names what arrived', strpos($early, '9 B of 20 B') !== false);

echo "[11d] Publishing survives a cut between the move and the save\n";

$publish = new Pipeline(
	array(
		new \MigrateKit\Cloud\Stages\PullFinishStage(),
	)
);

$published = $backups_dir . DIRECTORY_SEPARATOR . 'cut.wpress';

file_put_contents($published, $small);

@unlink($pull_state['target']);

$pull_state['offset'] = strlen($small);
$pull_state['stage']  = 0;

check_cl('a backup already moved into place is accepted', $publish->advance($pull_state, new PipelineContext(null, cl_site(), $GLOBALS['cl_capture'], 0.0)));
check_cl('the job points at the published archive', realpath((string) $pull_state['archive_path']) === realpath($published));

file_put_contents($published, 'an older backup with the same name');

$wrong = '';

$pull_state['stage'] = 0;

try {
	$publish->advance($pull_state, new PipelineContext(null, cl_site(), $GLOBALS['cl_capture'], 0.0));
} catch (\RuntimeException $caught) {
	$wrong = $caught->getMessage();
}

check_cl('an unrelated file of the same name is not passed off as the download', strpos($wrong, 'was not written') !== false);

@unlink($published);

echo "[11e] Dropbox: the session is ahead of the job, gone, or simply failing\n";

$dbx = array( 'held' => 0, 'refusal' => null, 'bytes' => '' );

$dbxfake = function (string $method, string $url, array $headers, $body, int $timeout) use (&$dbx): array {
	if (strpos($url, '/files/upload_session/start') !== false) {
		return array( 'status' => 200, 'headers' => array(), 'body' => '{"session_id":"sess-9"}' );
	}

	if (strpos($url, '/files/upload_session/append_v2') !== false) {
		if ($dbx['refusal'] !== null) {
			$refusal        = $dbx['refusal'];
			$dbx['refusal'] = null;

			return $refusal;
		}

		$arg    = json_decode((string) headers_get($headers, 'Dropbox-API-Arg'), true);
		$cursor = (int) ( $arg['cursor']['offset'] ?? -1 );

		// Dropbox only accepts an append at exactly the offset its session is at.
		if ($cursor !== $dbx['held']) {
			return array(
				'status'  => 400,
				'headers' => array( 'dropbox-api-result' => '{"error":{"lookup_failed":{"correct_offset":' . $dbx['held'] . '}}}' ),
				'body'    => '',
			);
		}

		$dbx['held']  += strlen($body);
		$dbx['bytes'] .= $body;

		return array( 'status' => 200, 'headers' => array(), 'body' => '' );
	}

	if (strpos($url, '/files/upload_session/finish') !== false) {
		return array( 'status' => 200, 'headers' => array(), 'body' => '{"name":"done.wpress"}' );
	}

	return array( 'status' => 404, 'headers' => array(), 'body' => '' );
};

$GLOBALS['mk_opt']['migratekit_cloud'] = array(
	'dropbox' => array(
		'client_id'     => 'cid',
		'client_secret' => 'cs',
		'folder'        => 'backups',
		'access_token'  => 'tok',
		'http'          => $dbxfake,
	),
);

$dropbox = Manager::provider('dropbox');
$thirty  = str_repeat('a', 10) . str_repeat('b', 10) . str_repeat('c', 10);
$ten     = cl_local_file($thirty);

$session = $dropbox->upload_start('resume.wpress');

$dropbox->upload_chunk($session, $ten, 0, 10);

// What the job saved after that first chunk.
$saved = $session;

$dropbox->upload_chunk($session, $ten, 10, 10);
$dropbox->upload_chunk($session, $ten, 20, 10);

same_cl('three chunks appended', 30, $dbx['held']);

// The job resumes from its saved copy while Dropbox is already at 30.
same_cl('a stale chunk sends nothing', 0, $dropbox->upload_chunk($saved, $ten, 10, 10));
same_cl('the session takes the offset Dropbox reported', 30, $saved['offset']);

// The same answer with the offset as a bare number in the body.
$dbx['held']     = 20;
$saved['offset'] = 10;

$dbx['refusal'] = array(
	'status'  => 400,
	'headers' => array(),
	'body'    => '{"error_summary":"lookup_failed/incorrect_offset/..","error":{"lookup_failed":20}}',
);

same_cl('a bare offset is understood too', 0, $dropbox->upload_chunk($saved, $ten, 10, 10));
same_cl('session offset follows', 20, $saved['offset']);

// A session that expired: there is no position to resume from.
$lost = false;

$dbx['refusal'] = array(
	'status'  => 409,
	'headers' => array(),
	'body'    => '{"error_summary":"lookup_failed/not_found/..","error":{"lookup_failed":{".tag":"not_found"}}}',
);

try {
	$dropbox->upload_chunk($saved, $ten, 20, 10);
} catch (MigrateKit\Cloud\SessionLostException $caught) {
	$lost = true;
}

check_cl('an expired Dropbox session is reported as lost', $lost);

// Any other failure is just a failure: retrying it later may work.
$plain = null;

$dbx['refusal'] = array( 'status' => 500, 'headers' => array(), 'body' => '' );

try {
	$dropbox->upload_chunk($saved, $ten, 20, 10);
} catch (\RuntimeException $caught) {
	$plain = $caught;
}

check_cl('a server error is not mistaken for a lost session', $plain !== null && ! $plain instanceof MigrateKit\Cloud\SessionLostException);
same_cl('the failed append left the session offset alone', 20, $saved['offset']);

// Through the stage: the job is behind, Dropbox is ahead, and the file still lands whole.
$dbx_stage = array(
	'cloud'       => 'dropbox',
	'remote'      => 'resume.wpress',
	'source'      => $ten,
	'size'        => 30,
	'storage_dir' => $GLOBALS['cl_tmp'] . '/job-dropbox',
	'offset'      => 10,
	'session'     => array( 'remote' => 'resume.wpress', 'session' => 'sess-9', 'offset' => 10 ),
	'stage'       => 1,
	'percent'     => 33,
	'message'     => '',
);

mkdir($dbx_stage['storage_dir'], 0777, true);

// A slice the job never saved had already put the second chunk up.
$dbx['held']  = 20;
$dbx['bytes'] = str_repeat('a', 10) . str_repeat('b', 10);

check_cl('Dropbox push completes across the stale offset', cl_steps('MigrateKit\\Cloud\\PushPipeline', $dbx_stage));
same_cl('the job skipped to where Dropbox was, then finished', 30, (int) $dbx_stage['offset']);
same_cl('Dropbox holds every byte exactly once', $thirty, $dbx['bytes']);
same_cl('nothing was appended past the end', 30, $dbx['held']);

echo "[11f] Google Drive: the session reports its own position\n";

$gdrive = array( 'held' => 0, 'total' => 0, 'expired' => false );

$gfake = function (string $method, string $url, array $headers, $body, int $timeout) use (&$gdrive): array {
	if (strpos($url, 'upload/drive/v3/files?') !== false) {
		return array(
			'status'  => 200,
			'headers' => array( 'location' => 'http://upload.local/session-42' ),
			'body'    => '',
		);
	}

	if ($gdrive['expired']) {
		return array( 'status' => 410, 'headers' => array(), 'body' => '' );
	}

	if ( ! empty($gdrive['refused']) ) {
		return array( 'status' => 403, 'headers' => array(), 'body' => '{"error":{"code":403,"message":"upload session expired"}}' );
	}

	$range = (string) headers_get($headers, 'Content-Range');

	if (preg_match('#^bytes=(\d+)-(\d+)/(\d+)$#', $range, $match) === 1) {
		$gdrive['total'] = (int) $match[3];

		// Bytes the server already holds are acknowledged, not stored again.
		$gdrive['held'] = max($gdrive['held'], ( (int) $match[2] ) + 1);

		if ($gdrive['held'] >= $gdrive['total']) {
			return array( 'status' => 200, 'headers' => array(), 'body' => '{"id":"file-1"}' );
		}

		return array(
			'status'  => 308,
			'headers' => array( 'Range' => 'bytes=0-' . ( $gdrive['held'] - 1 ) ),
			'body'    => '',
		);
	}

	if (strpos($range, 'bytes */') === 0) {
		if ($gdrive['held'] >= $gdrive['total']) {
			return array( 'status' => 200, 'headers' => array(), 'body' => '{"id":"file-1"}' );
		}

		return array(
			'status'  => 308,
			'headers' => array( 'Range' => 'bytes=0-' . ( $gdrive['held'] - 1 ) ),
			'body'    => '',
		);
	}

	return array( 'status' => 500, 'headers' => array(), 'body' => '' );
};

$GLOBALS['mk_opt']['migratekit_cloud'] = array(
	'gdrive' => array(
		'client_id'     => 'gid',
		'client_secret' => 'gsec',
		'folder'        => 'fldr-9',
		'access_token'  => 'gtok',
		'http'          => $gfake,
	),
);

$drive = Manager::provider('gdrive');

$session = $drive->upload_start('drive.wpress');

$drive->upload_chunk($session, $ten, 0, 10);
$drive->upload_chunk($session, $ten, 10, 10);

same_cl('two chunks in', 20, $gdrive['held']);

// A slice cut off after two chunks left the job saved at one.
$session['offset'] = 10;

$drive->upload_chunk($session, $ten, 10, 10);

same_cl('the session takes the position Drive reported', 20, $session['offset']);

$drive->upload_chunk($session, $ten, 20, 10);

check_cl('upload is done once Drive confirms the last byte', ! empty($session['done']));

// The reply to the last chunk was lost with a cut-off request: ask the session instead of failing.
$session['done']   = false;
$session['offset'] = 20;

$drive->upload_finish($session);

check_cl('a finished upload the job never saw confirmed is recognised', ! empty($session['done']));

$session['done'] = false;
$gdrive['held']  = 10;

$not_complete = false;

try {
	$drive->upload_finish($session);
} catch (MigrateKit\Cloud\SessionLostException $caught) {
	$not_complete = true;
}

check_cl('an upload the session says is short is not passed off as complete', $not_complete);

$gdrive['expired'] = true;

$expired = false;

try {
	$drive->upload_chunk($session, $ten, 20, 10);
} catch (MigrateKit\Cloud\SessionLostException $caught) {
	$expired = true;
}

check_cl('an expired Drive session is reported as lost', $expired);

// Drive answers any 4xx on a resumable upload with "start over".
$gdrive['expired'] = false;
$gdrive['refused'] = true;

$refused = false;

try {
	$drive->upload_chunk($session, $ten, 20, 10);
} catch (MigrateKit\Cloud\SessionLostException $caught) {
	$refused = true;
}

check_cl('a refused Drive session is reported as lost', $refused);

// ---------------------------------------------------------------------------
echo "\n";
printf("passed: %d, failed: %d\n", $GLOBALS['cl_pass'] ?? 0, $GLOBALS['cl_fail'] ?? 0);
exit(( $GLOBALS['cl_fail'] ?? 0 ) > 0 ? 1 : 0);

// ---------------------------------------------------------------------------
// Helpers below main flow (PHP declares them via function scoping anyway).

function cl_site(): SiteContext {
	return new SiteContext(array( 'table_prefix' => 'wp_', 'uploads_path' => $GLOBALS['cl_tmp'] . '/uploads' ));
}

function cl_local_file(string $content): string {
	$path = $GLOBALS['cl_tmp'] . '/local-' . bin2hex(random_bytes(4)) . '.bin';

	if (file_put_contents($path, $content) === false) {
		throw new RuntimeException('could not write local test file');
	}

	return $path;
}

/**
 * @param array<int,array{method:string,url:string,headers:array,body:string}> $calls
 * @param string $needle
 * @return array|null
 */
function call_site_aware(array $calls, string $needle): ?array {
	foreach ($calls as $call) {
		if (strpos($call['method'] . ' ' . $call['url'], $needle) !== false) {
			return $call;
		}
	}

	return null;
}

/**
 * Complete-multipart request body from the recorded S3 calls.
 *
 * @param array<int,array{method:string,url:string,headers:array,body:string}> $calls
 * @return string
 */
function call_complete(array $calls): string {
	foreach ($calls as $call) {
		if ($call['method'] === 'POST' && strpos($call['url'], 'uploadId=mk-upload-1') !== false && strpos($call['url'], 'uploads=') === false) {
			return $call['body'];
		}
	}

	return '';
}

/**
 * @param array<int,array{method:string,url:string,headers:array,body:string}> $calls
 * @return string|null
 */
function call_first_range(array $calls): ?string {
	foreach ($calls as $call) {
		$value = headers_get((array) $call['headers'], 'Range');

		if ($value !== null) {
			return $value;
		}
	}

	return null;
}

/**
 * @param array<int,array{method:string,url:string,headers:array,body:string}> $calls
 * @return string|null
 */
function call_last_range(array $calls): ?string {
	$last = null;

	foreach ($calls as $call) {
		$value = headers_get((array) $call['headers'], 'Range');

		if ($value !== null) {
			$last = $value;
		}
	}

	return $last;
}

function call_last_range_url(array $calls): ?string {
	$last = null;

	foreach ($calls as $call) {
		$value = headers_get((array) $call['headers'], 'Range');

		if ($value !== null) {
			$last = $call['url'];
		}
	}

	return $last;
}
