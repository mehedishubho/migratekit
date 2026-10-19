<?php
/**
 * Cron registration and scheduled backup execution.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Scheduler;

use MigrateKit\Job\Runner;
use MigrateKit\Support\OptionStore;
use MigrateKit\Support\Paths;

final class Cron {

	public const HOOK      = 'migratekit_run_schedule';
	public const SYNC_HOOK = 'migratekit_sync_schedules';

	/** Age (seconds) at which finished job storage is pruned. */
	public const STORAGE_TTL = 604800;

	/** Age (seconds) at which finished (done) job storage is pruned. */
	public const FINISHED_TTL = 86400;

	private function __construct() {
	}

	public static function register(): void {
		add_filter('cron_schedules', array( 'MigrateKit\\Scheduler\\Cron', 'schedules' ));
		add_action(self::HOOK, array( 'MigrateKit\\Scheduler\\Cron', 'run' ), 10, 1);
		add_action(self::SYNC_HOOK, array( 'MigrateKit\\Scheduler\\Cron', 'sync' ));
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules Existing intervals.
	 * @return array<string,array<string,mixed>>
	 */
	public static function schedules(array $schedules): array {
		$schedules['weekly']  = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => 'Once Weekly',
		);
		$schedules['monthly'] = array(
			'interval' => MONTH_IN_SECONDS,
			'display'  => 'Once Monthly',
		);

		return $schedules;
	}

	/**
	 * Rebuild the cron events for all enabled schedules.
	 *
	 * @return void
	 */
	public static function sync(): void {
		wp_unschedule_hook(self::HOOK);

		foreach (Schedule::all() as $schedule) {
			if (empty($schedule['enabled'])) {
				continue;
			}

			wp_schedule_event(
				time() + Schedule::interval_seconds($schedule['interval']),
				$schedule['interval'],
				self::HOOK,
				array( $schedule['id'] )
			);
		}

		if (! wp_next_scheduled(self::SYNC_HOOK)) {
			wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::SYNC_HOOK);
		}

		self::prune();
	}

	/**
	 * Remove finished job storage past its grace period and abandoned
	 * unfinished storage past the retention window.
	 *
	 * @param int|null $now Current time (injectable for tests).
	 * @return int Removed directories.
	 */
	public static function prune(?int $now = null): int {
		$now     = $now === null ? time() : $now;
		$storage = rtrim(Paths::storage(), '/\\');
		$removed = 0;

		if (! is_dir($storage)) {
			return 0;
		}

		$entries = @scandir($storage);

		if (! is_array($entries)) {
			return 0;
		}

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$dir = $storage . DIRECTORY_SEPARATOR . $entry;

			if (! is_dir($dir)) {
				continue;
			}

			$modified = @filemtime($dir);

			if ($modified === false) {
				continue;
			}

			$age = $now - $modified;

			if (empty(self::job_state($entry)['done'])) {
				if ($age < self::STORAGE_TTL) {
					continue;
				}
			} elseif ($age < self::FINISHED_TTL) {
				continue;
			}

			if (self::rrmdir($dir)) {
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * @param string $job_id Job identifier.
	 * @return array<string,mixed>
	 */
	private static function job_state(string $job_id): array {
		$state = OptionStore::get('migratekit_status_' . $job_id, array());

		return is_array($state) ? $state : array();
	}

	/**
	 * @param string $dir Directory to remove recursively.
	 * @return bool
	 */
	private static function rrmdir(string $dir): bool {
		$items = @scandir($dir);

		if (! is_array($items)) {
			return false;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$child = $dir . DIRECTORY_SEPARATOR . $item;

			if (is_dir($child) && ! is_link($child)) {
				self::rrmdir($child);
				continue;
			}

			@unlink($child);
		}

		return @rmdir($dir);
	}

	/**
	 * Run one scheduled backup.
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @return void
	 */
	public static function run($schedule_id): void {
		$schedule = Schedule::get((string) $schedule_id);

		if ($schedule === null || empty($schedule['enabled'])) {
			return;
		}

		$started = time();

		try {
			$state    = Runner::start_export($schedule['options']);
			$job_id   = $state['id'];
			$guard    = 0;
			$deadline = microtime(true) + 300;

			do {
				$state = Runner::advance($job_id);

				if (++$guard > 100000 || microtime(true) > $deadline) {
					break;
				}
			} while (empty($state['done']));

			if (empty($state['done'])) {
				throw new \RuntimeException('The scheduled backup did not finish.');
			}

			$deleted = Retention::apply($schedule);
			$status  = 'Success: ' . (string) $state['archiveName'];

			if ($deleted > 0) {
				$status .= sprintf(' (%d old backups removed)', $deleted);
			}

			$status .= self::push_to_cloud($schedule, (string) $state['archiveName']);

			Schedule::update_runtime($schedule['id'], $started, $status);
			Notifier::send($schedule, true, $status);
		} catch (\Throwable $e) {
			$status = 'Failed: ' . $e->getMessage();

			Schedule::update_runtime($schedule['id'], $started, $status);
			Notifier::send($schedule, false, $status);

			if (defined('WP_CLI') && WP_CLI) {
				\WP_CLI::warning($status);
			}
		}
	}

	/**
	 * Push the finished archive to the schedule's cloud provider, when set.
	 *
	 * @param array<string,mixed> $schedule    Schedule.
	 * @param string              $backup_name Published archive name.
	 * @return string Status suffix ('' when no cloud target).
	 */
	private static function push_to_cloud(array $schedule, string $backup_name): string {
		$cloud = isset($schedule['cloud_provider']) ? (string) $schedule['cloud_provider'] : '';

		if ($cloud === '' || $backup_name === '') {
			return '';
		}

		try {
			$push  = Runner::start_push($backup_name, $cloud);
			$guard = 0;

			do {
				$push = Runner::advance((string) $push['id']);

				if (++$guard > 100000) {
					break;
				}
			} while (empty($push['done']));

			if (empty($push['done'])) {
				throw new \RuntimeException('The cloud push did not finish.');
			}

			return sprintf(' (pushed to %s)', $cloud);
		} catch (\Throwable $e) {
			return sprintf(' (cloud push failed: %s)', $e->getMessage());
		}
	}
}
