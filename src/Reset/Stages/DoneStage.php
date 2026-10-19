<?php
/**
 * Marks the reset complete and summarises what was removed.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Reset\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class DoneStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		$survey  = isset($state['reset']) && is_array($state['reset']) && isset($state['reset']['rows'])
			? (array) $state['reset']['rows']
			: array();
		$unknown = 0;
		$total   = 0;

		foreach ($survey as $count) {
			$count = (int) $count;

			if ($count < 0) {
				++$unknown;
				continue;
			}

			$total += $count;
		}

		$media = ! empty($state['options']['delete_media_files']);
		$users = ! empty($state['options']['delete_users']);

		$summary = sprintf(
			'Site reset done. %d content rows removed.',
			$total
		);

		if ($unknown > 0) {
			$summary .= sprintf(' (row count unavailable for %d table(s))', $unknown);
		}

		if ($media) {
			$summary .= ' Media files removed.';
		}

		if ($users) {
			$summary .= ' Non-administrator users removed.';
		}

		$state['reset_summary'] = $summary;

		$ctx->report($summary, 100);

		return true;
	}
}
