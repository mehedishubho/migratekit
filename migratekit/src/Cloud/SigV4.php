<?php
/**
 * AWS Signature Version 4 request signer used by the S3 provider.
 *
 * Path-style bucket addressing is used everywhere so the same signer works
 * for amazonaws.com and any S3-compatible endpoint (MinIO, Spaces, Wasabi,
 * B2 S3-compatible mode).
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud;

final class SigV4 {

	private function __construct() {
	}

	/**
	 * Sign and return the header set for one request.
	 *
	 * @param string $method  HTTP method (uppercase).
	 * @param string $host    Host name without scheme.
	 * @param string $path    Canonical path, includes the bucket, '/' prefix.
	 * @param array  $query   Query parameters (Assoc), encoded canonically.
	 * @param array  $headers Headers to sign (lowercase names). Extra headers
	 *                        may be included but only these get signed.
	 * @param string $region  Service region, e.g. us-east-1.
	 * @param string $key     Access key id.
	 * @param string $secret  Secret access key.
	 * @param string $payload Literal payload hash or UNSIGNED-PAYLOAD.
	 * @return array<string,string> Headers to send (includes signature).
	 */
	public static function sign(string $method, string $host, string $path, array $query, array $headers, string $region, string $key, string $secret, string $payload): array {
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

		return self::sign_at($method, $host, $path, $query, $headers, $region, $key, $secret, $payload, $now);
	}

	/**
	 * Sign with an explicit timestamp (used by the documented golden test).
	 *
	 * @param string            $method  HTTP method (uppercase).
	 * @param string            $host    Host name without scheme.
	 * @param string            $path    Canonical path (/bucket/key).
	 * @param array<string,string> $query Query parameters.
	 * @param array<string,string> $headers Signed candidate headers.
	 * @param string            $region  Region.
	 * @param string            $key     Access key id.
	 * @param string            $secret  Secret access key.
	 * @param string            $payload Payload hash literal.
	 * @param \DateTimeImmutable $now    Signing clock.
	 * @return array<string,string>
	 */
	public static function sign_at(string $method, string $host, string $path, array $query, array $headers, string $region, string $key, string $secret, string $payload, \DateTimeImmutable $now): array {
		$amz_date = $now->format('Ymd\THis\Z');
		$date     = $now->format('Ymd');

		$headers = self::lowercase($headers);
		$headers['host'] = $host;
		$headers['x-amz-date'] = $amz_date;
		$headers['x-amz-content-sha256'] = $payload;

		ksort($headers);

		$signed = array();

		foreach ($headers as $name => $value) {
			$signed[] = $name;
		}

		$canonical_headers = '';
		foreach ($headers as $name => $value) {
			$canonical_headers .= $name . ':' . self::trim_value($value) . "\n";
		}

		$signed_headers = implode(';', $signed);
		$canonical_query = self::canonical_query($query);
		$canonical = implode("\n", array(
			$method,
			self::encode_path($path),
			$canonical_query,
			$canonical_headers,
			$signed_headers,
			$payload,
		));

		$scope = $date . '/' . $region . '/s3/aws4_request';
		$string_to_sign = implode("\n", array(
			'AWS4-HMAC-SHA256',
			$amz_date,
			$scope,
			hash('sha256', $canonical),
		));

		// Key derivation carries raw digests between steps; only the final
		// signature returns hex.
		$derivation = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
		$derivation = hash_hmac('sha256', $region, $derivation, true);
		$derivation = hash_hmac('sha256', 's3', $derivation, true);
		$derivation = hash_hmac('sha256', 'aws4_request', $derivation, true);
		$signature  = hash_hmac('sha256', $string_to_sign, $derivation, false);

		$headers['authorization'] = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$key,
			$scope,
			$signed_headers,
			$signature
		);

		return self::retitle($headers);
	}

	/**
	 * HMAC-SHA256, hex output (used opportunistically by tests).
	 *
	 * @param string $key     HMAC key.
	 * @param string $message Payload.
	 * @return string
	 */
	private static function hmac(string $key, string $message): string {
		return hash_hmac('sha256', $message, $key, false);
	}

	/**
	 * Collapse sequential spaces and trim, per the SigV4 header rules.
	 *
	 * @param string $value Header value.
	 * @return string
	 */
	private static function trim_value(string $value): string {
		return trim((string) preg_replace('/[[:space:]]+/', ' ', $value));
	}

	/**
	 * @param array<string,string> $headers Any case.
	 * @return array<string,string> Lowercase keyed, sorted.
	 */
	private static function lowercase(array $headers): array {
		$lower = array();

		foreach ($headers as $name => $value) {
			$lower[ strtolower((string) $name) ] = is_string($value) ? $value : (string) $value;
		}

		return $lower;
	}

	/**
	 * Restore the mixed-case names callers expect (Authorization, Host...).
	 *
	 * @param array<string,string> $headers Lowercase signed headers.
	 * @return array<string,string>
	 */
	private static function retitle(array $headers): array {
		$known = array(
			'host'                      => 'Host',
			'authorization'             => 'Authorization',
			'x-amz-date'                => 'X-Amz-Date',
			'x-amz-content-sha256'      => 'X-Amz-Content-SHA256',
			'x-amz-content-type'        => 'X-Amz-Content-Type',
			'x-amz-storage-class'       => 'X-Amz-Storage-Class',
			'x-amz-server-side-encryption' => 'X-Amz-Server-Side-Encryption',
			'content-type'              => 'Content-Type',
			'content-length'            => 'Content-Length',
			'content-disposition'       => 'Content-Disposition',
			'if-match'                  => 'If-Match',
			'if-none-match'             => 'If-None-Match',
			'range'                     => 'Range',
		);

		$restored = array();

		foreach ($headers as $name => $value) {
			$restored[ isset($known[ $name ]) ? $known[ $name ] : $name ] = $value;
		}

		return $restored;
	}

	/**
	 * RFC3986 query encoding, sorted by key, '' drops the trailing =.
	 *
	 * @param array<string,string> $query Query parameters.
	 * @return string
	 */
	public static function canonical_query(array $query): string {
		if ($query === array()) {
			return '';
		}

		$parts = array();

		foreach ($query as $key => $value) {
			$parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
		}

		sort($parts);

		return implode('&', $parts);
	}

	/**
	 * Encode a path per S3 SigV4: '/' separators stay literal, segments are
	 * RFC3986-encoded.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	public static function encode_path(string $path): string {
		$segments = explode('/', $path);

		foreach ($segments as $index => $segment) {
			if ($segment === '') {
				continue;
			}

			$segments[ $index ] = rawurlencode($segment);
		}

		return implode('/', $segments);
	}
}
