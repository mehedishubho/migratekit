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
use MigrateKit\Support\Bytes;

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

		$handle = fopen($target, 'c');

		if ($handle === false) {
			throw new \RuntimeException('The pull destination could not be opened for writing.');
		}

		try {
			clearstatcache(true, $target);

			// The bytes already downloaded are gone: fill the gap with a fresh download rather than with the wrong ones.
			if ($offset > 0 && (int) @filesize($target) < $offset) {
				$offset            = 0;
				$state['offset']   = 0;
				$state['percent']  = 0;

				$ctx->report(sprintf('The partially downloaded %s is no longer on this server — downloading it again from the start.', $remote), 0);
			}

			// A request that was cut off after writing more than the job saved leaves the file longer than the offset resumes from.
			if (! ftruncate($handle, $offset) || fseek($handle, $offset) !== 0) {
				throw new \RuntimeException('The pull destination could not be prepared for writing.');
			}

			$chunk    = $provider->chunk_size();
			$deadline = $ctx->deadline();
			$label    = $provider->label();

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
					$ctx->report(sprintf('Downloaded %s from %s (%s so far)', $remote, $label, Bytes::format($offset)));
				}

				if ($deadline !== null && microtime(true) >= $deadline) {
					return false;
				}
			}
		} finally {
			fclose($handle);
		}

		// An archive that is shorter than the remote announced would fail its integrity check later, or restore a truncated site.
		if ($size > 0 && $offset < $size) {
			throw new \RuntimeException(
				sprintf(
					'The download of %s stopped early (%s of %s). Start it again.',
					$remote,
					Bytes::format($offset),
					Bytes::format($size)
				)
			);
		}

		$state['offset'] = $offset;

		return true;
	}
}
