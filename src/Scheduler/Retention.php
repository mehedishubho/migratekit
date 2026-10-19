<?php
/**
 * Backup retention pruning.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Scheduler;

use MigrateKit\Storage\BackupsRepository;

final class Retention {

	private function __construct() {
	}

	/**
	 * Apply a schedule's retention rules to the backups directory.
	 *
	 * Rules are global: the newest N backups are kept and anything older than
	 * the day limit is removed.
	 *
	 * @param array $schedule Schedule definition.
	 * @return int Number of backups deleted.
	 */
	public static function apply(array $schedule): int {
		$keep_count = isset($schedule['retention_count']) ? (int) $schedule['retention_count'] : 0;
		$keep_days  = isset($schedule['retention_days']) ? (int) $schedule['retention_days'] : 0;

		if ($keep_count <= 0 && $keep_days <= 0) {
			return 0;
		}

		$repository = new BackupsRepository();
		$backups    = $repository->list();
		$cutoff     = $keep_days > 0 ? time() - ( $keep_days * DAY_IN_SECONDS ) : 0;
		$deleted    = 0;

		foreach ($backups as $index => $backup) {
			$too_many = $keep_count > 0 && $index >= $keep_count;
			$too_old  = $keep_days > 0 && $backup['mtime'] < $cutoff;

			if (! $too_many && ! $too_old) {
				continue;
			}

			if ($repository->delete($backup['name'])) {
				++$deleted;
			}
		}

		return $deleted;
	}
}
