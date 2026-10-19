<?php
/**
 * Marks the import complete.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Import\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class DoneStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		$state['import_done'] = true;

		$ctx->report('Import complete', 100);

		return true;
	}
}
