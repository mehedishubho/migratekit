<?php
/**
 * Google Drive storage provider (OAuth2, resumable uploads).
 *
 * Uploads open a resumable session against the Google Drive upload
 * endpoint and stream 8 MB chunks; downloads use ranged requests with
 * alt=media. Live Google endpoints remain unverified in this build; the
 * unit harness verifies request shaping with an injected transport.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud;

final class DriveProvider implements CloudProviderInterface {

	public const ID = 'gdrive';

	/** @var int Resumable chunk size (multiple of 256 KiB). */
	public const CHUNK = 8 * 1024 * 1024;

	private const API       = 'https://www.googleapis.com/drive/v3';
	private const UPLOAD    = 'https://www.googleapis.com/upload/drive/v3';
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	private const AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';

	/** @var array<string,mixed> */
	private $config;

	/**
	 * @param array<string,mixed> $config Stored settings.
	 */
	public function __construct(array $config) {
		$this->config = $config;
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'Google Drive';
	}

	/**
	 * @param string $name Setting name.
	 * @return string
	 */
	private function field(string $name): string {
		$value = $this->config[ $name ] ?? '';

		return is_string($value) ? $value : (string) $value;
	}

	/**
	 * @param array $input Raw settings.
	 * @return array<string,mixed>
	 */
	public static function settings(array $input): array {
		$parsed = array(
			'client_id'     => isset($input['client_id']) ? trim((string) $input['client_id']) : '',
			'client_secret' => isset($input['client_secret']) ? trim((string) $input['client_secret']) : '',
			'folder'        => isset($input['folder']) ? trim((string) $input['folder'], "/ \t") : '',
		);

		$json = isset($input['service_account']) ? trim((string) $input['service_account']) : '';

		if ($json !== '' && $json !== Manager::MASK) {
			$decoded = json_decode($json, true);

			if (! is_array($decoded) || ! isset($decoded['client_email']) || ! isset($decoded['private_key'])) {
				throw new \RuntimeException('The Google service-account JSON is incomplete: client_email and private_key are required.');
			}

			$parsed['client_email'] = trim((string) $decoded['client_email']);
			$parsed['private_key']  = (string) $decoded['private_key'];
		}

		return $parsed;
	}

	public function is_ready(): bool {
		if ($this->service_account_ready()) {
			return true;
		}

		return $this->field('client_id') !== ''
			&& $this->field('client_secret') !== ''
			&& $this->field('access_token') !== '';
	}

	/**
	 * Whether the service-account key pair (the no-OAuth easy mode) is set.
	 *
	 * @return bool
	 */
	public function service_account_ready(): bool {
		return $this->field('client_email') !== '' && $this->field('private_key') !== '';
	}

	/**
	 * Which connection mode applies: 'service' (key paste) or 'oauth'.
	 * Fresh installs land on the recommended service-account mode; an
	 * OAuth-only setup keeps its mode.
	 *
	 * @return string
	 */
	public function auth_mode(): string {
		if ($this->service_account_ready()) {
			return 'service';
		}

		$has_oauth = $this->field('client_id') !== ''
			|| $this->field('client_secret') !== ''
			|| $this->field('refresh_token') !== ''
			|| ( $this->field('access_token') !== '' && $this->field('client_id') !== '' );

		return $has_oauth ? 'oauth' : 'service';
	}

	public function authorize_url(): string {
		if ($this->field('client_id') === '' || self::redirect_url() === '') {
			return '';
		}

		return self::AUTHORIZE . '?' . http_build_query(
			array(
				'response_type' => 'code',
				'client_id'     => $this->field('client_id'),
				'redirect_uri'  => self::redirect_url(),
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'scope'         => 'https://www.googleapis.com/auth/drive',
				'state'         => wp_create_nonce('migratekit_cloud_gdrive'),
			)
		);
	}

	/**
	 * Absolute redirect URL registered in the Google Cloud console.
	 *
	 * @return string
	 */
	public static function redirect_url(): string {
		return admin_url('admin-post.php?action=migratekit_cloud_gdrive_callback');
	}

	public function display(): array {
		return array(
			'client_id'       => $this->field('client_id'),
			'client_secret'   => $this->field('client_secret') !== '' ? Manager::MASK : '',
			'folder'          => $this->field('folder'),
			'auth_mode'       => $this->auth_mode(),
			'client_email'    => $this->field('client_email'),
			'service_account' => $this->field('private_key') !== '' ? Manager::MASK : '',
			'connected'       => $this->field('access_token') !== '' || $this->service_account_ready(),
			'account'         => $this->field('account') !== '' ? $this->field('account') : $this->field('client_email'),
			'expires_at'      => (int) ( $this->config['expires_at'] ?? 0 ),
		);
	}

	public function connect(): array {
		if ($this->field('client_id') === '' || self::redirect_url() === '') {
			return array( 'url' => '', 'state' => '', 'paste' => false );
		}

		return array(
			'url'   => self::AUTHORIZE . '?' . http_build_query(
				array(
					'response_type' => 'code',
					'client_id'     => $this->field('client_id'),
					'redirect_uri'  => self::redirect_url(),
					'access_type'   => 'offline',
					'prompt'        => 'consent',
					'scope'         => 'https://www.googleapis.com/auth/drive',
					'state'         => wp_create_nonce('migratekit_cloud_gdrive'),
				)
			),
			'state' => '',
			'paste' => false,
		);
	}

	public function chunk_size(): int {
		return self::CHUNK;
	}

	public function test(): void {
		$response = $this->api(self::API . '/about?fields=user', 'GET', array(), null);

		if ($response['status'] !== 200) {
			throw new \RuntimeException($this->message('The Google Drive connection check failed', $response));
		}

		$decoded = json_decode($response['body'], true);
		$email   = is_array($decoded) && isset($decoded['user']['emailAddress'])
			? (string) $decoded['user']['emailAddress']
			: '';

		if ($email !== '') {
			Manager::store_tokens(self::ID, array( 'account' => $email ));
			$this->config['account'] = $email;
		}
	}

	/**
	 * Exchange an authorisation code for access/refresh tokens.
	 *
	 * @param string $code Authorisation code.
	 * @return void
	 */
	public function authorize_code(string $code): void {
		$body = http_build_query(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $this->field('client_id'),
				'client_secret' => $this->field('client_secret'),
				'redirect_uri'  => self::redirect_url(),
			)
		);

		$response = Transport::send(
			'POST',
			self::TOKEN_URL,
			array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			$body,
			30,
			$this->transport()
		);

		if ($response['status'] !== 200) {
			throw new \RuntimeException($this->message('Exchanging the Google authorisation failed', $response));
		}

		$decoded = json_decode($response['body'], true);

		if (! is_array($decoded) || ! isset($decoded['access_token'])) {
			throw new \RuntimeException('The Google token response could not be parsed.');
		}

		$expires = time() + (int) ( $decoded['expires_in'] ?? 3600 ) - 60;

		Manager::store_tokens(self::ID, array(
			'access_token'  => (string) $decoded['access_token'],
			'refresh_token' => (string) ( $decoded['refresh_token'] ?? '' ),
			'expires_at'    => $expires,
		));

		$this->config['access_token']  = (string) $decoded['access_token'];
		$this->config['refresh_token'] = (string) ( $decoded['refresh_token'] ?? '' );
		$this->config['expires_at']    = $expires;
	}

	public function upload_start(string $remote): array {
		$metadata = array( 'name' => $remote );
		$folder   = $this->field('folder');

		if ($folder !== '') {
			$metadata['parents'] = array( $folder );
		}

		$payload = (string) json_encode($metadata);
		$query   = http_build_query(array( 'uploadType' => 'resumable', 'supportsAllDrives' => 'true' ));

		$response = $this->api(
			self::UPLOAD . '/files?' . $query,
			'POST',
			array( 'Content-Type' => 'application/json' ),
			$payload
		);

		if ($response['status'] !== 200 || ! isset($response['headers']['location'])) {
			throw new \RuntimeException($this->message('Starting the Google Drive upload session failed', $response));
		}

		return array(
			'remote'     => $remote,
			'upload_url' => (string) $response['headers']['location'],
			'offset'     => 0,
			'done'       => false,
		);
	}

	public function upload_chunk(array &$session, string $local_path, int $offset, int $length): int {
		if ((bool) ( $session['done'] ?? false )) {
			return 0;
		}

		$total = filesize($local_path);

		if ($total === false) {
			throw new \RuntimeException('The local file to push could not be measured.');
		}

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

		$end  = $offset + strlen($chunk) - 1;
		$head = sprintf('bytes=%d-%d/%d', $offset, $end, $total);

		$response = $this->api(
			(string) $session['upload_url'],
			'PUT',
			array(
				'Content-Length' => (string) strlen($chunk),
				'Content-Range'  => $head,
				'Content-Type'   => 'application/octet-stream',
			),
			$chunk
		);

		if ($response['status'] === 308) {
			$session['offset'] = (int) ( $session['offset'] ?? 0 ) + strlen($chunk);

			return strlen($chunk);
		}

		if ($response['status'] === 200 || $response['status'] === 201) {
			$session['offset'] = $total;
			$session['done']   = true;

			return strlen($chunk);
		}

		throw new \RuntimeException($this->message('Uploading a Google Drive chunk failed', $response));
	}

	public function upload_finish(array &$session): void {
		if ((bool) ( $session['done'] ?? false )) {
			return;
		}

		// The resumable upload finalises on the last chunk (2xx); a replayed
		// finish means the session state says chunks are pending.
		throw new \RuntimeException('The Google Drive upload is not complete. Push the backup again.');
	}

	public function download_start(string $remote, int $size = 0): array {
		$file = $this->lookup($remote);

		if ($file['id'] === '') {
			throw new \RuntimeException('The remote backup does not exist: ' . $remote);
		}

		return array(
			'remote' => $remote,
			'id'     => $file['id'],
			'size'   => $size > 0 ? $size : $file['size'],
		);
	}

	public function download_chunk(array &$session, int $offset, int $length): string {
		$response = $this->api(
			self::API . '/files/' . rawurlencode((string) $session['id']) . '?alt=media&supportsAllDrives=true',
			'GET',
			array( 'Range' => sprintf('bytes=%d-%d', $offset, $offset + $length - 1) ),
			null
		);

		if ($response['status'] === 416) {
			return '';
		}

		if ($response['status'] !== 200 && $response['status'] !== 206) {
			throw new \RuntimeException($this->message('Downloading from Google Drive failed', $response));
		}

		return $response['body'];
	}

	public function list(): array {
		$objects = array();
		$page    = '';
		$guard   = 0;

		do {
			$parts = array( "name contains '.wpress'", 'trashed = false', "'me' in writers" );
			$folder = $this->field('folder');

			if ($folder !== '') {
				$parts[] = sprintf("'%s' in parents", $folder);
			}

			$params = array(
				'q'                => implode(' and ', $parts),
				'pageSize'         => '500',
				'fields'           => 'nextPageToken, files(id, name, size, modifiedTime)',
				'supportsAllDrives' => 'true',
				'includeItemsFromAllDrives' => 'true',
			);

			if ($page !== '') {
				$params['pageToken'] = $page;
			}

			$response = $this->api(self::API . '/files?' . http_build_query($params), 'GET', array(), null);

			if ($response['status'] !== 200) {
				throw new \RuntimeException($this->message('Listing Google Drive failed', $response));
			}

			$decoded = json_decode($response['body'], true);

			if (! is_array($decoded) || ! isset($decoded['files']) || ! is_array($decoded['files'])) {
				throw new \RuntimeException('The Google Drive listing response could not be parsed.');
			}

			foreach ($decoded['files'] as $entry) {
				if (! is_array($entry) || ! isset($entry['name'])) {
					continue;
				}

				$name = (string) $entry['name'];

				if (! preg_match('/\.wpress$/', $name)) {
					continue;
				}

				$objects[ $name ] = array(
					'name'  => $name,
					'size'  => (int) ( $entry['size'] ?? 0 ),
					'mtime' => isset($entry['modifiedTime']) ? (int) strtotime((string) $entry['modifiedTime']) : 0,
				);
			}

			$page = isset($decoded['nextPageToken']) ? (string) $decoded['nextPageToken'] : '';
		} while ($page !== '' && ++$guard < 50);

		ksort($objects);

		return $objects;
	}

	public function delete(string $remote): void {
		$file_id = $this->lookup($remote)['id'];

		if ($file_id === '') {
			return;
		}

		$response = $this->api(
			self::API . '/files/' . rawurlencode($file_id) . '?supportsAllDrives=true',
			'DELETE',
			array(),
			null
		);

		if ($response['status'] !== 204 && $response['status'] !== 200) {
			throw new \RuntimeException($this->message('Deleting the remote backup failed', $response));
		}
	}

	/**
	 * Look up the Drive file id and size for a stored archive name.
	 *
	 * @param string $remote Remote name.
	 * @return array{id:string,size:int} '' id when not found.
	 */
	private function lookup(string $remote): array {
		$folder = $this->field('folder');
		$query  = sprintf("name = '%s' and trashed = false", str_replace("'", "\\'", $remote));

		if ($folder !== '') {
			$query .= sprintf(" and '%s' in parents", $folder);
		}

		$params = array(
			'q'                       => $query,
			'fields'                  => 'files(id, name, size)',
			'pageSize'                => '10',
			'supportsAllDrives'       => 'true',
			'includeItemsFromAllDrives' => 'true',
		);

		$response = $this->api(self::API . '/files?' . http_build_query($params), 'GET', array(), null);

		if ($response['status'] !== 200) {
			throw new \RuntimeException($this->message('Resolving the remote backup failed', $response));
		}

		$decoded = json_decode($response['body'], true);

		if (is_array($decoded) && isset($decoded['files']) && is_array($decoded['files'])) {
			foreach ($decoded['files'] as $entry) {
				if (is_array($entry) && ( $entry['name'] ?? '' ) === $remote) {
					return array(
						'id'   => (string) ( $entry['id'] ?? '' ),
						'size' => (int) ( $entry['size'] ?? 0 ),
					);
				}
			}
		}

		return array( 'id' => '', 'size' => 0 );
	}

	/**
	 * Drive request with token refresh retry. Service accounts mint a
	 * short-lived access token up front; OAuth clients refresh on a 401.
	 *
	 * @param string               $url    Endpoint.
	 * @param string               $method HTTP method.
	 * @param array<string,string> $extra  Extra headers.
	 * @param string|null          $body   Body or none.
	 * @return array{status:int,headers:array<string,string>,body:string,error:string}
	 */
	private function api(string $url, string $method, array $extra, ?string $body): array {
		if ($this->service_account_ready()) {
			$this->ensure_service_token();
		}

		$headers = array_merge(
			array( 'Authorization' => 'Bearer ' . $this->field('access_token') ),
			$extra
		);

		$response = Transport::send($method, $url, $headers, $body, 60, $this->transport());

		if ($response['status'] === 401 && ! $this->service_account_ready() && $this->field('refresh_token') !== '') {
			$this->refresh_token();

			$headers = array_merge(
				array( 'Authorization' => 'Bearer ' . $this->field('access_token') ),
				$extra
			);

			$response = Transport::send($method, $url, $headers, $body, 60, $this->transport());
		}

		return $response;
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
	 * @return void
	 */
	private function refresh_token(): void {
		$body = http_build_query(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $this->field('refresh_token'),
				'client_id'     => $this->field('client_id'),
				'client_secret' => $this->field('client_secret'),
			)
		);
		$response = Transport::send(
			'POST',
			self::TOKEN_URL,
			array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			$body,
			30,
			$this->transport()
		);

		if ($response['status'] !== 200) {
			throw new \RuntimeException($this->message('Refreshing the Google token failed', $response));
		}

		$decoded = json_decode($response['body'], true);

		if (! is_array($decoded) || ! isset($decoded['access_token'])) {
			throw new \RuntimeException('The Google refresh response could not be parsed.');
		}

		$expires = time() + (int) ( $decoded['expires_in'] ?? 3600 ) - 60;

		Manager::store_tokens(self::ID, array(
			'access_token' => (string) $decoded['access_token'],
			'expires_at'   => $expires,
		));

		$this->config['access_token'] = (string) $decoded['access_token'];
		$this->config['expires_at']   = $expires;
	}

	/**
	 * Mint a fresh service-account access token when none is cached or the
	 * cached one is within a minute of expiring.
	 *
	 * @return void
	 */
	private function ensure_service_token(): void {
		$expires = (int) ( $this->config['expires_at'] ?? 0 );

		if ($this->field('access_token') !== '' && $expires >= time() + 60) {
			return;
		}

		$this->mint_service_token();
	}

	/**
	 * Exchange a signed JWT for an access token (service account flow).
	 *
	 * @return void
	 */
	private function mint_service_token(): void {
		$header = self::b64url((string) json_encode(array( 'alg' => 'RS256', 'typ' => 'JWT' )));
		$issued = time();
		$claims = json_encode(
			array(
				'iss'   => $this->field('client_email'),
				'scope' => 'https://www.googleapis.com/auth/drive',
				'aud'   => self::TOKEN_URL,
				'exp'   => $issued + 3600,
				'iat'   => $issued,
			)
		);

		if ($claims === false) {
			throw new \RuntimeException('Could not encode the Google service-account claims.');
		}

		$payload = $header . '.' . self::b64url($claims);
		$signed  = '';

		if (openssl_sign($payload, $signed, $this->field('private_key'), OPENSSL_ALGO_SHA256) !== true) {
			throw new \RuntimeException('The Google service-account private key could not sign the token request.');
		}

		$assertion = $payload . '.' . rtrim(strtr(base64_encode($signed), '+/', '-_'), '=');

		$response = Transport::send(
			'POST',
			self::TOKEN_URL,
			array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			http_build_query(
				array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
				)
			),
			30,
			$this->transport()
		);

		if ($response['status'] !== 200) {
			throw new \RuntimeException($this->message('Minting the Google service-account token failed', $response));
		}

		$decoded = json_decode($response['body'], true);

		if (! is_array($decoded) || ! isset($decoded['access_token'])) {
			throw new \RuntimeException('The Google service-account token response could not be parsed.');
		}

		$expires = time() + (int) ( $decoded['expires_in'] ?? 3600 ) - 60;

		Manager::store_tokens(self::ID, array(
			'access_token' => (string) $decoded['access_token'],
			'expires_at'   => $expires,
		));

		$this->config['access_token'] = (string) $decoded['access_token'];
		$this->config['expires_at']   = $expires;
	}

	/**
	 * @param string                                                                 $headline Message prefix.
	 * @param array{status:int,headers:array<string,string>,body:string,error:string} $response Transport response.
	 * @return string
	 */
	private function message(string $headline, array $response): string {
		$detail = $response['error'] !== ''
			? $response['error']
			: $this->first_error_line($response['body']);

		return sprintf(
			'%s (HTTP %d%s%s)',
			$headline,
			$response['status'],
			$detail !== '' ? ': ' : '',
			$detail
		);
	}

	/**
	 * @param string $body Response body.
	 * @return string
	 */
	private function first_error_line(string $body): string {
		if ($body === '') {
			return '';
		}

		$decoded = json_decode($body, true);

		if (is_array($decoded) && isset($decoded['error']['message'])) {
			return (string) $decoded['error']['message'];
		}

		return substr(trim($body), 0, 160);
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $value Raw bytes.
	 * @return string
	 */
	private static function b64url(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}
}
