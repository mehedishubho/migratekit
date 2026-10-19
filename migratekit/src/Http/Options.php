<?php
/**
 * Request option sanitization shared by AJAX, REST and WP-CLI.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Http;

final class Options {

	/**
	 * Export flags accepted from every transport.
	 *
	 * @var string[]
	 */
	private const FLAGS = array(
		'no_media',
		'no_plugins',
		'no_themes',
		'no_inactive_plugins',
		'no_inactive_themes',
		'no_muplugins',
		'no_cache',
		'no_database',
		'no_post_revisions',
		'no_spam_comments',
		'no_email_replace',
	);

	private function __construct() {
	}

	/**
	 * @param array $source Request data.
	 * @return array
	 */
	public static function export(array $source): array {
		$compression = isset($source['compression']) ? (string) $source['compression'] : '';

		if (! in_array($compression, array( '', 'gzip', 'bzip2' ), true)) {
			$compression = '';
		}

		$password = isset($source['password']) ? (string) $source['password'] : '';

		$network = isset($source['network']) ? (string) $source['network'] : '';

		if (! in_array($network, array( '', 'whole', 'subsite' ), true)) {
			$network = '';
		}

		$options = array(
			'compression' => $compression,
			'password'    => substr($password, 0, 255),
			'network'     => $network,
		);

		foreach (self::FLAGS as $flag) {
			$options[ $flag ] = self::truthy(isset($source[ $flag ]) ? $source[ $flag ] : false);
		}

		$old = isset($source['replace_old']) && is_array($source['replace_old']) ? $source['replace_old'] : array();
		$new = isset($source['replace_new']) && is_array($source['replace_new']) ? $source['replace_new'] : array();

		$clean = static function ($value): string {
			return is_scalar($value) ? (string) $value : '';
		};

		$options['replace_old'] = array_values(array_map($clean, $old));
		$options['replace_new'] = array_values(array_map($clean, $new));

		$clean_table = static function ($value): string {
			if (! is_scalar($value)) {
				return '';
			}

			$table = trim((string) $value);

			return preg_match('/^[A-Za-z0-9._$-]{1,64}$/', $table) === 1 ? $table : '';
		};

		$excluded = isset($source['excluded_tables']) && is_array($source['excluded_tables'])
			? $source['excluded_tables']
			: array();

		$included = isset($source['included_tables']) && is_array($source['included_tables'])
			? $source['included_tables']
			: array();

		$options['excluded_tables'] = array_values(array_unique(array_filter(array_map($clean_table, $excluded))));
		$options['included_tables'] = array_values(array_unique(array_filter(array_map($clean_table, $included))));

		return $options;
	}

	/**
	 * Reset flags accepted from every transport.
	 *
	 * @var string[]
	 */
	private const RESET_FLAGS = array(
		'delete_media_files',
		'delete_users',
		'purge_third_party',
	);

	/**
	 * @param array $source Request data.
	 * @return array
	 */
	public static function reset(array $source): array {
		$options = array();

		foreach (self::RESET_FLAGS as $flag) {
			$options[ $flag ] = self::truthy(isset($source[ $flag ]) ? $source[ $flag ] : false);
		}

		return $options;
	}

	/**
	 * @param array $source Request data.
	 * @return array
	 */
	public static function import(array $source): array {
		$password = isset($source['password']) ? (string) $source['password'] : '';

		return array(
			'password'         => substr($password, 0, 255),
			'no_email_replace' => self::truthy(isset($source['no_email_replace']) ? $source['no_email_replace'] : false),
		);
	}

	/**
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function truthy($value): bool {
		return $value === true
			|| $value === 1
			|| $value === '1'
			|| $value === 'true'
			|| $value === 'yes';
	}
}
