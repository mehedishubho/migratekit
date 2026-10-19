<?php
/**
 * Manifest and entry inspection for stored or uploaded archives.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Archive;

final class Inspector {

	private function __construct() {
	}

	/**
	 * Decode an archive's package.json manifest.
	 *
	 * @param string $path     Archive path.
	 * @param string $password Decryption password (empty when unencrypted).
	 * @return array<string,mixed>
	 */
	public static function package(string $path, string $password = ''): array {
		$reader = new Reader($path, null, $password !== '' ? $password : null);
		$entry  = $reader->find('package.json');

		if ($entry === null) {
			throw new \RuntimeException('The archive has no manifest.');
		}

		$decoded = json_decode((string) $reader->read($entry), true);

		if (! is_array($decoded)) {
			throw new \RuntimeException('The archive manifest is invalid.');
		}

		return $decoded;
	}

	/**
	 * @param array<string,mixed> $package Archive manifest.
	 * @return bool
	 */
	public static function is_encrypted(array $package): bool {
		return ! empty($package['Encrypted']);
	}

	/**
	 * Verify a decryption password against the manifest signature.
	 *
	 * @param array  $package  Archive manifest.
	 * @param string $password Supplied password.
	 * @return void
	 */
	public static function verify_password(array $package, string $password): void {
		if (! self::is_encrypted($package)) {
			return;
		}

		if ($password === '') {
			throw new \RuntimeException('This archive is encrypted. Provide the decryption password.');
		}

		$signature = isset($package['EncryptedSignature']) ? (string) $package['EncryptedSignature'] : '';

		if ($signature !== '' && ! ( new Encryptor($password) )->verify($signature, Protocol::SIGN_TEXT)) {
			throw new \RuntimeException('The decryption password is incorrect.');
		}
	}

	/**
	 * Sorted file listing of an archive: arrays with path and size.
	 *
	 * @param string $path     Archive path.
	 * @param string $password Decryption password (empty when unencrypted).
	 * @return array<int,array{path:string,size:int}>
	 */
	public static function entries(string $path, string $password = ''): array {
		$reader = new Reader($path, null, $password !== '' ? $password : null);

		if (! $reader->is_valid()) {
			throw new \RuntimeException('The archive is not a valid backup.');
		}

		$items = array();

		foreach ($reader->entries() as $entry) {
			$items[] = array(
				'path' => $entry->relative_path(),
				'size' => $entry->size(),
			);
		}

		usort(
			$items,
			static function (array $left, array $right): int {
				return strcasecmp($left['path'], $right['path']);
			}
		);

		return $items;
	}

	/**
	 * Resolve one entry path.
	 *
	 * @param string $path     Archive path.
	 * @param string $relative Entry path inside the archive.
	 * @param string $password Decryption password.
	 * @return Entry
	 */
	public static function locate(string $path, string $relative, string $password = ''): Entry {
		$reader = new Reader($path, null, $password !== '' ? $password : null);

		if (! $reader->is_valid()) {
			throw new \RuntimeException('The archive is not a valid backup.');
		}

		$entry = $reader->find($relative);

		if ($entry === null) {
			throw new \RuntimeException('The requested file is not part of this archive.');
		}

		return $entry;
	}
}
