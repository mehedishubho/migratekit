<?php
/**
 * Assembles the ordered reset pipeline.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Reset;

use MigrateKit\Pipeline\Pipeline;
use MigrateKit\Reset\Stages\CacheStage;
use MigrateKit\Reset\Stages\ContentStage;
use MigrateKit\Reset\Stages\DoneStage;
use MigrateKit\Reset\Stages\MediaFilesStage;
use MigrateKit\Reset\Stages\OptionsStage;
use MigrateKit\Reset\Stages\SurveyStage;
use MigrateKit\Reset\Stages\ThirdPartyStage;
use MigrateKit\Reset\Stages\UsersStage;

final class ResetPipeline {

	private function __construct() {
	}

	public static function create(): Pipeline {
		return new Pipeline(
			array(
				new SurveyStage(),
				new ContentStage(),
				new ThirdPartyStage(),
				new OptionsStage(),
				new UsersStage(),
				new MediaFilesStage(),
				new CacheStage(),
				new DoneStage(),
			)
		);
	}
}
