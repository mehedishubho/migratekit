<?php
/**
 * S3-compatible storage provider (AWS, MinIO, Spaces, Wasabi, B2-compatible).
 *
 * Path-style addressing: {endpoint}/{bucket}/{key}. Multipart uploads are
 * always used so large archives resume in stages; downloads use Range
 * requests. Remote names are prefix-relative: the configured prefix is
 * joined on the wire and stripped for listings.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud;

final class S3Provider implements CloudProviderInterface {

	public const ID = 's3';

	/** @var int Part size for multipart uploads and ranged downloads. */
	public const CHUNK = 8 * 1024 * 1024;

	/** @var string Object key the connection test opens a multipart upload on and cancels; nothing is ever stored under it. */
	private const WRITE_PROBE = 'migratekit-write-check.wpress';

	/** @var array<string,mixed> */
	private $config;

	/**
	 * @param array<string,mixed> $config Sanitised settings.
	 */
	public function __construct(array $config) {
		$this->config = $config;
	}

	/**
	 * @param string $name Setting name.
	 * @return string
	 */
	private function field(string $name): string {
		$value = $this->config[ $name ] ?? '';

		return is_string($value) ? $value : (string) $value;
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'S3-compatible storage';
	}

	/**
	 * @param array $input Raw settings.
	 * @return array<string,mixed>
	 */
	public static function settings(array $input): array {
		return array(
			'endpoint'   => isset($input['endpoint']) ? self::normalise_endpoint((string) $input['endpoint']) : '',
			'bucket'     => isset($input['bucket']) ? trim((string) $input['bucket']) : '',
			'prefix'     => isset($input['prefix']) ? trim((string) $input['prefix'], "/ \t") : '',
			'region'     => isset($input['region']) && $input['region'] !== '' ? (string) $input['region'] : 'us-east-1',
			'access_key' => isset($input['access_key']) ? trim((string) $input['access_key']) : '',
			'secret_key' => isset($input['secret_key']) ? trim((string) $input['secret_key']) : '',
			// Whenever secret_key arrives empty and a masked placeholder comes
			// in, the caller (Manager::save) keeps the stored secret instead.
		);
	}

	/**
	 * @param string $endpoint Raw endpoint.
	 * @return string Normalised https endpoint without a trailing slash.
	 */
	private static function normalise_endpoint(string $endpoint): string {
		$endpoint = trim($endpoint);

		if ($endpoint === '') {
			return '';
		}

		if (! preg_match('#^https?://#i', $endpoint)) {
			$endpoint = 'https://' . $endpoint;
		}

		return rtrim($endpoint, '/');
	}

	public function is_ready(): bool {
		return $this->field('endpoint') !== ''
			&& $this->field('bucket') !== ''
			&& $this->field('access_key') !== ''
			&& $this->field('secret_key') !== '';
	}

	public function authorize_url(): string {
		return '';
	}

	public function connect(): array {
		return array( 'url' => '', 'state' => '', 'paste' => false );
	}

	public function test(): void {
		$response = $this->request('GET', $this->bucket_base(), array(), array(), null);

		if ($response['status'] !== 200) {
			throw new \RuntimeException(
				$this->message('Listing the bucket failed', $response)
			);
		}

		$this->assert_writable();
	}

	public function display(): array {
		return array(
			'endpoint'   => $this->field('endpoint'),
			'bucket'     => $this->field('bucket'),
			'prefix'     => $this->field('prefix'),
			'region'     => $this->field('region'),
			'access_key' => $this->field('access_key'),
			'secret_key' => $this->field('secret_key') !== '' ? Manager::MASK : '',
		);
	}

	public function chunk_size(): int {
		return self::CHUNK;
	}

	public function upload_start(string $remote): array {
		$response = $this->request('POST', $this->object_path($remote), array(), array( 'uploads' => '' ), null);

		if ($response['status'] !== 200) {
			throw new \RuntimeException(
				$this->message('Starting the upload failed', $response, 'The access key cannot write to this bucket')
			);
		}

		$xml = simplexml_load_string($response['body']);

		if (! ($xml instanceof \SimpleXMLElement) || ! isset($xml->UploadId)) {
			throw new \RuntimeException('The S3 response did not include an upload id.');
		}

		return array(
			'upload_id' => (string) $xml->UploadId,
			'remote'    => $remote,
			'parts'     => array(),
			'next_part' => 1,
		);
	}

	public function upload_chunk(array &$session, string $local_path, int $offset, int $length): int {
		$handle = fopen($local_path, 'rb');

		if ($handle === false) {
			throw new \RuntimeException('The local file to push could not be opened.');
		}

		if (fseek($handle, $offset) !== 0) {
			fclose($handle);
			throw new \RuntimeException('Failed to seek to offset ' . $offset . ' in the local backup file.');
		}

		$chunk = (string) fread($handle, $length);
		fclose($handle);

		if ($chunk === '') {
			return 0;
		}

		$part = (int) $session['next_part'];
		$response = $this->request(
			'PUT',
			$this->object_path($session['remote']),
			array(),
			array(
				'partNumber' => (string) $part,
				'uploadId'   => (string) $session['upload_id'],
			),
			$chunk
		);

		if ($response['status'] !== 200 || ! isset($response['headers']['etag'])) {
			// The upload id expired or was aborted elsewhere; none of the parts sent so far can be completed.
			if ($response['status'] === 404 && $this->error_code($response['body']) === 'NoSuchUpload') {
				throw new SessionLostException('The upload to ' . $this->label() . ' is no longer known there.');
			}

			throw new \RuntimeException(
				$this->message('Uploading part ' . $part . ' failed', $response, 'The access key cannot write to this bucket')
			);
		}

		$parts = is_array($session['parts']) ? $session['parts'] : array();
		$parts[] = array(
			'part_number' => $part,
			'etag'        => trim((string) $response['headers']['etag']),
		);
		$session['parts']     = $parts;
		$session['next_part'] = $part + 1;

		return strlen($chunk);
	}

	public function upload_finish(array &$session): void {
		$parts = is_array($session['parts']) ? $session['parts'] : array();

		usort($parts, static function (array $a, array $b): int {
			return $a['part_number'] <=> $b['part_number'];
		});

		$xml = '<CompleteMultipartUpload>';

		foreach ($parts as $part) {
			$xml .= sprintf(
				'<Part><PartNumber>%d</PartNumber><ETag>%s</ETag></Part>',
				(int) $part['part_number'],
				htmlspecialchars((string) $part['etag'], ENT_XML1 | ENT_NOQUOTES)
			);
		}

		$xml .= '</CompleteMultipartUpload>';

		$response = $this->request(
			'POST',
			$this->object_path((string) $session['remote']),
			array(),
			array(
				'uploadId' => (string) $session['upload_id'],
			),
			$xml
		);

		if ($response['status'] !== 200) {
			throw new \RuntimeException(
				$this->message('Completing the upload failed', $response, 'The access key cannot write to this bucket')
			);
		}
	}

	public function upload_abort(array $session): void {
		$response = $this->request(
			'DELETE',
			$this->object_path((string) $session['remote']),
			array(),
			array( 'uploadId' => (string) $session['upload_id'] ),
			null
		);

		if ($response['status'] !== 204 && $response['status'] !== 404) {
			throw new \RuntimeException($this->message('Discarding the unfinished upload failed', $response));
		}
	}

	public function download_start(string $remote, int $size = 0): array {
		$head = $this->request('HEAD', $this->object_path($remote), array(), array(), null);

		if ($head['status'] === 404) {
			throw new \RuntimeException('The remote backup does not exist: ' . $remote);
		}

		$known = $size > 0;

		if (! $known && $head['status'] === 200 && isset($head['headers']['content-length'])) {
			$known = true;
			$size  = (int) $head['headers']['content-length'];
		}

		return array(
			'remote' => $remote,
			'size'   => $known ? $size : 0,
		);
	}

	public function download_chunk(array &$session, int $offset, int $length): string {
		$response = $this->request(
			'GET',
			$this->object_path((string) $session['remote']),
			array(
				'Range' => sprintf('bytes=%d-%d', $offset, $offset + $length - 1),
			),
			array(),
			null
		);

		if ($response['status'] === 416) {
			return '';
		}

		if ($response['status'] !== 200 && $response['status'] !== 206) {
			throw new \RuntimeException($this->message('Downloading from storage failed', $response));
		}

		return $response['body'];
	}

	public function list(): array {
		$objects = array();
		$token   = '';
		$guard   = 0;

		do {
			$query = array( 'list-type' => '2', 'max-keys' => '1000' );

			if ($this->field('prefix') !== '') {
				$query['prefix'] = $this->field('prefix');
			}

			if ($token !== '') {
				$query['continuation-token'] = $token;
			}

			$response = $this->request('GET', $this->bucket_base(), array(), $query, null);

			if ($response['status'] !== 200) {
				throw new \RuntimeException($this->message('Listing the bucket failed', $response));
			}

			$xml = simplexml_load_string($response['body']);

			if (! ($xml instanceof \SimpleXMLElement)) {
				throw new \RuntimeException('The S3 listing response could not be parsed.');
			}

			$prefix = $this->field('prefix');

			foreach ($xml->Contents as $content) {
				$name = (string) $content->Key;
				$size = (int) $content->Size;
				$t    = (string) $content->LastModified;

				if ($prefix !== '' && strpos($name, $prefix) === 0) {
					$name = ltrim(substr($name, strlen($prefix)), '/');
				}

				if (! preg_match('/\.wpress$/', $name)) {
					continue;
				}

				$objects[ $name ] = array(
					'name'  => $name,
					'size'  => $size,
					'mtime' => $t !== '' ? strtotime($t) : 0,
				);
			}

			$truncated = isset($xml->IsTruncated) && ((string) $xml->IsTruncated) === 'true';
			$token     = $truncated && isset($xml->NextContinuationToken)
				? (string) $xml->NextContinuationToken
				: '';
		} while ($token !== '' && ++$guard < 50);

		ksort($objects);

		return $objects;
	}

	public function delete(string $remote): void {
		$response = $this->request('DELETE', $this->object_path($remote), array(), array(), null);

		if ($response['status'] === 204 || $response['status'] === 404) {
			return;
		}

		throw new \RuntimeException($this->message('Deleting the remote backup failed', $response));
	}

	/**
	 * Bucket root: endpoint/{bucket}.
	 *
	 * @return string
	 */
	private function bucket_base(): string {
		return $this->field('endpoint') . '/' . rawurlencode($this->field('bucket'));
	}

	/**
	 * Path-style object URL: endpoint/{bucket}/{prefix+key}.
	 *
	 * @param string $remote Prefix-stripped remote name.
	 * @return string
	 */
	private function object_path(string $remote): string {
		$prefix = $this->field('prefix');
		$key    = ($prefix !== '' ? $prefix . '/' : '') . $remote;

		return $this->bucket_base() . '/' . $key;
	}

	/**
	 * Sign and send one S3 request.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Full URL (path style).
	 * @param array<string,string> $headers Extra headers (Range).
	 * @param array<string,string> $query   Query parameters.
	 * @param string|null          $body    Body or none.
	 * @return array{status:int,headers:array<string,string>,body:string,error:string}
	 */
	private function request(string $method, string $url, array $headers, array $query, ?string $body): array {
		$parsed = parse_url($url);

		if (! is_array($parsed) || empty($parsed['host'])) {
			throw new \RuntimeException('The storage endpoint configuration is invalid.');
		}

		$host = (string) $parsed['host'] . ( isset($parsed['port']) ? ':' . $parsed['port'] : '' );
		$path = (string) ( $parsed['path'] ?? '' );

		$payload = $body === null
			? hash('sha256', '')
			: 'UNSIGNED-PAYLOAD';

		$signed = SigV4::sign(
			strtoupper($method),
			$host,
			$path,
			$query,
			$headers,
			$this->field('region'),
			$this->field('access_key'),
			$this->field('secret_key'),
			$payload
		);

		// The request URL must carry exactly the canonical path the
		// signature was computed over.
		$base = ( isset($parsed['scheme']) ? $parsed['scheme'] : 'https' ) . '://' . $host . SigV4::encode_path($path);
		$send_url = $base . ( $query !== array() ? '?' . SigV4::canonical_query($query) : '' );

		return Transport::send(
			strtoupper($method),
			$send_url,
			$signed,
			$body,
			120,
			$this->transport()
		);
	}

	/**
	 * Test-injected transport, when present in the settings.
	 *
	 * @return callable|null
	 */
	private function transport(): ?callable {
		$injected = $this->config['http'] ?? null;

		return is_callable($injected) ? $injected : null;
	}

	/**
	 * A key may list the bucket and still be refused the first write of a
	 * push: open that upload on a probe key, then cancel it.
	 *
	 * @return void
	 */
	private function assert_writable(): void {
		$probe    = $this->object_path(self::WRITE_PROBE);
		$response = $this->request('POST', $probe, array(), array( 'uploads' => '' ), null);

		if ($response['status'] !== 200) {
			throw new \RuntimeException(
				$this->message(
					'The bucket can be listed, but this access key cannot write to it',
					$response,
					'Backups cannot be pushed with these credentials'
				)
			);
		}

		$xml = simplexml_load_string($response['body']);

		if ($xml instanceof \SimpleXMLElement && isset($xml->UploadId)) {
			$this->request('DELETE', $probe, array(), array( 'uploadId' => (string) $xml->UploadId ), null);
		}
	}

	/**
	 * @param string                                             $headline Message prefix.
	 * @param array{status:int,headers:array<string,string>,body:string,error:string} $response Transport response.
	 * @param string                                             $denied    Sentence added when the key lacks the permission.
	 * @return string
	 */
	private function message(string $headline, array $response, string $denied = 'The access key is not allowed to do that'): string {
		$detail = $response['error'] !== ''
			? $response['error']
			: $this->first_error_line($response['body']);

		$text = sprintf(
			'%s (HTTP %d%s%s)',
			$headline,
			$response['status'],
			$detail !== '' ? ': ' : '',
			$detail
		);

		if ($denied === '' || ! $this->is_denied($response, $detail)) {
			return $text;
		}

		return $text . '. ' . $denied . '. ' . $this->permission_advice();
	}

	/**
	 * S3 also answers 403 for a bad signature or an unknown key: only an
	 * AccessDenied error is a key that is valid but not permitted.
	 *
	 * @param array{status:int,headers:array<string,string>,body:string,error:string} $response Transport response.
	 * @param string                                             $detail   Error detail already extracted.
	 * @return bool
	 */
	private function is_denied(array $response, string $detail): bool {
		if ($response['status'] !== 403 || $response['error'] !== '') {
			return false;
		}

		$code = $this->error_code($response['body']);

		return $code === 'AccessDenied'
			|| ( $code === '' && preg_match('/^access denied\b/i', $detail) === 1 );
	}

	/**
	 * Machine-readable code of an S3 XML error response, when there is one.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	private function error_code(string $body): string {
		if ($body === '') {
			return '';
		}

		$use_errors = libxml_use_internal_errors(true);
		$xml        = simplexml_load_string($body);
		libxml_clear_errors();
		libxml_use_internal_errors($use_errors);

		return $xml instanceof \SimpleXMLElement && isset($xml->Code) ? trim((string) $xml->Code) : '';
	}

	/**
	 * How to give the key write access, in the terms the storage service uses.
	 *
	 * @return string
	 */
	private function permission_advice(): string {
		$host = strtolower((string) parse_url($this->field('endpoint'), PHP_URL_HOST));

		if (preg_match('#(^|\.)r2\.cloudflarestorage\.com$#', $host) === 1) {
			return 'On Cloudflare R2, create the API token with the "Object Read and Write" permission for this bucket.';
		}

		return 'Give this key read and write access to this bucket.';
	}

	/**
	 * First human-readable line of an S3 XML error response.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	private function first_error_line(string $body): string {
		if ($body === '') {
			return '';
		}

		$xml = simplexml_load_string($body);

		if ($xml === false || ! isset($xml->Message)) {
			return substr(trim($body), 0, 160);
		}

		return trim((string) $xml->Message);
	}
}
