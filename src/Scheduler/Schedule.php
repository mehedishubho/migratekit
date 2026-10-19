<?php
/**
 * Scheduled backup definitions.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Scheduler;

use MigrateKit\Http\Options;

final class Schedule {

	public const OPTION = 'migratekit_schedules';

	public const INTERVALS = array( 'hourly', 'twicedaily', 'daily', 'weekly', 'monthly' );

	private function __construct() {
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$raw = get_option(self::OPTION, array());

		if (! is_array($raw)) {
			return array();
		}

		$schedules = array();

		foreach ($raw as $key => $schedule) {
			if (! is_array($schedule)) {
				continue;
			}

			if (empty($schedule['id'])) {
				$schedule['id'] = (string) $key;
			}

			$schedules[] = self::normalize($schedule, false);
		}

		return $schedules;
	}

	/**
	 * @param string $id Schedule identifier.
	 * @return array<string,mixed>|null
	 */
	public static function get(string $id): ?array {
		foreach (self::all() as $schedule) {
			if ($schedule['id'] === $id) {
				return $schedule;
			}
		}

		return null;
	}

	/**
	 * Create or update a schedule.
	 *
	 * @param array $input Schedule data.
	 * @return array<string,mixed>
	 */
	public static function save(array $input): array {
		$schedule = self::normalize($input, true);
		$all      = self::all();
		$found    = false;

		foreach ($all as $index => $existing) {
			if ($existing['id'] === $schedule['id']) {
				$all[ $index ] = array_merge($existing, $schedule);
				$found         = true;
				break;
			}
		}

		if (! $found) {
			$all[] = $schedule;
		}

		self::store($all);
		Cron::sync();

		return $schedule;
	}

	/**
	 * @param string $id Schedule identifier.
	 * @return bool
	 */
	public static function delete(string $id): bool {
		$all   = self::all();
		$next  = array();
		$found = false;

		foreach ($all as $schedule) {
			if ($schedule['id'] === $id) {
				$found = true;
				continue;
			}

			$next[] = $schedule;
		}

		if ($found) {
			self::store($next);
			Cron::sync();
		}

		return $found;
	}

	/**
	 * Record the result of a run without rescheduling.
	 *
	 * @param string $id     Schedule identifier.
	 * @param int    $run_at Unix time of the run.
	 * @param string $status Result text.
	 * @return void
	 */
	public static function update_runtime(string $id, int $run_at, string $status): void {
		$all = self::all();

		foreach ($all as $index => $schedule) {
			if ($schedule['id'] === $id) {
				$all[ $index ]['last_run']    = $run_at;
				$all[ $index ]['last_status'] = $status;
			}
		}

		self::store($all);
	}

	/**
	 * @param string $interval Interval slug.
	 * @return int Seconds.
	 */
	public static function interval_seconds(string $interval): int {
		$map = array(
			'hourly'     => HOUR_IN_SECONDS,
			'twicedaily' => 12 * HOUR_IN_SECONDS,
			'daily'      => DAY_IN_SECONDS,
			'weekly'     => WEEK_IN_SECONDS,
			'monthly'    => MONTH_IN_SECONDS,
		);

		return isset($map[ $interval ]) ? $map[ $interval ] : DAY_IN_SECONDS;
	}

	/**
	 * @param array $input     Raw schedule.
	 * @param bool  $assign_id Whether to generate a missing id.
	 * @return array<string,mixed>
	 */
	private static function normalize(array $input, bool $assign_id): array {
		$id = isset($input['id']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string) $input['id'])) : '';

		if ($assign_id && ( $id === '' || $id === null )) {
			$id = 'sched_' . bin2hex(random_bytes(6));
		}

		$interval = isset($input['interval']) ? (string) $input['interval'] : 'daily';

		if (! in_array($interval, self::INTERVALS, true)) {
			$interval = 'daily';
		}

		$name = isset($input['name']) ? trim(wp_strip_all_tags((string) $input['name'])) : '';

		if ($name === '') {
			$name = 'Scheduled backup';
		}

		$options = isset($input['options']) && is_array($input['options'])
			? Options::export($input['options'])
			: Options::export($input);

		$email = isset($input['notify_email']) ? sanitize_email((string) $input['notify_email']) : '';

		if ($email !== '' && ! is_email($email)) {
			$email = '';
		}

		return array(
			'id'              => (string) $id,
			'name'            => substr($name, 0, 100),
			'enabled'         => ! empty($input['enabled']),
			'interval'        => $interval,
			'options'         => $options,
			'cloud_provider'  => isset($input['cloud_provider']) ? sanitize_key((string) $input['cloud_provider']) : '',
			'retention_count' => isset($input['retention_count']) ? max(0, (int) $input['retention_count']) : 0,
			'retention_days'  => isset($input['retention_days']) ? max(0, (int) $input['retention_days']) : 0,
			'notify_email'    => $email,
			'last_run'        => isset($input['last_run']) ? (int) $input['last_run'] : 0,
			'last_status'     => isset($input['last_status']) ? (string) $input['last_status'] : '',
			'created'         => isset($input['created']) ? (int) $input['created'] : time(),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $schedules Schedules.
	 * @return void
	 */
	private static function store(array $schedules): void {
		$map = array();

		foreach ($schedules as $schedule) {
			$map[ $schedule['id'] ] = $schedule;
		}

		update_option(self::OPTION, $map, false);
	}
}
