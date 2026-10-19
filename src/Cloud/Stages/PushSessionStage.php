<?php
/**
 * Opens (or resumes) the provider upload session for a push job.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class PushSessionStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (isset($state['session'])) {
			return true;
		}

		$provider = CloudStageKit::provider($state);
		$remote   = isset($state['remote']) ? (string) $state['remote'] : '';
		$source   = isset($state['source']) ? (string) $state['source'] : '';

		if ($remote === '') {
			throw new \RuntimeException('The push job did not name a remote backup.');
		}

		if ($source === '' || ! is_file($source)) {
			throw new \RuntimeException('The local backup to push could not be found.');
		}

		$ctx->report(sprintf('Opening the upload session to %s…', $provider->label()));

		$state['session'] = $provider->upload_start($remote);

		if (! isset($state['size'])) {
			$state['size'] = (int) filesize($source);
		}

		$ctx->report(sprintf(
			'Pushing %s (%s) to %s…',
			$remote,
			self::size_label((int) $state['size']),
			$provider->label()
		), 0);

		return true;
	}

	/**
	 * @param int $bytes Byte count.
	 * @return string
	 */
	private static function size_label(int $bytes): string {
		$units = array( 'B', 'KB', 'MB', 'GB' );
		$value = (float) $bytes;
		$unit  = $units[0];

		foreach ($units as $candidate) {
			$unit = $candidate;

			if ($value < 1024) {
				break;
			}

			$value /= 1024;
		}

		return sprintf('%.1f %s', $value, $unit);
	}
}
