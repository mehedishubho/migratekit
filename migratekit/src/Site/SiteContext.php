<?php
/**
 * Immutable-ish site metadata used to build an export and interpret an import.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Site;

final class SiteContext {

	/** @var array<string,mixed> */
	private $data;

	/** @var array<string,mixed> */
	private static $defaults = array(
		'site_url'            => '',
		'home_url'            => '',
		'internal_site_url'   => '',
		'internal_home_url'   => '',
		'absolute_path'       => '',
		'content_path'        => '',
		'plugins_path'        => '',
		'themes_path'         => '',
		'uploads_path'        => '',
		'uploads_url'         => '',
		'wp_version'          => '',
		'db_version'          => '',
		'db_charset'          => 'utf8mb4',
		'db_collate'          => '',
		'table_prefix'        => 'wp_',
		'base_prefix'         => 'wp_',
		'plugins'             => array(),
		'themes'              => array(),
		'template'            => '',
		'stylesheet'          => '',
		'server'              => array(),
		'is_multisite'        => false,
		'network_sites'       => array(),
		'current_blog_id'     => 1,
	);

	/**
	 * @param array<string,mixed> $data Site metadata.
	 */
	public function __construct(array $data = array()) {
		$this->data = array_merge(self::$defaults, $data);
	}

	/**
	 * @param mixed $default Fallback value.
	 * @return mixed
	 */
	public function get(string $key, $default = null) {
		if (array_key_exists($key, $this->data)) {
			return $this->data[ $key ];
		}

		return $default;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function all(): array {
		return $this->data;
	}
}
