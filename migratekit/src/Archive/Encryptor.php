<?php
/**
 * Password-based AES-256-CBC encryption for .wpress payload chunks.
 *
 * The key material mirrors the archive format: the first 16 bytes of the raw
 * SHA-1 of the password, zero-padded to a 32-byte AES-256 key. Each chunk is
 * stored as a 16-byte IV followed by the PKCS#7-padded ciphertext.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Archive;

final class Encryptor {

	public const CIPHER = 'AES-256-CBC';
	public const IV_LENGTH = 16;

	/** @var string */
	private $key;

	public function __construct(string $password) {
		$this->key = substr(sha1($password, true), 0, self::IV_LENGTH) . str_repeat("\0", self::IV_LENGTH);
	}

	/**
	 * Encrypt a chunk: returns IV . ciphertext.
	 *
	 * @param string $data Plaintext bytes.
	 * @return string
	 */
	public function encrypt(string $data): string {
		$iv = random_bytes(self::IV_LENGTH);

		$ciphertext = openssl_encrypt($data, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);

		if ($ciphertext === false) {
			throw new \RuntimeException('Unable to encrypt archive chunk.');
		}

		return $iv . $ciphertext;
	}

	/**
	 * Decrypt a chunk produced by encrypt().
	 *
	 * @param string $data IV . ciphertext.
	 * @return string Plaintext bytes.
	 */
	public function decrypt(string $data): string {
		if (strlen($data) < self::IV_LENGTH) {
			return '';
		}

		$iv         = substr($data, 0, self::IV_LENGTH);
		$ciphertext = substr($data, self::IV_LENGTH);

		$plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);

		if ($plaintext === false) {
			throw new \RuntimeException('Unable to decrypt archive chunk (wrong password or corrupt data).');
		}

		return $plaintext;
	}

	/**
	 * Produce a base64 signature proving knowledge of the password.
	 *
	 * @param string $text Known plaintext.
	 * @return string
	 */
	public function sign(string $text): string {
		return base64_encode($this->encrypt($text));
	}

	/**
	 * Verify a signature produced by sign().
	 *
	 * @param string $signature Base64 signature.
	 * @param string $text      Expected plaintext.
	 * @return bool
	 */
	public function verify(string $signature, string $text): bool {
		$decoded = base64_decode($signature, true);

		if ($decoded === false) {
			return false;
		}

		try {
			return hash_equals($text, $this->decrypt($decoded));
		} catch (\RuntimeException $e) {
			return false;
		}
	}
}
