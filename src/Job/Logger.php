<?php
/**
 * Per-job log file.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Job;

final class Logger {

	/** Seconds between two lines that report the same kind of progress. */
	private const PROGRESS_INTERVAL = 1.0;

	/** @var array<string,array{0:string,1:float}> Last progress line written for a job: its kind, and when. */
	private static $progress = array();

	private function __construct() {
	}

	/**
	 * Append a line to the job log.
	 *
	 * A site with hundreds of thousands of files reports on every one of them,
	 * so progress of one kind is written at most once a second. The log keeps
	 * the trail; the live message is carried by the job state.
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

		$kind = implode(' ', array_slice(explode(' ', $message), 0, 2));
		$now  = microtime(true);
		$last = self::$progress[ $directory ] ?? null;

		if ($last !== null && $last[0] === $kind && $now - $last[1] < self::PROGRESS_INTERVAL) {
			return;
		}

		self::$progress[ $directory ] = array( $kind, $now );

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
