<?php
/**
 * Prepares paths and options for an import.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Import\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class PrepareStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (empty($state['archive_path']) || ! is_file($state['archive_path'])) {
			throw new \RuntimeException('Import archive not found: ' . (string) ($state['archive_path'] ?? ''));
		}

		$storage = isset($state['storage_dir']) && $state['storage_dir'] !== ''
			? (string) $state['storage_dir']
			: dirname((string) $state['archive_path']);

		if (! is_dir($storage) && ! mkdir($storage, 0755, true) && ! is_dir($storage)) {
			throw new \RuntimeException('Unable to create import storage directory: ' . $storage);
		}

		$state['storage_dir'] = $storage;
		$state['options']     = isset($state['options']) && is_array($state['options']) ? $state['options'] : array();

		$password = isset($state['options']['password']) ? (string) $state['options']['password'] : '';

		$state['password'] = $password === '' ? null : $password;
		$state['compression'] = null;

		$state['paths'] = array_merge(
			array(
				'content_path' => (string) $ctx->site->get('content_path'),
				'plugins_path' => (string) $ctx->site->get('plugins_path'),
				'themes_path'  => (string) $ctx->site->get('themes_path'),
				'uploads_path' => (string) $ctx->site->get('uploads_path'),
			),
			isset($state['paths']) && is_array($state['paths']) ? $state['paths'] : array()
		);

		return true;
	}
}
