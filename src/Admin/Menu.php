<?php
/**
 * Admin menu registration.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Admin;

use MigrateKit\Storage\BackupsRepository;
use MigrateKit\Support\Capabilities;

final class Menu {

	public const SLUG_HOME      = 'migratekit-home';
	public const SLUG_EXPORT    = 'migratekit-export';
	public const SLUG_IMPORT    = 'migratekit-import';
	public const SLUG_BACKUPS   = 'migratekit-backups';
	public const SLUG_SCHEDULES = 'migratekit-schedules';
	public const SLUG_RESET     = 'migratekit-reset';
	public const SLUG_CLOUD     = 'migratekit-cloud';

	private function __construct() {
	}

	public static function register(): void {
		add_action('admin_menu', array( 'MigrateKit\\Admin\\Menu', 'add_menu' ));

		if (is_multisite()) {
			add_action('network_admin_menu', array( 'MigrateKit\\Admin\\Menu', 'add_menu' ));
		}
	}

	public static function add_menu(): void {
		$label = 'Backups';
		$count = ( new BackupsRepository() )->count();

		if ($count > 0) {
			$label .= sprintf(' <span class="awaiting-mod">%d</span>', $count);
		}

		add_menu_page(
			'MigrateKit',
			'MigrateKit',
			Capabilities::EXPORT,
			self::SLUG_HOME,
			array( 'MigrateKit\\Admin\\Menu', 'render_home' ),
			'dashicons-migrate',
			76.3
		);

		add_submenu_page(
			self::SLUG_HOME,
			'Dashboard',
			'Dashboard',
			Capabilities::EXPORT,
			self::SLUG_HOME,
			array( 'MigrateKit\\Admin\\Menu', 'render_home' )
		);

		add_submenu_page(
			self::SLUG_HOME,
			'Export',
			'Export',
			Capabilities::EXPORT,
			self::SLUG_EXPORT,
			array( 'MigrateKit\\Admin\\Menu', 'render_export' )
		);

		add_submenu_page(
			self::SLUG_HOME,
			'Import',
			'Import',
			Capabilities::IMPORT,
			self::SLUG_IMPORT,
			array( 'MigrateKit\\Admin\\Menu', 'render_import' )
		);

		add_submenu_page(
			self::SLUG_HOME,
			'Backups',
			$label,
			Capabilities::IMPORT,
			self::SLUG_BACKUPS,
			array( 'MigrateKit\\Admin\\Menu', 'render_backups' )
		);

		add_submenu_page(
			self::SLUG_HOME,
			'Cloud Storage',
			'Cloud Storage',
			Capabilities::EXPORT,
			self::SLUG_CLOUD,
			array( 'MigrateKit\\Admin\\Menu', 'render_cloud' )
		);

		add_submenu_page(
			self::SLUG_HOME,
			'Schedules',
			'Schedules',
			Capabilities::EXPORT,
			self::SLUG_SCHEDULES,
			array( 'MigrateKit\\Admin\\Menu', 'render_schedules' )
		);

		add_submenu_page(
			self::SLUG_HOME,
			'Reset',
			'Reset',
			Capabilities::RESET,
			self::SLUG_RESET,
			array( 'MigrateKit\\Admin\\Menu', 'render_reset' )
		);
	}

	public static function render_export(): void {
		self::render('export');
	}

	public static function render_home(): void {
		self::render('home');
	}

	public static function render_import(): void {
		self::render('import');
	}

	public static function render_backups(): void {
		self::render('backups');
	}

	public static function render_cloud(): void {
		self::render('cloud');
	}

	public static function render_schedules(): void {
		self::render('schedules');
	}

	public static function render_reset(): void {
		self::render('reset');
	}

	private static function render(string $page): void {
		echo '<div class="wrap mk-wrap"><div id="migratekit-app" data-page="' . esc_attr($page) . '">'
			. '<noscript>MigrateKit requires JavaScript.</noscript></div></div>';
	}
}
