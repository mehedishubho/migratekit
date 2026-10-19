<?php
/**
 * Push pipeline: streams a local backup into a cloud provider.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud;

use MigrateKit\Cloud\Stages\PushFinishStage;
use MigrateKit\Cloud\Stages\PushSessionStage;
use MigrateKit\Cloud\Stages\PushTransferStage;
use MigrateKit\Pipeline\Pipeline;

final class PushPipeline {

	private function __construct() {
	}

	public static function create(): Pipeline {
		return new Pipeline(
			array(
				new PushSessionStage(),
				new PushTransferStage(),
				new PushFinishStage(),
			)
		);
	}
}
