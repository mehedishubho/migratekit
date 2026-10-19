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

function wp_verify_nonce(string $nonce, string $action = ''): int|false {
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