<?php
/**
 * Pull pipeline: downloads a remote backup into the local backups directory.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud;

use MigrateKit\Cloud\Stages\PullFinishStage;
use MigrateKit\Cloud\Stages\PullSessionStage;
use MigrateKit\Cloud\Stages\PullTransferStage;
use MigrateKit\Pipeline\Pipeline;

final class PullPipeline {

	private function __construct() {
	}

	public static function create(): Pipeline {
		return new Pipeline(
			array(
				new PullSessionStage(),
				new PullTransferStage(),
				new PullFinishStage(),
			)
		);
	}
}
