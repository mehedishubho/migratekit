<?php
/**
 * Prepares paths, options and the archive file before the export stages run.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class PrepareStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (empty($state['archive_path'])) {
			throw new \InvalidArgumentException('Export state is missing archive_path.');
		}

		$archive = (string) $state['archive_path'];
		$storage = isset($state['storage_dir']) && $state['storage_dir'] !== ''
			? (string) $state['storage_dir']
			: dirname($archive);

		if (! is_dir($storage) && ! mkdir($storage, 0755, true) && ! is_dir($storage)) {
			throw new \RuntimeException('Unable to create storage directory: ' . $storage);
		}

		if (! is_file($archive) && ! touch($archive)) {
			throw new \RuntimeException('Unable to create archive file: ' . $archive);
		}

		$state['archive_path']   = $archive;
		$state['storage_dir']    = $storage;
		$state['options']        = isset($state['options']) && is_array($state['options']) ? $state['options'] : array();
		$state['scans']          = isset($state['scans']) && is_array($state['scans']) ? $state['scans'] : array();
		$state['cursors']        = isset($state['cursors']) && is_array($state['cursors']) ? $state['cursors'] : array();
		$state['archive_offset'] = isset($state['archive_offset']) ? (int) $state['archive_offset'] : 0;

		$compression = isset($state['options']['compression']) ? (string) $state['options']['compression'] : '';
		$password    = isset($state['options']['password']) ? (string) $state['options']['password'] : '';

		$state['compression'] = $compression === '' ? null : $compression;
		$state['password']    = $password === '' ? null : $password;

		$state['paths'] = array_merge(
			array(
				'content_path' => (string) $ctx->site->get('content_path'),
				'uploads_path' => (string) $ctx->site->get('uploads_path'),
				'plugins_path' => (string) $ctx->site->get('plugins_path'),
				'themes_path'  => (string) $ctx->site->get('themes_path'),
			),
			isset($state['paths']) && is_array($state['paths']) ? $state['paths'] : array()
		);

		return true;
	}
}
