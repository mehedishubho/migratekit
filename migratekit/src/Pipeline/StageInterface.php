<?php
/**
 * One resumable step in an export or import pipeline.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Pipeline;

interface StageInterface {

	/**
	 * Run the stage.
	 *
	 * Returning false means the stage needs another request; the pipeline keeps
	 * its position so the next call resumes it.
	 *
	 * @param array           $state Pipeline state (by reference).
	 * @param PipelineContext $ctx   Runtime services.
	 * @return bool True when the stage is complete.
	 */
	public function run(array &$state, PipelineContext $ctx): bool;
}
