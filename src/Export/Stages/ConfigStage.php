<?php
/**
 * Writes package.json as the first archive record.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export\Stages;

use MigrateKit\Archive\Writer;
use MigrateKit\Export\ExportConfig;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class ConfigStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		$config = ExportConfig::build($ctx->site, $state['options']);
		$json   = json_encode($config);

		if ($json === false) {
			throw new \RuntimeException('Unable to encode the export manifest.');
		}

		// package.json always opens the archive: restart it from byte 0, dropping a copy a killed request may have left.
		$writer = new Writer(
			$state['archive_path'],
			$state['compression'],
			$state['password'],
			false,
			0
		);

		$writer->add_contents('package.json', $json, time());

		$state['archive_offset'] = $writer->tell();

		$writer->close(false);

		$state['config'] = $config;
		$ctx->report('Created export manifest');

		return true;
	}
}
