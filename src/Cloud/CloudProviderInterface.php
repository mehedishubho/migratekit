<?php
/**
 * Contract every cloud storage backend implements.
 *
 * Providers stream archive bytes in fixed-size chunks so exports or imports
 * of any size can run inside WordPress request budgets, resumable via job
 * state. Sessions are opaque hashed-safe arrays stored in job state.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud;

interface CloudProviderInterface {

	/**
	 * Stable provider identifier used in options, jobs and REST routes.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human readable label for the admin UI.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether the stored settings contain enough credentials to work.
	 *
	 * @return bool
	 */
	public function is_ready(): bool;

	/**
	 * Connection check. Throws a RuntimeException with a useful message.
	 *
	 * @return void
	 */
	public function test(): void;

	/**
	 * Authorisation URL for OAuth providers, or '' when none applies.
	 *
	 * @return string
	 */
	public function authorize_url(): string;

	/**
	 * Begin an interactive connection. Returns the browser URL to open,
	 * a state token that must travel with the follow-up exchange, and
	 * whether the provider ends with a paste-a-code step instead of an
	 * automatic redirect. Blank URL when the provider cannot connect.
	 *
	 * @return array{url:string,state:string,paste:bool}
	 */
	public function connect(): array;

	/**
	 * Preferred chunk size for uploads and ranged downloads (bytes).
	 *
	 * @return int
	 */
	public function chunk_size(): int;

	/**
	 * Start an upload session for a remote object. Returns an opaque
	 * session array stored in job state.
	 *
	 * @param string $remote Remote object name (already prefix-stripped).
	 * @return array<string,mixed>
	 */
	public function upload_start(string $remote): array;

	/**
	 * Transfer one chunk of a local file into the remote object.
	 *
	 * A session that cannot be continued throws SessionLostException. A
	 * session that carries an 'offset' key states there how many bytes of the
	 * local file the remote holds, which may be ahead of the job after a
	 * request was cut off before it saved.
	 *
	 * @param array  $session    Session array (by reference).
	 * @param string $local_path Local source file.
	 * @param int    $offset     Seek offset in the local file.
	 * @param int    $length     Number of bytes to send.
	 * @return int Bytes sent (0 signals the transfer must stop, or that the remote already holds this chunk).
	 * @throws \MigrateKit\Cloud\SessionLostException
	 */
	public function upload_chunk(array &$session, string $local_path, int $offset, int $length): int;

	/**
	 * Close the upload session. Throws on a failed finalisation.
	 *
	 * @param array $session Session array (by reference).
	 * @return void
	 */
	public function upload_finish(array &$session): void;

	/**
	 * Discard an upload that will not be finished. Nothing to do where the
	 * remote drops unused sessions by itself.
	 *
	 * @param array $session Session array.
	 * @return void
	 */
	public function upload_abort(array $session): void;

	/**
	 * Start a download session. The returned array must carry 'size' when
	 * the remote size is known, so progress can be computed.
	 *
	 * @param string $remote Remote object name (already prefix-stripped).
	 * @param int    $size   Known remote size, 0 when unknown.
	 * @return array<string,mixed>
	 */
	public function download_start(string $remote, int $size = 0): array;

	/**
	 * Fetch one chunk of the remote object.
	 *
	 * @param array $session Session array (by reference).
	 * @param int   $offset  Absolute remote offset.
	 * @param int   $length  Number of bytes to fetch.
	 * @return string Bytes fetched ('' at end of the remote object).
	 */
	public function download_chunk(array &$session, int $offset, int $length): string;

	/**
	 * Prefix-stripped remote objects, name-keyed: name, size, mtime.
	 *
	 * @return array<string,array{name:string,size:int,mtime:int}>
	 */
	public function list(): array;

	/**
	 * Delete a remote object. A missing object is a no-op.
	 *
	 * @param string $remote Remote object name (already prefix-stripped).
	 * @return void
	 */
	public function delete(string $remote): void;

	/**
	 * Sanitise raw request settings into stored configuration.
	 *
	 * @param array $input Raw settings.
	 * @return array Sanitised settings.
	 */
	public static function settings(array $input): array;

	/**
	 * Configuration safe for the UI: secrets masked when set.
	 *
	 * @return array<string,mixed>
	 */
	public function display(): array;
}
