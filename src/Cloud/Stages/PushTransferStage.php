<?php
/**
 * Streams the local backup into the provider, resuming across requests.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud\Stages;

use MigrateKit\Cloud\CloudProviderInterface;
use MigrateKit\Cloud\SessionLostException;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class PushTransferStage implements StageInterface {

	/** Upload sessions this stage may open again within one step. */
	private const RESTARTS_PER_STEP = 1;

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
			$size        = (int) filesize($source);
			$state['size'] = $size;
		}

		$chunk    = $provider->chunk_size();
		$deadline = $ctx->deadline();
		$label    = $provider->label();
		$restarts = 0;

		while ($offset < $size) {
			$previous = $offset;

			try {
				$bytes = $provider->upload_chunk($session, $source, $offset, $chunk);
			} catch (SessionLostException $e) {
				// The remote forgot the session, or its position can no longer be established. The local file is intact, so the upload starts over.
				if ($restarts >= self::RESTARTS_PER_STEP) {
					throw $e;
				}

				++$restarts;

				$ctx->report(sprintf('The upload to %s could not be continued — starting it again from the beginning…', $label), 0);

				self::discard($provider, $session);

				$session = $provider->upload_start($remote);
				$offset  = 0;

				$state['offset']  = 0;
				$state['percent'] = 0;

				continue;
			}

			$offset += $bytes;

			// A provider that keeps the remote's true position in the session may be ahead of the job: a request that was cut off before the job saved had already sent more.
			if (isset($session['offset'])) {
				$offset = max($offset, (int) $session['offset']);
			}

			// Nothing moved: the provider reports that the transfer must stop.
			if ($offset <= $previous) {
				break;
			}

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

	/**
	 * Free what the abandoned session already holds on the remote. Providers
	 * that drop unused sessions by themselves do nothing.
	 *
	 * @param CloudProviderInterface $provider Provider.
	 * @param array                  $session  Abandoned session.
	 * @return void
	 */
	private static function discard(CloudProviderInterface $provider, array $session): void {
		try {
			$provider->upload_abort($session);
		} catch (\Throwable $e) {
			// Only storage on the remote is at stake; the upload goes on.
		}
	}
}
