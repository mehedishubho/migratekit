<?php
/**
 * Validates the archive and reads its package.json manifest.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Import\Stages;

use MigrateKit\Archive\Reader;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class ValidateStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		$reader = new Reader((string) $state['archive_path']);

		if (! $reader->is_valid()) {
			throw new \RuntimeException('The selected file is not a valid .wpress archive.');
		}

		$entry = $reader->find('package.json');

		if ($entry === null) {
			throw new \RuntimeException('The archive is missing its package.json manifest.');
		}

		$package = json_decode($reader->read($entry), true);

		if (! is_array($package)) {
			throw new \RuntimeException('The archive manifest could not be decoded.');
		}

		$compression = isset($package['Compression']['Type']) ? (string) $package['Compression']['Type'] : '';

		if ($compression !== '' && ! in_array($compression, array( 'gzip', 'bzip2' ), true)) {
			throw new \RuntimeException('Unsupported archive compression: ' . $compression);
		}

		if (isset($package['Network']) && ! $ctx->site->get('is_multisite', false)) {
			throw new \RuntimeException(
				'This archive contains a whole-network export; it can only be imported into a multisite network.'
			);
		}

		if (! isset($package['Network']) && $ctx->site->get('is_multisite', false)) {
			$prefix = isset($package['Database']['Prefix']) ? (string) $package['Database']['Prefix'] : '';

			if (preg_match('/^(.+?)(\d+)_$/', $prefix) === 1) {
				throw new \RuntimeException(
					'This archive contains a single subsite export; restore it into a standalone WordPress site.'
				);
			}
		}

		$state['package']     = $package;
		$state['compression'] = $compression === '' ? null : $compression;

		$ctx->report('Archive validated');

		return true;
	}
}
