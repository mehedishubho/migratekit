<?php
/**
 * Jobs the admin pages started and may have to continue.
 *
 * A job advances only while an admin page sends step requests. When that page
 * is reloaded or closed the job waits on the server; this registry is how the
 * next page load finds it again. Only jobs started from the admin pages are
 * listed: cron, WP-CLI and REST callers drive their own.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Job;

use MigrateKit\Support\OptionStore;

final class ActiveJobs {

	public const OPTION = 'migratekit_active_jobs';

	private function __construct() {
	}

	/**
	 * @return string[] Job ids, oldest first.
	 */
	public static function ids(): array {
		$stored = OptionStore::get(self::OPTION, array());
		$ids    = array();

		if (! is_array($stored)) {
			return $ids;
		}

		foreach ($stored as $id) {
			if (is_string($id) && $id !== '' && ! in_array($id, $ids, true)) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param string $job_id Job identifier.
	 * @return bool
	 */
	public static function has(string $job_id): bool {
		return $job_id !== '' && in_array($job_id, self::ids(), true);
	}

	/**
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public static function add(string $job_id): void {
		$ids = self::ids();

		if ($job_id === '' || in_array($job_id, $ids, true)) {
			return;
		}

		OptionStore::put(self::OPTION, array_merge($ids, array( $job_id )));
	}

	/**
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public static function remove(string $job_id): void {
		$ids = self::ids();

		if (! in_array($job_id, $ids, true)) {
			return;
		}

		OptionStore::put(self::OPTION, array_values( array_diff( $ids, array( $job_id ) ) ));
	}

	/**
	 * Make the registry exactly these jobs.
	 *
	 * For after an import has replaced the options table underneath it: the
	 * stored registry is read past the cache, since what the cache holds is
	 * the registry of the site as it was before.
	 *
	 * @param string[] $job_ids Job identifiers to keep, oldest first.
	 * @return void
	 */
	public static function replace(array $job_ids): void {
		if (function_exists('wp_cache_delete')) {
			wp_cache_delete(self::OPTION, 'options');
		}

		$ids = array();

		foreach ($job_ids as $id) {
			if (is_string($id) && $id !== '' && ! in_array($id, $ids, true)) {
				$ids[] = $id;
			}
		}

		OptionStore::put(self::OPTION, $ids);
	}
}
