<?php
/**
 * Finalizes the archive: appends the CRC32 end-of-archive sentinel.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export\Stages;

use MigrateKit\Archive\Writer;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class FinalizeStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		$writer = new Writer(
			$state['archive_path'],
			$state['compression'],
			$state['password'],
			false
		);

		$writer->close(true);

		clearstatcache(true, $state['archive_path']);
		$state['archive_size'] = (int) filesize($state['archive_path']);

		$ctx->report('Archive finalized', 100);

		return true;
	}
}
