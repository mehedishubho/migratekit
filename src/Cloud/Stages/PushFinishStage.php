<?php
/**
 * Finalises the provider upload session for a push job.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class PushFinishStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (! isset($state['session'])) {
			throw new \RuntimeException('The push job lost its upload session.');
		}

		$provider = CloudStageKit::provider($state);
		$remote   = isset($state['remote']) ? (string) $state['remote'] : '';

		$session =& $state['session'];

		$provider->upload_finish($session);

		$ctx->report(sprintf('Pushed %s to %s (%d bytes).', $remote, $provider->label(), (int) ( $state['size'] ?? 0 )));

		return true;
	}
}
