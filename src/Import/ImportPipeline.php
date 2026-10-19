<?php
/**
 * Assembles the ordered import pipeline.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Import;

use MigrateKit\Import\Stages\ContentStage;
use MigrateKit\Import\Stages\DatabaseFileStage;
use MigrateKit\Import\Stages\DatabaseStage;
use MigrateKit\Import\Stages\DoneStage;
use MigrateKit\Import\Stages\EncryptionStage;
use MigrateKit\Import\Stages\PrepareStage;
use MigrateKit\Import\Stages\ValidateCrcStage;
use MigrateKit\Import\Stages\ValidateStage;
use MigrateKit\Pipeline\Pipeline;

final class ImportPipeline {

	private function __construct() {
	}

	public static function create(): Pipeline {
		return new Pipeline(
			array(
				new PrepareStage(),
				new ValidateStage(),
				new ValidateCrcStage(),
				new EncryptionStage(),
				new ContentStage(),
				new DatabaseFileStage(),
				new DatabaseStage(),
				new DoneStage(),
			)
		);
	}
}
