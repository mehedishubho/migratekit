<?php
/**
 * HTTP transport for cloud providers: WP HTTP API inside WordPress, curl
 * outside it (CLI and test harness without a WordPress boot).
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud;

final class Transport {

	/**
	 * Send an HTTP request. Curve errors surface as status 0.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $url     Full URL.
	 * @param array      $headers Request headers.
	 * @param mixed      $body    String body, or null for none.
	 * @param int        $timeout Seconds.
	 * @param callable|null $http Test transport: receives the arguments
	 *                              and returns array<string,mixed>.
	 * @return array{status:int,headers:array<string,string>,body:string,error:string}
	 */
	public static function send(string $method, string $url, array $headers = array(), $body = null, int $timeout = 60, ?callable $http = null): array {
		if ($http !== null) {
			return self::normalise($http($method, $url, $headers, $body, $timeout));
		}

		if (function_exists('wp_remote_request')) {
			return self::via_wp($method, $url, $headers, $body, $timeout);
		}

		return self::via_curl($method, $url, $headers, $body, $timeout);
	}

	/**
	 * @param mixed $result Transport result.
	 * @return array{status:int,headers:array<string,string>,body:string,error:string}
	 */
	private static function normalise($result): array {
		if (! is_array($result)) {
			return array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => 'invalid transport result' );
		}

		return array(
			'status'  => isset($result['status']) ? (int) $result['status'] : 0,
			'headers' => isset($result['headers']) && is_array($result['headers']) ? self::lowercase_keys($result['headers']) : array(),
			'body'    => isset($result['body']) ? (string) $result['body'] : '',
			'error'   => isset($result['error']) ? (string) $result['error'] : '',
		);
	}

	/**
	 * @param string $method  HTTP method.
	 * @param string $url     Full URL.
	 * @param array  $headers Request headers.
	 * @param mixed  $body    Body or null.
	 * @param int    $timeout Seconds.
	 * @return array{status:int,headers:array<string,string>,body:string,error:string}
	 */
	private static function via_wp(string $method, string $url, array $headers, $body, int $timeout): array {
		$args = array(
			'method'     => $method,
			'timeout'    => $timeout,
			'redirection' => 5,
			'headers'    => $headers,
			'sslverify'  => true,
			'compress'   => false,
		);

		if ($body !== null) {
			$args['body'] = $body;
		}

		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) {
			return array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => (string) $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$raw  = wp_remote_retrieve_headers($response);
		$map  = array();

		if (is_object($raw) && method_exists($raw, 'getAll')) {
			$map = (array) $raw->getAll();
		} elseif (is_object($raw) && method_exists($raw, 'getArrayCopy')) {
			$map = $raw->getArrayCopy();
		} else {
			$map = (array) $raw;
		}

		return array(
			'status'  => $code,
			'headers' => self::lowercase_keys($map),
			'body'    => (string) wp_remote_retrieve_body($response),
			'error'   => '',
		);
	}

	/**
	 * @param string $method  HTTP method.
	 * @param string $url     Full URL.
	 * @param array  $headers Request headers.
	 * @param mixed  $body    Body or null.
	 * @param int    $timeout Seconds.
	 * @return array{status:int,headers:array<string,string>,body:string,error:string}
	 */
	private static function via_curl(string $method, string $url, array $headers, $body, int $timeout): array {
		$handle = curl_init();

		if ($handle === false) {
			return array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => 'curl unavailable' );
		}

		$options = array(
			CURLOPT_URL            => $url,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_HEADER         => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_HTTPHEADER     => self::header_lines($headers),
		);

		if ($body !== null) {
			$options[ CURLOPT_POSTFIELDS ] = $body;
		}

		// HEAD responses never carry a body; without NOBODY curl waits for
		// one until the timeout.
		if (strtoupper($method) === 'HEAD') {
			$options[ CURLOPT_NOBODY ] = true;
		}

		curl_setopt_array($handle, $options);
		$raw = curl_exec($handle);

		if ($raw === false) {
			$message = (string) curl_error($handle);
			curl_close($handle);

			return array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => $message );
		}

		$status  = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
		$length  = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
		$headers = self::parse_header_block((string) substr((string) $raw, 0, $length));
		$payload = (string) substr((string) $raw, $length);

		curl_close($handle);

		return array( 'status' => $status, 'headers' => $headers, 'body' => $payload, 'error' => '' );
	}

	/**
	 * @param array<string,string> $headers Header map.
	 * @return string[]
	 */
	private static function header_lines(array $headers): array {
		$lines = array();

		foreach ($headers as $name => $value) {
			$value = is_string($value) ? $value : (string) $value;
			$lines[] = $name . ': ' . $value;
		}

		return $lines;
	}

	/**
	 * @param string|null $block Raw header block.
	 * @return array<string,string>
	 */
	private static function parse_header_block(?string $block): array {
		$headers = array();

		foreach (preg_split('/\r?\n/', (string) $block) ?: array() as $line) {
			$pos = strpos($line, ':');

			if ($pos === false || $pos === 0) {
				continue;
			}

			$name  = strtolower(trim(substr($line, 0, $pos)));
			$value = trim((string) substr($line, $pos + 1));

			$headers[ $name ] = isset($headers[ $name ]) ? (string) $headers[ $name ] . ', ' . $value : $value;
		}

		return $headers;
	}

	/**
	 * @param array<string,mixed> $headers Header map.
	 * @return array<string,string>
	 */
	private static function lowercase_keys(array $headers): array {
		$lower = array();

		foreach ($headers as $name => $value) {
			$lower[ strtolower((string) $name) ] = is_array($value) ? implode(', ', $value) : (string) $value;
		}

		return $lower;
	}
}
