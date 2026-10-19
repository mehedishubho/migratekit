<?php
/**
 * Per-job log file.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Job;

final class Logger {

	private function __construct() {
	}

	/**
	 * Append a line to the job log.
	 *
	 * @param array  $state   Job state (must contain storage_dir).
	 * @param string $message Message to log.
	 * @return void
	 */
	public static function append(array $state, string $message): void {
		$directory = isset($state['storage_dir']) ? (string) $state['storage_dir'] : '';

		if ($directory === '') {
			return;
		}

		$line = '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . "\n";

		@file_put_contents(
			rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'job.log',
			$line,
			FILE_APPEND | LOCK_EX
		);
	}

	/**
	 * Read a job's log.
	 *
	 * @param string $job_id Job identifier.
	 * @return string
	 */
	public static function read(string $job_id): string {
		$state     = Status::read($job_id);
		$directory = isset($state['storage_dir']) ? (string) $state['storage_dir'] : '';

		if ($directory === '') {
			return '';
		}

		$file = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'job.log';

		return is_file($file) ? (string) file_get_contents($file) : '';
	}
}
