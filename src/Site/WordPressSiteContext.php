<?php
/**
 * Builds a SiteContext from the running WordPress installation.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Site;

final class WordPressSiteContext {

	private function __construct() {
	}

	public static function build(): SiteContext {
		global $wpdb;

		$uploads = wp_upload_dir();
		$themes  = array();

		foreach (wp_get_themes() as $slug => $theme) {
			$themes[] = (string) $slug;
		}

		$server = array();

		$htaccess = ABSPATH . '.htaccess';

		if (is_file($htaccess)) {
			$server['.htaccess'] = (string) file_get_contents($htaccess);
		}

		$webconfig = ABSPATH . 'web.config';

		if (is_file($webconfig)) {
			$server['web.config'] = (string) file_get_contents($webconfig);
		}

		return new SiteContext(
			array(
				'site_url'          => site_url(),
				'home_url'          => home_url(),
				'internal_site_url' => site_url(),
				'internal_home_url' => home_url(),
				'absolute_path'     => trailingslashit(ABSPATH),
				'content_path'      => trailingslashit(WP_CONTENT_DIR),
				'plugins_path'      => trailingslashit(WP_PLUGIN_DIR),
				'themes_path'       => trailingslashit(get_theme_root()),
				'uploads_path'      => trailingslashit((string) $uploads['basedir']),
				'uploads_url'       => trailingslashit((string) $uploads['baseurl']),
				'wp_version'        => get_bloginfo('version'),
				'db_version'        => method_exists($wpdb, 'db_version') ? $wpdb->db_version() : '',
				'db_charset'        => (string) $wpdb->charset,
				'db_collate'        => (string) $wpdb->collate,
				'table_prefix'      => (string) $wpdb->prefix,
				'base_prefix'       => (string) $wpdb->base_prefix,
				'plugins'           => array_values((array) get_option('active_plugins', array())),
				'themes'            => $themes,
				'template'          => (string) get_option('template'),
				'stylesheet'        => (string) get_option('stylesheet'),
				'server'            => $server,
				'is_multisite'      => is_multisite(),
				'network_plugins'   => is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', array())) : array(),
				'network_sites'     => is_multisite() ? self::network_sites() : array(),
				'current_blog_id'   => (int) get_current_blog_id(),
			)
		);
	}

	/**
	 * Describes every site in the network.
	 *
	 * Everything here is computed without blog switches: `wp_upload_dir()`
	 * bakes the boot context's URL into subsite results, so uploads resolve
	 * from the blogs table options instead. The network main site is
	 * identified with get_main_site_id(), never with the current blog.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function network_sites(): array {
		global $wpdb;

		$base              = (string) $wpdb->base_prefix;
		$main_id           = (int) get_main_site_id();
		$subdomain_install = defined('SUBDOMAIN_INSTALL') && SUBDOMAIN_INSTALL;

		$root_dir = self::central_uploads_dir($main_id);
		$root_url = self::central_uploads_url($main_id);

		$sites = array();

		foreach (get_sites(array( 'number' => 1000, 'orderby' => 'blog_id', 'order' => 'ASC' )) as $network_site) {
			$id      = (int) $network_site->blog_id;
			$is_main = $id === $main_id;

			$site_url = get_site_url($id);

			if ($is_main) {
				$uploads_dir = $root_dir;
				$uploads_url = $root_url;
			} else {
				$option_dir = (string) get_blog_option($id, 'upload_path');
				$option_url = (string) get_blog_option($id, 'upload_url_path');

				$custom_dir = $option_dir !== '' ? self::resolve_option_path($option_dir) : '';
				$custom_url = $option_url !== '' ? self::resolve_option_url($site_url, $option_url) : '';

				if ($subdomain_install) {
					$uploads_dir = $custom_dir !== '' ? $custom_dir : $root_dir;
					$uploads_url = $custom_url !== '' ? $custom_url : $site_url . '/wp-content/uploads/';
				} else {
					$uploads_dir = $custom_dir !== '' ? $custom_dir : $root_dir . 'sites/' . $id . '/';
					$uploads_url = $custom_url !== '' ? $custom_url : $root_url . 'sites/' . $id . '/';
				}
			}

			$sites[] = array(
				'blog_id'      => $id,
				'is_main'      => $is_main,
				'site_url'     => $site_url,
				'home_url'     => get_home_url($id),
				'table_prefix' => $is_main ? $base : $base . $id . '_',
				'domain'       => (string) $network_site->domain,
				'path'         => (string) $network_site->path,
				'uploads_path' => trailingslashit((string) $uploads_dir),
				'uploads_url'  => trailingslashit((string) $uploads_url),
			);
		}

		return $sites;
	}

	/**
	 * Physical uploads directory shared across the network.
	 *
	 * @param int $main_id Network main site id.
	 * @return string
	 */
	private static function central_uploads_dir(int $main_id): string {
		$custom = (string) get_blog_option($main_id, 'upload_path');

		if ($custom !== '') {
			return trailingslashit(self::resolve_option_path($custom));
		}

		return trailingslashit(WP_CONTENT_DIR) . 'uploads' . DIRECTORY_SEPARATOR;
	}

	/**
	 * Uploads URL served from the main site's host.
	 *
	 * @param int $main_id Network main site id.
	 * @return string
	 */
	private static function central_uploads_url(int $main_id): string {
		$custom = (string) get_blog_option($main_id, 'upload_url_path');

		if ($custom !== '') {
			return trailingslashit(self::resolve_option_url(get_site_url($main_id), $custom));
		}

		return trailingslashit(get_site_url($main_id)) . 'wp-content/uploads/';
	}

	/**
	 * Resolves the per-site upload_path option to a directory.
	 *
	 * @param string $option Stored value; absolute or relative to ABSPATH.
	 * @return string
	 */
	private static function resolve_option_path(string $option): string {
		$value = trim($option, "/\\\t\n\r\0");

		if ($value === '') {
			return '';
		}

		if (preg_match('/^([A-Za-z]:\\\\|\\\\\\\\|\/)/', $option) === 1) {
			return $option;
		}

		return trailingslashit(ABSPATH) . $value;
	}

	/**
	 * Resolves the per-site upload_url_path option to a URL.
	 *
	 * @param string $site_url The owning site's URL.
	 * @param string $option   Stored value; absolute URL or a path label.
	 * @return string
	 */
	private static function resolve_option_url(string $site_url, string $option): string {
		$value = trim($option);

		if ($value === '') {
			return '';
		}

		if (preg_match('#^https?://#i', $value) === 1) {
			return $value;
		}

		return trailingslashit($site_url) . ltrim($value, '/');
	}
}
