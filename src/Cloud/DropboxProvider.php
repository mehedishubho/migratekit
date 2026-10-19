<?php
/**
 * Dropbox storage provider (OAuth2, chunked upload sessions).
 *
 * Chunks stream through /files/upload_session endpoints with cursor
 * offsets, downloads use ranged requests against the content endpoint.
 * Real-provider behaviour is exercised against the Dropbox v2 API; unit
 * harnesses verify request shaping with an injected transport though,
 * so live Dropbox calls remain unverified in this build.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud;

final class DropboxProvider implements CloudProviderInterface {

	public const ID = 'dropbox';

	/** @var int Upload-session chunk size (well under the 150 MB cap). */
	public const CHUNK = 8 * 1024 * 1024;

	private const API       = 'https://api.dropboxapi.com/2';
	private const CONTENT   = 'https://content.dropboxapi.com/2';
	private const TOKEN_URL = 'https://api.dropboxapi.com/oauth2/token';
	private const AUTHORIZE = 'https://www.dropbox.com/oauth2/authorize';

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
		return 'Dropbox';
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
		return array(
			'client_id'     => isset($input['client_id']) ? trim((string) $input['client_id']) : '',
			'client_secret' => isset($input['client_secret']) ? trim((string) $input['client_secret']) : '',
			'folder'        => isset($input['folder']) ? trim((string) $input['folder'], "/ \t") : '',
		);
	}

	public function is_ready(): bool {
		if ($this->field('access_token') === '') {
			return false;
		}

		// Refreshing needs an app identity: either the legacy pair or the
		// embedded/public app key behind the one-click connection.
		return $this->field('client_id') !== ''
			|| $this->field('client_secret') !== ''
			|| $this->app_key() !== '';
	}

	/**
	 * One-click (public client) mode: an app key with no secret. Dropbox
	 * then ends the consent page on a code the user pastes back, and the
	 * token exchange is authenticated by the PKCE verifier alone.
	 *
	 * @return bool
	 */
	public function easy_mode(): bool {
		return $this->field('client_secret') === '';
	}

	/**
	 * The Dropbox App key: the stored value, else the one embedded in the
	 * distribution so connecting needs zero fields.
	 *
	 * @return string
	 */
	public function app_key(): string {
		$stored = $this->field('client_id');

		if ($stored !== '') {
			return $stored;
		}

		$embedded = defined('MIGRATEKIT_DROPBOX_APP_ID') ? (string) MIGRATEKIT_DROPBOX_APP_ID : '';

		return $embedded !== '' ? $embedded : '';
	}

	public function authorize_url(): string {
		if ($this->app_key() === '') {
			return '';
		}

		if ($this->easy_mode()) {
			// Preview only; the real connection start is connect(), which
			// stores the verifier the URL's challenge hashes.
			return self::AUTHORIZE . '?' . http_build_query(
				array(
					'response_type'         => 'code',
					'client_id'             => $this->app_key(),
					'token_access_type'     => 'offline',
					'code_challenge'        => $this->challenge($this->new_verifier()),
					'code_challenge_method' => 'S256',
					'scope'                 => 'files.content.read files.content.write files.metadata.read offline.access',
					'state'                 => wp_create_nonce('migratekit_cloud_dropbox'),
				)
			);
		}

		if (self::redirect_url() === '') {
			return '';
		}

		return self::AUTHORIZE . '?' . http_build_query(
			array(
				'response_type'     => 'code',
				'client_id'         => $this->field('client_id'),
				'redirect_uri'      => self::redirect_url(),
				'token_access_type' => 'offline',
				'scope'             => 'files.content.read files.content.write',
				'state'             => wp_create_nonce('migratekit_cloud_dropbox'),
			)
		);
	}

	public function connect(): array {
		if ($this->app_key() === '') {
			return array( 'url' => '', 'state' => '', 'paste' => false );
		}

		$state = wp_create_nonce('migratekit_cloud_dropbox');

		if (! $this->easy_mode() || self::redirect_url() === '') {
			// Legacy secret flow: consent redirects back to admin-post.php.
			return array(
				'url'   => self::AUTHORIZE . '?' . http_build_query(
					array(
						'response_type'     => 'code',
						'client_id'         => $this->field('client_id'),
						'redirect_uri'      => self::redirect_url(),
						'token_access_type' => 'offline',
						'scope'             => 'files.content.read files.content.write',
						'state'             => $state,
					)
				),
				'state' => $state,
				'paste' => false,
			);
		}

		$verifier = $this->new_verifier();
		$this->store_verifier($state, $verifier);

		return array(
			'url'   => self::AUTHORIZE . '?' . http_build_query(
				array(
					'response_type'         => 'code',
					'client_id'             => $this->app_key(),
					'token_access_type'     => 'offline',
					'code_challenge'        => $this->challenge($verifier),
					'code_challenge_method' => 'S256',
					'scope'                 => 'files.content.read files.content.write files.metadata.read offline.access',
					'state'                 => $state,
				)
			),
			'state' => $state,
			'paste' => true,
		);
	}

	/**
	 * Random PKCE code verifier (64 hex characters).
	 *
	 * @return string
	 */
	private function new_verifier(): string {
		return bin2hex((string) random_bytes(32));
	}

	/**
	 * S256 challenge derived from a verifier.
	 *
	 * @param string $verifier Verifier bytes.
	 * @return string
	 */
	private function challenge(string $verifier): string {
		return rtrim(strtr(base64_encode((string) hash('sha256', $verifier, true)), '+/', '-_'), '=');
	}

	/**
	 * Keep the verifier with its state so the exchange can pair them.
	 *
	 * @param string $state    State token.
	 * @param string $verifier Verifier bytes.
	 * @return void
	 */
	private function store_verifier(string $state, string $verifier): void {
		if ($state === '' || ! function_exists('set_transient')) {
			return;
		}

		set_transient('migratekit_cloud_dropbox_' . hash('sha256', $state), $verifier, 600);
	}

	/**
	 * Fetch and drop the verifier held for a state.
	 *
	 * @param string $state State token.
	 * @return string '' when missing or expired.
	 */
	private function take_verifier(string $state): string {
		if ($state === '' || ! function_exists('get_transient')) {
			throw new \RuntimeException('The Dropbox connection state is missing. Click Connect again.');
		}

		$key    = 'migratekit_cloud_dropbox_' . hash('sha256', $state);
		$value  = get_transient($key);
		$exists = $value !== false;

		if (function_exists('delete_transient') && $exists) {
			delete_transient($key);
		}

		if ($exists === false) {
			throw new \RuntimeException('The Dropbox connection expired. Click Connect again.');
		}

		return is_string($value) ? $value : '';
	}

	/**
	 * Absolute redirect URL registered in the Dropbox app console.
	 *
	 * @return string
	 */
	public static function redirect_url(): string {
		return admin_url('admin-post.php?action=migratekit_cloud_dropbox_callback');
	}

	public function display(): array {
		return array(
			'client_id'     => $this->field('client_id'),
			'client_secret' => $this->field('client_secret') !== '' ? Manager::MASK : '',
			'folder'        => $this->field('folder'),
			'mode'          => $this->easy_mode() && $this->field('client_id') === '' ? 'easy' : 'advanced',
			'connected'     => $this->field('access_token') !== '',
			'account'       => $this->field('account'),
			'expires_at'    => (int) ( $this->config['expires_at'] ?? 0 ),
		);
	}

	public function chunk_size(): int {
		return self::CHUNK;
	}

	public function test(): void {
		$response = $this->api(self::API . '/users/get_current_account', 'POST', array(), '{}');

		if ($response['status'] !== 200) {
			throw new \RuntimeException($this->message('The Dropbox connection check failed', $response));
		}

		$decoded = json_decode($response['body'], true);
		$account = is_array($decoded) && isset($decoded['email']) ? (string) $decoded['email'] : '';

		if ($account !== '') {
			Manager::store_tokens(self::ID, array( 'account' => $account ));
			$this->config['account'] = $account;
		}
	}

	/**
	 * Authorisation code exchange, PKCE (no secret) or legacy secret flow.
	 *
	 * @param string $code  Authorisation code from the redirect or paste box.
	 * @param string $state State token binding the exchange to its start.
	 * @return void
	 */
	public function authorize_code(string $code, string $state = ''): void {
		$body = $this->easy_mode()
			? http_build_query(
				array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $this->app_key(),
					'code_verifier' => $this->take_verifier($state),
				)
			)
			: http_build_query(
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
			throw new \RuntimeException($this->message('Exchanging the Dropbox authorisation failed', $response));
		}

		$decoded = json_decode($response['body'], true);

		if (! is_array($decoded) || ! isset($decoded['access_token'])) {
			throw new \RuntimeException('The Dropbox token response could not be parsed.');
		}

		$expires = time() + (int) ( $decoded['expires_in'] ?? 14400 ) - 60;

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
		$response = $this->content(
			'/files/upload_session/start',
			array( 'Content-Type' => 'application/octet-stream' ),
			''
		);

		if ($response['status'] !== 200) {
			throw new \RuntimeException($this->message('Starting the Dropbox upload session failed', $response));
		}

		$decoded = json_decode($response['body'], true);

		if (! is_array($decoded) || ! isset($decoded['session_id'])) {
			throw new \RuntimeException('The Dropbox upload session could not be started.');
		}

		return array(
			'remote'  => $remote,
			'session' => (string) $decoded['session_id'],
			'offset'  => 0,
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

		$response = $this->content(
			'/files/upload_session/append_v2',
			array(
				'Dropbox-API-Arg' => (string) json_encode(
					array(
						'cursor' => array(
							'session_id' => (string) $session['session'],
							'offset'     => (int) ( $session['offset'] ?? 0 ),
						),
						'close'  => false,
					)
				),
				'Content-Type' => 'application/octet-stream',
			),
			$chunk
		);

		if ($response['status'] !== 200) {
			throw new \RuntimeException($this->message('Uploading a Dropbox chunk failed', $response));
		}

		$session['offset'] = (int) ( $session['offset'] ?? 0 ) + strlen($chunk);

		return strlen($chunk);
	}

	public function upload_finish(array &$session): void {
		$response = $this->content(
			'/files/upload_session/finish',
			array(
				'Dropbox-API-Arg' => (string) json_encode(
					array(
						'cursor' => array(
							'session_id' => (string) $session['session'],
							'offset'     => (int) ( $session['offset'] ?? 0 ),
						),
						'commit' => array(
							'path'       => $this->full_path((string) $session['remote']),
							'mode'       => 'overwrite',
							'autorename' => false,
						),
					)
				),
				'Content-Type' => 'application/octet-stream',
			),
			''
		);

		if ($response['status'] !== 200) {
			throw new \RuntimeException($this->message('Finishing the Dropbox upload session failed', $response));
		}
	}

	public function download_start(string $remote, int $size = 0): array {
		return array(
			'remote' => $remote,
			'size'   => $size,
		);
	}

	public function download_chunk(array &$session, int $offset, int $length): string {
		$response = $this->content(
			'/files/download',
			array(
				'Dropbox-API-Arg' => (string) json_encode(
					array( 'path' => $this->full_path((string) $session['remote']) )
				),
				'Range' => sprintf('bytes=%d-%d', $offset, $offset + $length - 1),
			),
			''
		);

		if ($response['status'] === 409 && strpos($response['body'], 'not_found') !== false) {
			throw new \RuntimeException('The remote backup does not exist: ' . (string) $session['remote']);
		}

		if ($response['status'] === 416) {
			return '';
		}

		if ($response['status'] !== 200 && $response['status'] !== 206) {
			throw new \RuntimeException($this->message('Downloading from Dropbox failed', $response));
		}

		return $response['body'];
	}

	public function list(): array {
		$objects = array();
		$cursor  = '';
		$guard   = 0;

		do {
			$payload = json_encode($cursor === '' ? array( 'path' => $this->full_path('') ) : array( 'cursor' => $cursor ));

			if ($payload === false) {
				throw new \RuntimeException('Could not encode the Dropbox request.');
			}

			$url      = $cursor === '' ? self::API . '/files/list_folder' : self::API . '/files/list_folder/continue';
			$response = $this->api($url, 'POST', array(), $payload);

			if ($response['status'] === 409 && strpos($response['body'], 'not_found') !== false) {
				break;
			}

			if ($response['status'] !== 200) {
				throw new \RuntimeException($this->message('Listing Dropbox failed', $response));
			}

			$decoded = json_decode($response['body'], true);

			if (! is_array($decoded) || ! isset($decoded['entries']) || ! is_array($decoded['entries'])) {
				throw new \RuntimeException('The Dropbox listing response could not be parsed.');
			}

			foreach ($decoded['entries'] as $entry) {
				if (! is_array($entry) || ( $entry['.tag'] ?? '' ) !== 'file') {
					continue;
				}

				$name = (string) $entry['name'];

				if (! preg_match('/\.wpress$/', $name)) {
					continue;
				}

				$objects[ $name ] = array(
					'name'  => $name,
					'size'  => (int) ( $entry['size'] ?? 0 ),
					'mtime' => isset($entry['client_modified']) ? (int) strtotime((string) $entry['client_modified']) : 0,
				);
			}

			$cursor = ( $decoded['has_more'] ?? false ) === true ? (string) ( $decoded['cursor'] ?? '' ) : '';
		} while ($cursor !== '' && ++$guard < 50);

		ksort($objects);

		return $objects;
	}

	public function delete(string $remote): void {
		$payload = json_encode(array( 'path' => $this->full_path($remote) ));

		if ($payload === false) {
			throw new \RuntimeException('Could not encode the Dropbox request.');
		}

		$response = $this->api(self::API . '/files/delete_v2', 'POST', array(), $payload);

		if ($response['status'] !== 200 && strpos($response['body'], 'not_found') === false) {
			throw new \RuntimeException($this->message('Deleting the remote backup failed', $response));
		}
	}

	/**
	 * Full remote path including the configured folder.
	 *
	 * @param string $remote Prefix-relative remote name.
	 * @return string
	 */
	private function full_path(string $remote): string {
		$folder = $this->field('folder');

		return $folder !== '' ? '/' . $folder . '/' . $remote : '/' . $remote;
	}

	/**
	 * JSON API request with token refresh retry.
	 *
	 * @param string               $url    Endpoint.
	 * @param string               $method HTTP method.
	 * @param array<string,string> $extra  Extra headers.
	 * @param string               $body   Request body.
	 * @return array{status:int,headers:array<string,string>,body:string,error:string}
	 */
	private function api(string $url, string $method, array $extra, string $body): array {
		$response = $this->send($url, $method, $extra, $body);

		if ($this->expired($response)) {
			$this->refresh_token();
			$response = $this->send($url, $method, $extra, $body);
		}

		return $response;
	}

	/**
	 * Content endpoint request with token refresh retry.
	 *
	 * @param string               $path  Content path.
	 * @param array<string,string> $extra Extra headers.
	 * @param string               $body  Request body ('' for none).
	 * @return array{status:int,headers:array<string,string>,body:string,error:string}
	 */
	private function content(string $path, array $extra, string $body): array {
		$response = $this->send(self::CONTENT . $path, 'POST', $extra, $body);

		if ($this->expired($response)) {
			$this->refresh_token();
			$response = $this->send(self::CONTENT . $path, 'POST', $extra, $body);
		}

		return $response;
	}

	/**
	 * @param string               $url    Endpoint.
	 * @param string               $method HTTP method.
	 * @param array<string,string> $extra  Extra headers.
	 * @param string               $body   Request body.
	 * @return array{status:int,headers:array<string,string>,body:string,error:string}
	 */
	private function send(string $url, string $method, array $extra, string $body): array {
		$headers = array_merge(
			array( 'Authorization' => 'Bearer ' . $this->field('access_token') ),
			$extra
		);

		return Transport::send($method, $url, $headers, $body === '' ? null : $body, 60, $this->transport());
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
	 * @param array{status:int,headers:array<string,string>,body:string,error:string} $response
	 * @return bool
	 */
	private function expired(array $response): bool {
		return $response['status'] === 401;
	}

	/**
	 * @return void
	 */
	private function refresh_token(): void {
		$refresh = $this->field('refresh_token');

		if ($refresh === '') {
			throw new \RuntimeException('The Dropbox authorisation expired. Reconnect the account.');
		}

		$body = http_build_query(
			$this->easy_mode()
				? array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
					'client_id'     => $this->app_key(),
				)
				: array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
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
			throw new \RuntimeException($this->message('Refreshing the Dropbox token failed', $response));
		}

		$decoded = json_decode($response['body'], true);

		if (! is_array($decoded) || ! isset($decoded['access_token'])) {
			throw new \RuntimeException('The Dropbox refresh response could not be parsed.');
		}

		$expires = time() + (int) ( $decoded['expires_in'] ?? 14400 ) - 60;

		Manager::store_tokens(self::ID, array(
			'access_token'  => (string) $decoded['access_token'],
			'refresh_token' => (string) ( $decoded['refresh_token'] ?? $refresh ),
			'expires_at'    => $expires,
		));

		$this->config['access_token']  = (string) $decoded['access_token'];
		$this->config['expires_at']    = $expires;
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

		if (is_array($decoded) && isset($decoded['error_summary'])) {
			return (string) $decoded['error_summary'];
		}

		return substr(trim($body), 0, 160);
	}
}
