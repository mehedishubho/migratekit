<?php
/**
 * Verifies the archive-wide CRC32 before anything is extracted.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Import\Stages;

use MigrateKit\Archive\Reader;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class ValidateCrcStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		$reader = new Reader((string) $state['archive_path']);

		if (! $reader->verify_archive_crc()) {
			throw new \RuntimeException('The archive failed its CRC32 integrity check and may be corrupted.');
		}

		$ctx->report('Integrity verified');

		return true;
	}
}
