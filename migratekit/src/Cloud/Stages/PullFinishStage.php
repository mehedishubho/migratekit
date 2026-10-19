<?php
/**
 * Publishes the pulled archive into the local backups directory.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;
use MigrateKit\Support\Paths;

final class PullFinishStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		$provider = CloudStageKit::provider($state);
		$remote   = isset($state['remote']) ? (string) $state['remote'] : '';
		$target   = isset($state['target']) ? (string) $state['target'] : '';

		if (! is_file($target)) {
			throw new \RuntimeException('The pulled backup was not written.');
		}

		Paths::ensure_backups();

		$final = rtrim(Paths::backups(), '/\\') . DIRECTORY_SEPARATOR . $remote;

		if (realpath($target) !== realpath($final)) {
			if (! @rename($target, $final)) {
				if (! @copy($target, $final)) {
					throw new \RuntimeException('Unable to move the pulled backup into the backups directory.');
				}

				@unlink($target);
			}
		}

		$state['archive_path'] = $final;
		$state['published']    = true;

		$ctx->report(sprintf('Fetched %s from %s (%d bytes).', $remote, $provider->label(), (int) ( $state['offset'] ?? 0 )));

		return true;
	}
}
