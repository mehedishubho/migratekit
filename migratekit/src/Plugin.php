<?php
/**
 * Plugin container and service wiring.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit;

use MigrateKit\Admin\AssetLoader;
use MigrateKit\Admin\Menu;
use MigrateKit\Cli\Command;
use MigrateKit\Cli\CloudCommands;
use MigrateKit\Http\AdminPostController;
use MigrateKit\Http\AjaxController;
use MigrateKit\Http\RestController;
use MigrateKit\Scheduler\Cron as SchedulerCron;
use MigrateKit\Support\Capabilities;
use MigrateKit\Support\Maintenance;

final class Plugin {

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	private function __construct() {
	}

	public static function instance(): Plugin {
		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot the plugin. Admin, REST and CLI wiring is registered by later phases;
	 * the archive engine and pipelines are invoked from those entry points.
	 *
	 * @return void
	 */
	public function boot(): void {
		Capabilities::register();
		RestController::register();
		SchedulerCron::register();
		Maintenance::register();

		if (defined('WP_CLI') && WP_CLI) {
			Command::register();
			CloudCommands::register();
		}

		if (! is_admin()) {
			return;
		}

		Menu::register();
		AssetLoader::register();
		AjaxController::register();
		AdminPostController::register();
	}
}
