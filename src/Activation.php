<?php
/**
 * Activation and deactivation routines.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit;

use MigrateKit\Scheduler\Cron;
use MigrateKit\Support\Paths;

final class Activation {

	/**
	 * Create the backups/storage directories and their protection files.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Paths::ensure_storage();
		Paths::ensure_backups();

		if (! get_option('migratekit_secret_key')) {
			update_option('migratekit_secret_key', wp_generate_password(24, false, false));
		}

		Cron::sync();
	}

	/**
	 * Clear scheduled events and drop any run in flight, which nothing would continue.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_unschedule_hook(Cron::HOOK);
		wp_unschedule_hook(Cron::SYNC_HOOK);
		wp_clear_scheduled_hook('migratekit_storage_cleanup');
		Cron::abandon_runs();
	}
}
