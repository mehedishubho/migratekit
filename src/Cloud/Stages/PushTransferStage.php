<?php
/**
 * Streams the local backup into the provider, resuming across requests.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class PushTransferStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (! isset($state['session'])) {
			throw new \RuntimeException('The push job lost its upload session.');
		}

		$provider = CloudStageKit::provider($state);
		$source   = isset($state['source']) ? (string) $state['source'] : '';
		$remote   = isset($state['remote']) ? (string) $state['remote'] : '';

		if ($source === '' || ! is_file($source)) {
			throw new \RuntimeException('The local backup to push could not be found.');
		}

		$session =& $state['session'];
		$offset  = isset($state['offset']) ? (int) $state['offset'] : 0;
		$size    = isset($state['size']) ? (int) $state['size'] : 0;

		if ($size <= 0) {
			$size = (int) filesize($source);
			$state['size'] = $size;
		}

		$chunk    = $provider->chunk_size();
		$deadline = $ctx->deadline();
		$label    = $provider->label();

		while ($offset < $size) {
			$bytes = $provider->upload_chunk($session, $source, $offset, $chunk);

			if ($bytes === 0) {
				break;
			}

			$offset += $bytes;

			$state['offset'] = $offset;

			$ctx->report(
				sprintf('Pushed %s to %s (%d%%)', $remote, $label, CloudStageKit::percent($offset, $size)),
				CloudStageKit::percent($offset, $size)
			);

			if ($deadline !== null && microtime(true) >= $deadline && $offset < $size) {
				return false;
			}
		}

		$state['offset'] = $offset;

		return true;
	}
}
