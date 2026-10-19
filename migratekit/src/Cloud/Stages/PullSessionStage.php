<?php
/**
 * Opens (or resumes) the provider download session for a pull job.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class PullSessionStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (isset($state['session'])) {
			return true;
		}

		$provider = CloudStageKit::provider($state);
		$remote   = isset($state['remote']) ? (string) $state['remote'] : '';
		$storage  = isset($state['storage_dir']) ? (string) $state['storage_dir'] : '';

		if ($remote === '' || $storage === '') {
			throw new \RuntimeException('The pull job is missing its destination details.');
		}

		$ctx->report(sprintf('Fetching %s from %s…', $remote, $provider->label()), 0);

		$state['target']  = rtrim($storage, '/\\') . DIRECTORY_SEPARATOR . 'pull.wpress';
		$state['session'] = $provider->download_start($remote, (int) ( $state['size'] ?? 0 ));

		$size = (int) ( $state['session']['size'] ?? 0 );

		if ($size > 0) {
			$state['size'] = $size;
			$ctx->report(sprintf('Downloading %s (%d bytes)…', $remote, $size), 0);
		}

		return true;
	}
}
