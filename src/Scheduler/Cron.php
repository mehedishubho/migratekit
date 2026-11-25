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

	public const HOOK        = 'migratekit_run_schedule';
	public const RESUME_HOOK = 'migratekit_resume_schedule';
	public const SYNC_HOOK   = 'migratekit_sync_schedules';

	/** Age (seconds) at which finished job storage is pruned. */
	public const STORAGE_TTL = 604800;

	/** Age (seconds) at which finished (done) job storage is pruned. */
	public const FINISHED_TTL = 86400;

	/** Transient prefix binding a schedule to the job started inline. */
	public const INLINE_BINDING_PREFIX = 'migratekit_sched_job_';

	/** Lifetime of the binding (long enough for very large exports). */
	public const INLINE_TTL = 21600;

	/** Seconds of work one cron request does before handing a run to its continuation. */
	public const REQUEST_BUDGET = 20;

	/** Seconds until the event that picks an unfinished run back up. */
	public const RESUME_DELAY = 5;

	/** Lifetime of one run, export plus cloud copy, before it is declared unfinished. */
	public const RUN_TTL = 43200;

	/** Option prefix holding the run in flight for a schedule. */
	private const RUN_PREFIX = 'migratekit_run_';

	private function __construct() {
	}

	public static function register(): void {
		add_filter('cron_schedules', array( 'MigrateKit\Scheduler\Cron', 'schedules' ));
		add_action(self::HOOK, array( 'MigrateKit\Scheduler\Cron', 'run' ), 10, 1);
		add_action(self::RESUME_HOOK, array( 'MigrateKit\Scheduler\Cron', 'resume' ), 10, 1);
		add_action(self::SYNC_HOOK, array( 'MigrateKit\Scheduler\Cron', 'sync' ));
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

			$modified = self::last_touched($dir);

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
	 * When a job's folder last changed. The folder's own time ignores writes to
	 * the files it already holds, and a resumed backup mostly rewrites its archive.
	 *
	 * @param string $dir Job storage directory.
	 * @return int|false Unix time, or false when it cannot be read.
	 */
	private static function last_touched(string $dir) {
		$latest = @filemtime($dir);
		$names  = @scandir($dir);

		if (! is_array($names)) {
			return $latest;
		}

		foreach ($names as $name) {
			if ($name !== '.' && $name !== '..') {
				$latest = max((int) $latest, (int) @filemtime($dir . DIRECTORY_SEPARATOR . $name));
			}
		}

		return $latest;
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
	 * Run one scheduled backup: start it, or pick up the one already in flight.
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @return void
	 */
	public static function run($schedule_id): void {
		self::tick((string) $schedule_id, true);
	}

	/**
	 * Continue a scheduled backup that an earlier cron request left unfinished.
	 * Never starts one, so a stale continuation cannot trigger an extra backup.
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @return void
	 */
	public static function resume($schedule_id): void {
		self::tick((string) $schedule_id, false);
	}

	/**
	 * Move a schedule's run forward by one request.
	 *
	 * A run is spread over many short cron requests, so none has to outlive the
	 * host's time limits. The continuation is armed before any work runs and
	 * cleared when the run ends, so a request killed mid-run cannot strand it.
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @param bool   $may_start   Whether this request may begin a run, or only continue one.
	 * @return void
	 */
	private static function tick(string $schedule_id, bool $may_start): void {
		$schedule = Schedule::get($schedule_id);
		$pending  = self::run_record($schedule_id);

		if ($schedule === null || empty($schedule['enabled'])) {
			if ($pending !== null) {
				self::abandon($schedule_id, $pending);
			}

			return;
		}

		if ($pending !== null) {
			self::arm($schedule_id);
		} elseif (! $may_start) {
			return;
		}

		$lock = self::lock($schedule_id);

		// Another request is already driving this schedule.
		if ($lock === null) {
			return;
		}

		$run = null;

		try {
			// Read again under the lock: the request that held it may have finished the run.
			$run = self::run_record($schedule_id);

			if ($run === null) {
				$bound = get_transient(self::INLINE_BINDING_PREFIX . $schedule_id);
				$bound = is_string($bound) ? $bound : '';

				// A Run now in the browser is producing this schedule's backup already.
				if ($bound !== '' && self::job_unfinished($bound)) {
					return;
				}

				$run = self::start_run($schedule_id, $schedule);
			}

			self::arm($schedule_id);
			self::advance_run($schedule_id, $schedule, $run);
		} catch (\Throwable $e) {
			self::finish_failed($schedule_id, $schedule, $run, $e->getMessage());
		} finally {
			self::unlock($lock);
		}
	}

	/**
	 * Start an export job and open the run record that follows it.
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @param array  $schedule    Schedule.
	 * @return array<string,mixed> Run record.
	 */
	private static function start_run(string $schedule_id, array $schedule): array {
		$state = Runner::start_export($schedule['options']);

		$run = array(
			'job'     => (string) $state['id'],
			'phase'   => 'export',
			'started' => time(),
			'archive' => '',
			'note'    => '',
		);

		self::store_run($schedule_id, $run);

		return $run;
	}

	/**
	 * Work on a run until it finishes or the request's budget runs out. An
	 * unfinished run is left to the continuation armed by tick().
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @param array  $schedule    Schedule.
	 * @param array  $run         Run record.
	 * @return void
	 */
	private static function advance_run(string $schedule_id, array $schedule, array $run): void {
		$deadline = microtime(true) + self::request_budget();
		$guard    = 0;

		while (true) {
			if (time() - (int) $run['started'] > self::RUN_TTL) {
				if ($run['phase'] === 'push') {
					$run['note'] = ' (cloud push failed: it did not finish in time.)';
					break;
				}

				throw new \RuntimeException('The scheduled backup did not finish in time.');
			}

			if ($run['phase'] === 'push') {
				try {
					$state = Runner::advance($run['job']);
				} catch (\Throwable $e) {
					// The backup itself is done; a failed cloud copy is reported rather than counted as a failed backup.
					$run['note'] = sprintf(' (cloud push failed: %s)', $e->getMessage());
					break;
				}

				if (! empty($state['done'])) {
					$run['note'] = sprintf(' (pushed to %s)', (string) $schedule['cloud_provider']);
					break;
				}
			} else {
				$state = Runner::advance($run['job']);

				if (! empty($state['done'])) {
					$run['archive'] = (string) $state['archiveName'];
					$run['phase']   = 'push';

					self::begin_push($schedule, $run);
					self::store_run($schedule_id, $run);

					if ($run['job'] === '') {
						break;
					}

					continue;
				}
			}

			if (microtime(true) < $deadline && ++$guard <= 100000) {
				continue;
			}

			self::spawn($schedule_id);

			return;
		}

		self::unarm($schedule_id);
		self::drop_run($schedule_id);

		self::complete_run($schedule, (int) $run['started'], (string) $run['archive'], (string) $run['note']);
	}

	/**
	 * Open the cloud upload for a finished archive. One that cannot start is
	 * reported in the run's note, leaving no job to drive.
	 *
	 * @param array $schedule Schedule.
	 * @param array $run      Run record (by reference).
	 * @return void
	 */
	private static function begin_push(array $schedule, array &$run): void {
		$cloud = (string) ( $schedule['cloud_provider'] ?? '' );

		$run['job']  = '';
		$run['note'] = '';

		if ($cloud === '') {
			return;
		}

		try {
			$push = Runner::start_push($run['archive'], $cloud);
		} catch (\Throwable $e) {
			$run['note'] = sprintf(' (cloud push failed: %s)', $e->getMessage());

			return;
		}

		$run['job'] = (string) $push['id'];
	}

	/**
	 * Start a schedule's backup immediately and return the export job state
	 * for step-by-step driving from the browser. finish_inline() records
	 * retention, runtime status and the notification once the job completes.
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

		if (self::run_record($schedule_id) !== null) {
			throw new \RuntimeException('A scheduled backup for this schedule is still running in the background. Wait for it to finish.');
		}

		$state = Runner::start_export($schedule['options']);

		set_transient($binding, (string) $state['id'], self::INLINE_TTL);

		return $state;
	}

	/**
	 * Finalize a browser-driven schedule run: retention, runtime status and the
	 * notification. Only the job bound by start_inline() passes. A cloud copy
	 * is handed to cron, so the browser does not have to stay open for it.
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
			// Nothing can resume it once the binding is gone, and a half-built archive can be gigabytes.
			Runner::cancel($job_id);

			$status = self::fail_run($schedule, $started, 'The backup did not finish.');
		} else {
			$archive = isset($state['archiveName']) ? (string) $state['archiveName'] : '';

			if ($archive === '') {
				throw new \RuntimeException('The finished backup did not report its archive name.');
			}

			// While the cloud copy uploads there is nothing final to announce; the email follows when it ends.
			$copy   = self::queue_push($schedule_id, $schedule, $archive);
			$status = self::complete_run($schedule, $started, $archive, $copy['note'], ! $copy['queued']);
		}

		delete_transient($binding);

		return array( 'status' => $status );
	}

	/**
	 * Hand the cloud copy of a just-finished inline run to cron.
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @param array  $schedule    Schedule.
	 * @param string $archive     Published archive name.
	 * @return array{queued:bool,note:string} Whether an upload is in flight, and the status note to record now.
	 */
	private static function queue_push(string $schedule_id, array $schedule, string $archive): array {
		$cloud = (string) ( $schedule['cloud_provider'] ?? '' );

		if ($cloud === '') {
			return array( 'queued' => false, 'note' => '' );
		}

		$run = array(
			'job'     => '',
			'phase'   => 'push',
			'started' => time(),
			'archive' => $archive,
			'note'    => '',
		);

		self::begin_push($schedule, $run);

		if ($run['job'] === '') {
			return array( 'queued' => false, 'note' => $run['note'] );
		}

		self::store_run($schedule_id, $run);
		self::arm($schedule_id);
		self::spawn($schedule_id);

		return array( 'queued' => true, 'note' => ' (cloud copy is uploading in the background)' );
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
	 * Apply retention, record the outcome of a successful run and announce it.
	 *
	 * @param array  $schedule Schedule.
	 * @param int    $started  Run start timestamp.
	 * @param string $archive  Published archive name.
	 * @param string $note     Status suffix, e.g. the cloud copy's outcome.
	 * @param bool   $notify   Whether to send the notification now.
	 * @return string Status text.
	 */
	private static function complete_run(array $schedule, int $started, string $archive, string $note = '', bool $notify = true): string {
		$deleted = Retention::apply($schedule);
		$status  = 'Success: ' . $archive;

		if ($deleted > 0) {
			$status .= sprintf(' (%d old backups removed)', $deleted);
		}

		$status .= $note;

		Schedule::update_runtime((string) $schedule['id'], $started, $status);

		if ($notify) {
			Notifier::send($schedule, true, $status);
		}

		return $status;
	}

	/**
	 * Record and announce a failed run.
	 *
	 * @param array  $schedule Schedule.
	 * @param int    $started  Run start timestamp.
	 * @param string $message  Failure reason.
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
	 * End a run that threw. Its reason is already in the schedule history and
	 * the email, and a half-written archive can be gigabytes, so both go.
	 *
	 * @param string      $schedule_id Schedule identifier.
	 * @param array       $schedule    Schedule.
	 * @param array|null  $run         Run record, when one was opened.
	 * @param string      $message     Failure reason.
	 * @return void
	 */
	private static function finish_failed(string $schedule_id, array $schedule, ?array $run, string $message): void {
		self::unarm($schedule_id);
		self::drop_run($schedule_id);

		if ($run !== null) {
			Runner::cancel((string) $run['job']);
		}

		self::fail_run($schedule, $run !== null ? (int) $run['started'] : time(), $message);
	}

	/**
	 * Drop a run whose schedule was deleted or paused while it ran.
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @param array  $run         Run record.
	 * @return void
	 */
	private static function abandon(string $schedule_id, array $run): void {
		self::unarm($schedule_id);
		self::drop_run($schedule_id);

		Runner::cancel((string) $run['job']);
	}

	/**
	 * Drop every run in flight. Called on deactivation, when nothing would continue them.
	 *
	 * @return void
	 */
	public static function abandon_runs(): void {
		foreach (Schedule::all() as $schedule) {
			$run = self::run_record((string) $schedule['id']);

			if ($run !== null) {
				self::abandon((string) $schedule['id'], $run);
			}
		}

		wp_clear_scheduled_hook(self::RESUME_HOOK);
	}

	/**
	 * Queue the event that continues an unfinished run.
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @return void
	 */
	private static function arm(string $schedule_id): void {
		$args = array( $schedule_id );

		if (wp_next_scheduled(self::RESUME_HOOK, $args) === false) {
			wp_schedule_single_event(time() + self::RESUME_DELAY, self::RESUME_HOOK, $args);
		}
	}

	/**
	 * Remove the continuation of a run that no longer needs it.
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @return void
	 */
	private static function unarm(string $schedule_id): void {
		wp_clear_scheduled_hook(self::RESUME_HOOK, array( $schedule_id ));
	}

	/**
	 * Start the continuation now instead of on the next visitor. The event is
	 * brought forward so it counts as due, and WordPress's 60-second cron lock
	 * is released because this request is about to finish.
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @return void
	 */
	private static function spawn(string $schedule_id): void {
		if (! function_exists('spawn_cron')) {
			return;
		}

		$args = array( $schedule_id );
		$next = wp_next_scheduled(self::RESUME_HOOK, $args);

		if ($next !== false && $next > time()) {
			wp_clear_scheduled_hook(self::RESUME_HOOK, $args);
			wp_schedule_single_event(time(), self::RESUME_HOOK, $args);
		}

		delete_transient('doing_cron');
		spawn_cron();
	}

	/**
	 * @param string $schedule_id Schedule identifier.
	 * @return array<string,mixed>|null The run in flight, or null.
	 */
	private static function run_record(string $schedule_id): ?array {
		$run = OptionStore::get(self::RUN_PREFIX . $schedule_id);

		return is_array($run) && ! empty($run['job']) ? $run : null;
	}

	/**
	 * @param string $schedule_id Schedule identifier.
	 * @param array  $run         Run record.
	 * @return void
	 */
	private static function store_run(string $schedule_id, array $run): void {
		OptionStore::put(self::RUN_PREFIX . $schedule_id, $run);
	}

	/**
	 * @param string $schedule_id Schedule identifier.
	 * @return void
	 */
	private static function drop_run(string $schedule_id): void {
		OptionStore::drop(self::RUN_PREFIX . $schedule_id);
	}

	/**
	 * Seconds this request may work: the standard budget, or half of the host's
	 * execution limit when that is shorter.
	 *
	 * @return float
	 */
	private static function request_budget(): float {
		$limit = (int) @ini_get('max_execution_time');

		return $limit <= 0 ? (float) self::REQUEST_BUDGET : max(2.0, min((float) self::REQUEST_BUDGET, $limit * 0.5));
	}

	/**
	 * Take a schedule's exclusive lock, so two cron processes never drive one run.
	 *
	 * @param string $schedule_id Schedule identifier.
	 * @return resource|bool|null The lock to release, true when there is nothing to lock, or null while another request holds it.
	 */
	private static function lock(string $schedule_id) {
		$handle = @fopen(Paths::storage() . DIRECTORY_SEPARATOR . 'schedule-' . md5($schedule_id) . '.lock', 'c');

		if ($handle === false) {
			return true;
		}

		$would_block = 0;

		// Without $would_block the failure is not contention: locking is unsupported here, so carry on without it.
		if (@flock($handle, LOCK_EX | LOCK_NB, $would_block) || $would_block === 0) {
			return $handle;
		}

		fclose($handle);

		return null;
	}

	/**
	 * @param resource|bool|null $lock Lock returned by lock().
	 * @return void
	 */
	private static function unlock($lock): void {
		if (is_resource($lock)) {
			@flock($lock, LOCK_UN);
			fclose($lock);
		}
	}
}
