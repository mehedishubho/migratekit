<?php
/**
 * Extracts database.sql from the archive into storage.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Import\Stages;

use MigrateKit\Archive\Reader;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class DatabaseFileStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (! empty($state['database_file_done'])) {
			return true;
		}

		$reader = new Reader((string) $state['archive_path'], $state['compression'], $state['password']);
		$entry  = $reader->find('database.sql');

		if ($entry === null) {
			$state['database_file_done'] = true;
			$state['database_file']      = null;
			return true;
		}

		$destination = rtrim((string) $state['storage_dir'], '/\\') . DIRECTORY_SEPARATOR . 'database.sql';
		$reader->extract($entry, $destination);

		$state['database_file']      = $destination;
		$state['database_file_done'] = true;

		$ctx->report('Extracted the database dump');

		return true;
	}
}
