<?php
/**
 * Streams the remote backup to disk, resuming across requests.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class PullTransferStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (! isset($state['session'])) {
			throw new \RuntimeException('The pull job lost its download session.');
		}

		$provider = CloudStageKit::provider($state);
		$remote   = isset($state['remote']) ? (string) $state['remote'] : '';
		$target   = isset($state['target']) ? (string) $state['target'] : '';

		if ($target === '') {
			$storage = isset($state['storage_dir']) ? (string) $state['storage_dir'] : '';

			if ($storage === '') {
				throw new \RuntimeException('The pull job is missing its destination details.');
			}

			$target         = rtrim($storage, '/\\') . DIRECTORY_SEPARATOR . 'pull.wpress';
			$state['target'] = $target;
		}

		$session =& $state['session'];
		$offset  = isset($state['offset']) ? (int) $state['offset'] : 0;
		$size    = (int) ( $state['size'] ?? 0 );

		if ($offset === 0 && is_file($target)) {
			@unlink($target);
		}

		$handle = fopen($target, $offset === 0 ? 'wb' : 'ab');

		if ($handle === false) {
			throw new \RuntimeException('The pull destination could not be opened for writing.');
		}

		$chunk    = $provider->chunk_size();
		$deadline = $ctx->deadline();
		$label    = $provider->label();

		try {
			while (true) {
				$part = $provider->download_chunk($session, $offset, $chunk);

				if ($part === '') {
					break;
				}

				if (fwrite($handle, $part) !== strlen($part)) {
					throw new \RuntimeException('Writing the pulled backup failed.');
				}

				$offset += strlen($part);

				$state['offset'] = $offset;

				if ($size > 0) {
					$percent = CloudStageKit::percent($offset, $size);

					$ctx->report(
						sprintf('Downloaded %s from %s (%d%%)', $remote, $label, $percent),
						$percent
					);
				} else {
					$ctx->report(sprintf('Downloaded %s from %s (%d bytes so far)', $remote, $label, $offset));
				}

				if ($deadline !== null && microtime(true) >= $deadline) {
					return false;
				}
			}
		} finally {
			fclose($handle);
		}

		$state['offset'] = $offset;

		return true;
	}
}
