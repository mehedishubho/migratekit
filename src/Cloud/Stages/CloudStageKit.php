<?php
/**
 * Shared helpers for the cloud push/pull stages.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud\Stages;

use MigrateKit\Cloud\CloudProviderInterface;
use MigrateKit\Cloud\Manager;

final class CloudStageKit {

	private function __construct() {
	}

	/**
	 * Resolve and prepare the provider recorded in the job state.
	 *
	 * @param array $state Job state.
	 * @return CloudProviderInterface
	 */
	public static function provider(array $state): CloudProviderInterface {
		$id       = isset($state['cloud']) ? (string) $state['cloud'] : '';
		$provider = Manager::provider($id);

		if ($provider === null) {
			throw new \RuntimeException('Unknown cloud provider: ' . $id);
		}

		if (! $provider->is_ready()) {
			throw new \RuntimeException('The ' . $provider->label() . ' settings are incomplete.');
		}

		return $provider;
	}

	/**
	 * Progress percent when the remote size is known.
	 *
	 * @param int $bytes Bytes transferred or reached so far.
	 * @param int $size  Total size, 0 when unknown.
	 * @return int 0-99 (100 is reserved for the finished job).
	 */
	public static function percent(int $bytes, int $size): int {
		if ($size <= 0) {
			return 0;
		}

		return max(0, min(99, (int) floor($bytes / $size * 100)));
	}
}
