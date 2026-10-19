<?php
/**
 * Per-job progress persistence.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Job;

use MigrateKit\Support\OptionStore;

final class Status {

	public const OPTION_PREFIX = 'migratekit_status_';

	private function __construct() {
	}

	/**
	 * @param string $job_id Job identifier.
	 * @param array  $state  Job state.
	 * @return void
	 */
	public static function write(string $job_id, array $state): void {
		OptionStore::put(self::OPTION_PREFIX . $job_id, $state);
	}

	/**
	 * @param string $job_id Job identifier.
	 * @return array
	 */
	public static function read(string $job_id): array {
		$state = OptionStore::get(self::OPTION_PREFIX . $job_id, array());

		return is_array($state) ? $state : array();
	}

	public static function delete(string $job_id): void {
		OptionStore::drop(self::OPTION_PREFIX . $job_id);
	}
}
