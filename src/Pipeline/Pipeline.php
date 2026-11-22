<?php
/**
 * Sequential, resumable pipeline runner.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Pipeline;

final class Pipeline {

	/** @var StageInterface[] */
	private $stages;

	/**
	 * @param StageInterface[] $stages Ordered stages.
	 */
	public function __construct(array $stages) {
		$this->stages = array_values($stages);
	}

	/**
	 * Advance the pipeline until it completes or the time budget is exhausted.
	 *
	 * @param array           $state Pipeline state (by reference).
	 * @param PipelineContext $ctx   Runtime services.
	 * @return bool True when every stage has completed.
	 */
	public function advance(array &$state, PipelineContext $ctx): bool {
		$index = isset($state['stage']) ? (int) $state['stage'] : 0;
		$count = count($this->stages);

		while ($index < $count) {
			if ($ctx->time_exceeded()) {
				$state['stage'] = $index;
				return false;
			}

			// Stage position feeds the overall percent math in the status callback.
			$state['stage_total'] = $count;
			$state['stage_index'] = $index;

			$completed = $this->stages[ $index ]->run($state, $ctx);

			if (! $completed) {
				$state['stage'] = $index;
				return false;
			}

			++$index;
			$state['stage'] = $index;
		}

		$state['done'] = true;

		return true;
	}

	public function stage_count(): int {
		return count($this->stages);
	}
}
