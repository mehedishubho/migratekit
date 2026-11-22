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

	/** Transient prefix binding a schedule to the job started inline. */
	public const INLINE_BINDING_PREFIX = 'migratekit_sched_job_';

	/** Lifetime of the binding (long enough for very large exports). */
	public const INLINE_TTL = 21600;

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
	 * Unix time of the next queued run for a schedule (0 when unarmed).
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @return int
	 */
	public static function next_run(string $schedule_id): int {
		$next = wp_next_scheduled(self::HOOK, array( $schedule_id ));

		return $next === false ? 0 : (int) $next;
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
			$archive = self::drive_export($schedule['options']);

			if ($archive === '') {
				throw new \RuntimeException('The scheduled backup did not finish.');
			}

			self::complete_run($schedule, $started, $archive);
		} catch (\Throwable $e) {
			self::fail_run($schedule, $started, $e->getMessage());
		}
	}

	/**
	 * Start a schedule's backup immediately and return the export job state
	 * for step-by-step driving from the browser. finish_inline() records
	 * retention, cloud push, runtime status and the notification once the
	 * job completes.
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @return array<string,mixed> Export job state.
	 */
	public static function start_inline(string $schedule_id): array {
		$schedule = Schedule::get($schedule_id);

		if ($schedule === null) {
			throw new \RuntimeException('Schedule not found.');
		}

		$binding = self::INLINE_BINDING_PREFIX . $schedule['id'];
		$bound   = get_transient($binding);
		$bound   = is_string($bound) ? $bound : '';

		if ($bound !== '' && self::job_unfinished($bound)) {
			throw new \RuntimeException('A backup for this schedule is already running. Reload the page to reconnect, or wait for it to finish.');
		}

		$state = Runner::start_export($schedule['options']);

		set_transient($binding, (string) $state['id'], self::INLINE_TTL);

		return $state;
	}

	/**
	 * Finalize a browser-driven schedule run: retention, cloud push, runtime
	 * status and notification. Only the job bound by start_inline() passes.
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @param string $job_id      Job identifier bound by start_inline().
	 * @return array<string,string> Result with the recorded status.
	 */
	public static function finish_inline(string $schedule_id, string $job_id): array {
		$schedule = Schedule::get($schedule_id);

		if ($schedule === null) {
			throw new \RuntimeException('Schedule not found.');
		}

		$binding  = self::INLINE_BINDING_PREFIX . $schedule['id'];
		$expected = get_transient($binding);
		$expected = is_string($expected) ? $expected : '';

		if ($expected === '' || $expected !== $job_id) {
			throw new \RuntimeException('This backup was not started by Run now, so it cannot be finalized as one.');
		}

		$state   = Runner::status($job_id);
		$started = time();

		if (empty($state['done'])) {
			$status = self::fail_run($schedule, $started, 'The backup did not finish.');
		} else {
			$archive = isset($state['archiveName']) ? (string) $state['archiveName'] : '';

			if ($archive === '') {
				throw new \RuntimeException('The finished backup did not report its archive name.');
			}

			$status = self::complete_run($schedule, $started, $archive);
		}

		delete_transient($binding);

		return array( 'status' => $status );
	}

	/**
	 * Start an export job and drive it to completion.
	 *
	 * @param array $options Export options.
	 * @return string Published archive name ('' when it did not finish).
	 */
	private static function drive_export(array $options): string {
		$state    = Runner::start_export($options);
		$job_id   = (string) $state['id'];
		$guard    = 0;
		$deadline = microtime(true) + 300;

		do {
			$state = Runner::advance($job_id);

			if (++$guard > 100000 || microtime(true) > $deadline) {
				break;
			}
		} while (empty($state['done']));

		if (empty($state['done'])) {
			return '';
		}

		return (string) $state['archiveName'];
	}

	/**
	 * Whether a bound job exists and is still incomplete.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool
	 */
	private static function job_unfinished(string $job_id): bool {
		try {
			$state = Runner::status($job_id);
		} catch (\Throwable $e) {
			return false;
		}

		return empty($state['done']);
	}

	/**
	 * Apply retention, cloud push, runtime status and notification after a
	 * successful run.
	 *
	 * @param array<string,mixed> $schedule Schedule.
	 * @param int                 $started  Run start timestamp.
	 * @param string              $archive  Published archive name.
	 * @return string Status text.
	 */
	private static function complete_run(array $schedule, int $started, string $archive): string {
		$deleted = Retention::apply($schedule);
		$status  = 'Success: ' . $archive;

		if ($deleted > 0) {
			$status .= sprintf(' (%d old backups removed)', $deleted);
		}

		$status .= self::push_to_cloud($schedule, $archive);

		Schedule::update_runtime((string) $schedule['id'], $started, $status);
		Notifier::send($schedule, true, $status);

		return $status;
	}

	/**
	 * Record and announce a failed run.
	 *
	 * @param array<string,mixed> $schedule Schedule.
	 * @param int                 $started  Run start timestamp.
	 * @param string              $message  Failure reason.
	 * @return string Status text.
	 */
	private static function fail_run(array $schedule, int $started, string $message): string {
		$status = 'Failed: ' . $message;

		Schedule::update_runtime((string) $schedule['id'], $started, $status);
		Notifier::send($schedule, false, $status);

		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::warning($status);
		}

		return $status;
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
			$push     = Runner::start_push($backup_name, $cloud);
			$guard    = 0;
			$deadline = microtime(true) + 300;

			do {
				$push = Runner::advance((string) $push['id']);

				if (++$guard > 100000 || microtime(true) > $deadline) {
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
