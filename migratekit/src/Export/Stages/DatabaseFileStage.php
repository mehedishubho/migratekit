<?php
/**
 * Packs storage/database.sql into the archive.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export\Stages;

use MigrateKit\Archive\Writer;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class DatabaseFileStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (! empty($state['options']['no_database'])) {
			return true;
		}

		if (empty($state['database_file']) || ! is_file($state['database_file'])) {
			return true;
		}

		$writer = new Writer(
			$state['archive_path'],
			$state['compression'],
			$state['password'],
			false
		);

		$writer->add_file('database.sql', $state['database_file'], (int) filemtime($state['database_file']));
		$writer->close(false);

		$ctx->report('Added the database to the archive');

		return true;
	}
}
