<?php
/**
 * Admin-post actions such as backup downloads.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Http;

use MigrateKit\Archive\Reader;
use MigrateKit\Cloud\DriveProvider;
use MigrateKit\Cloud\DropboxProvider;
use MigrateKit\Cloud\Manager;
use MigrateKit\Storage\BackupsRepository;
use MigrateKit\Support\Capabilities;
use MigrateKit\Support\Paths;

final class AdminPostController {

	private function __construct() {
	}

	public static function register(): void {
		add_action('admin_post_migratekit_download', array( 'MigrateKit\\Http\\AdminPostController', 'download' ));
		add_action('admin_post_migratekit_backup_file', array( 'MigrateKit\\Http\\AdminPostController', 'download_entry' ));
		add_action('admin_post_migratekit_cloud_dropbox_callback', array( 'MigrateKit\\Http\\AdminPostController', 'dropbox_callback' ));
		add_action('admin_post_migratekit_cloud_gdrive_callback', array( 'MigrateKit\\Http\\AdminPostController', 'gdrive_callback' ));
	}

	/**
	 * Dropbox OAuth2 redirect landing.
	 *
	 * @return void
	 */
	public static function dropbox_callback(): void {
		self::oauth_callback(
			'dropbox',
			'migratekit_cloud_dropbox',
			static function (string $code, string $state): void {
				$provider = Manager::provider(DropboxProvider::ID);

				if ($provider === null || ! $provider instanceof DropboxProvider) {
					throw new \RuntimeException('The Dropbox integration is misconfigured.');
				}

				$provider->authorize_code($code, $state);
			}
		);
	}

	/**
	 * Google Drive OAuth2 redirect landing.
	 *
	 * @return void
	 */
	public static function gdrive_callback(): void {
		self::oauth_callback(
			'gdrive',
			'migratekit_cloud_gdrive',
			static function (string $code, string $state): void {
				$provider = Manager::provider(DriveProvider::ID);

				if ($provider === null || ! $provider instanceof DriveProvider) {
					throw new \RuntimeException('The Google Drive integration is misconfigured.');
				}

				$provider->authorize_code($code, $state);
			}
		);
	}

	/**
	 * Shared OAuth callback flow: permission, state, exchange, redirect.
	 *
	 * @param string               $id       Provider id (for URL notices).
	 * @param string               $nonce    Nonce action for the state value.
	 * @param callable(string,string):void $exchange Code exchange routine.
	 * @return void
	 */
	private static function oauth_callback(string $id, string $nonce, callable $exchange): void {
		if (! Capabilities::can_run_on_network() || ! Capabilities::can_export()) {
			wp_die('You do not have permission to connect cloud storage.', '', array( 'response' => 403 ));
		}

		if (isset($_GET['error']) && is_string($_GET['error'])) {
			wp_die(
				sprintf('The storage provider declined the connection: %s. Return to the Cloud page and try again.', esc_html((string) $_GET['error'])),
				'',
				array( 'response' => 400 )
			);
		}

		$code  = isset($_GET['code']) ? sanitize_text_field((string) wp_unslash($_GET['code'])) : '';
		$state = isset($_GET['state']) ? sanitize_text_field((string) wp_unslash($_GET['state'])) : '';

		if ($code === '' || $state === '' || wp_verify_nonce($state, $nonce) === false) {
			wp_die('The connection response is invalid or expired. Start again from the Cloud page.', '', array( 'response' => 400 ));
		}

		if (! Manager::save_guard($id)) {
			wp_die('Fill in the app credentials on the Cloud page before connecting.', '', array( 'response' => 400 ));
		}

		try {
			$exchange($code, $state);
		} catch (\Throwable $e) {
			wp_die(
				sprintf('Connecting the storage account failed: %s. Return to the Cloud page and try again.', esc_html($e->getMessage())),
				'',
				array( 'response' => 502 )
			);
		}

		wp_safe_redirect(
			admin_url('admin.php?page=migratekit-cloud&cloud=' . rawurlencode($id) . '&notice=connected')
		);
		exit;
	}

	public static function download(): void {
		if (! Capabilities::can_run_on_network() || ! Capabilities::can_export()) {
			wp_die('You do not have permission to download backups.', '', array( 'response' => 403 ));
		}

		check_admin_referer('migratekit_download');

		$name = isset($_GET['file']) ? basename((string) wp_unslash($_GET['file'])) : '';

		if (! BackupsRepository::validate_name($name)) {
			wp_die('Invalid backup name.', '', array( 'response' => 400 ));
		}

		$repository = new BackupsRepository();
		$backup     = $repository->find($name);

		if ($backup === null) {
			wp_die('Backup not found.', '', array( 'response' => 404 ));
		}

		nocache_headers();
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . $backup['name'] . '"');
		header('Content-Length: ' . (string) $backup['size']);
		header('X-Content-Type-Options: nosniff');

		readfile($backup['path']);
		exit;
	}

	/**
	 * Stream one file contained in an archive, decrypting when needed.
	 *
	 * @return void
	 */
	public static function download_entry(): void {
		if (! Capabilities::can_run_on_network() || ! Capabilities::can_import()) {
			wp_die('You do not have permission to download backups.', '', array( 'response' => 403 ));
		}

		check_admin_referer('migratekit_download');

		$name   = basename((string) wp_unslash((string) ( $_REQUEST['file'] ?? '' )));
		$path   = (string) wp_unslash((string) ( $_REQUEST['entry'] ?? '' ));
		$password = (string) wp_unslash((string) ( $_REQUEST['password'] ?? '' ));

		if (! BackupsRepository::validate_name($name)) {
			wp_die('Invalid backup name.', '', array( 'response' => 400 ));
		}

		if ($path === '' || $path !== sanitize_text_field($path)) {
			wp_die('Invalid archive entry.', '', array( 'response' => 400 ));
		}

		$repository = new BackupsRepository();
		$backup     = $repository->find($name);

		if ($backup === null) {
			wp_die('Backup not found.', '', array( 'response' => 404 ));
		}

		try {
			$reader = new Reader($backup['path'], null, $password !== '' ? $password : null);

			if (! $reader->is_valid()) {
				wp_die('The archive is not a valid backup.', '', array( 'response' => 400 ));
			}

			$entry = $reader->find($path);

			if ($entry === null) {
				wp_die('The requested file is not part of this backup.', '', array( 'response' => 404 ));
			}

			$temp = wp_tempnam($entry->name());

			if (! is_string($temp) || $temp === '') {
				$temp = rtrim(Paths::storage(), '/\\') .DIRECTORY_SEPARATOR . md5((string) mt_rand()) . '-' . basename($entry->name());
			}

			$reader->extract($entry, $temp);
		} catch (\Throwable $e) {
			wp_die(
				sprintf('The file could not be read from this backup: %s', esc_html($e->getMessage())),
				'',
				array( 'response' => 400 )
			);
		}

		nocache_headers();
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . basename($entry->name()) . '"');
		header('Content-Length: ' . (string) filesize($temp));
		header('X-Content-Type-Options: nosniff');

		readfile($temp);
		@unlink($temp);
		exit;
	}
}
