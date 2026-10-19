<?php
/**
 * Enqueues the built Vue application on MigrateKit admin pages.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Admin;

use MigrateKit\Support\SecretKey;

final class AssetLoader {

	private const HANDLE = 'migratekit-app';

	private function __construct() {
	}

	public static function register(): void {
		add_action('admin_enqueue_scripts', array( 'MigrateKit\\Admin\\AssetLoader', 'enqueue' ));
	}

	public static function enqueue(): void {
		$page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';

		if (! in_array(
			$page,
			array( Menu::SLUG_EXPORT, Menu::SLUG_IMPORT, Menu::SLUG_BACKUPS, Menu::SLUG_SCHEDULES, Menu::SLUG_RESET, Menu::SLUG_CLOUD ),
			true
		)) {
			return;
		}

		$manifest = self::manifest();

		if ($manifest === null) {
			add_action('admin_notices', array( 'MigrateKit\\Admin\\AssetLoader', 'missing_build_notice' ));
			return;
		}

		$entry = isset($manifest['resources/app/main.ts']) ? $manifest['resources/app/main.ts'] : null;

		if (! is_array($entry) || empty($entry['file'])) {
			add_action('admin_notices', array( 'MigrateKit\\Admin\\AssetLoader', 'missing_build_notice' ));
			return;
		}

		$base = MIGRATEKIT_URL . 'build/';

		wp_enqueue_script(self::HANDLE, $base . $entry['file'], array(), MIGRATEKIT_VERSION, true);

		add_filter(
			'script_loader_tag',
			static function (string $tag, string $handle): string {
				if ($handle === self::HANDLE) {
					$tag = str_replace('<script ', '<script type="module" ', $tag);
				}

				return $tag;
			},
			10,
			2
		);

		if (! empty($entry['css']) && is_array($entry['css'])) {
			foreach ($entry['css'] as $index => $css) {
				wp_enqueue_style(self::HANDLE . '-css-' . $index, $base . $css, array(), MIGRATEKIT_VERSION);
			}
		}

		wp_localize_script(
			self::HANDLE,
			'MigrateKit',
			array(
				'ajaxUrl'       => admin_url('admin-ajax.php'),
				'adminPost'     => admin_url('admin-post.php'),
				'nonce'         => wp_create_nonce('migratekit_ajax'),
				'downloadNonce' => wp_create_nonce('migratekit_download'),
				'secret'        => SecretKey::get(),
				'siteUrl'       => home_url(),
				'maxUpload'     => wp_max_upload_size(),
				'version'       => MIGRATEKIT_VERSION,
				'backupsUrl'    => admin_url('admin.php?page=' . Menu::SLUG_BACKUPS),
				'isMultisite'   => (bool) is_multisite(),
				'siteCount'     => self::site_count(),
				'isSubsite'     => is_multisite() && get_current_blog_id() !== get_main_site_id(),
			)
		);
	}

	/**
	 * Number of sites in the network (1 on single sites).
	 *
	 * @return int
	 */
	public static function site_count(): int {
		if (! is_multisite() || ! function_exists('get_sites')) {
			return 1;
		}

		$count = get_sites(array( 'number' => 1000, 'count' => true ));

		return is_numeric($count) ? (int) max(1, $count) : 1;
	}

	/**
	 * Warns when the compiled front-end is absent, which happens when the
	 * plugin was installed from a source archive instead of the release ZIP.
	 *
	 * @return void
	 */
	public static function missing_build_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses(
				__(
					'<strong>MigrateKit:</strong> the compiled application is missing (<code>build/.vite/manifest.json</code>). This happens when the plugin is installed from a source archive instead of the release ZIP. Reinstall the plugin using the <code>migratekit</code> ZIP attached to the release.',
					'migratekit'
				),
				array(
					'strong' => array(),
					'code'   => array(),
				)
			)
		);
	}

	/**
	 * Reads the Vite manifest so the hashed bundle names can be resolved.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function manifest(): ?array {
		$candidates = array(
			MIGRATEKIT_PATH . 'build/.vite/manifest.json',
			MIGRATEKIT_PATH . 'build/manifest.json',
		);

		foreach ($candidates as $path) {
			if (! is_file($path)) {
				continue;
			}

			$decoded = json_decode((string) file_get_contents($path), true);

			if (is_array($decoded)) {
				return $decoded;
			}
		}

		return null;
	}
}
