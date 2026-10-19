<?php
/**
 * Streams a manifest of files into the archive, resuming across requests.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export\Stages;

use MigrateKit\Archive\Writer;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class ArchiveFilesStage implements StageInterface {

	/** @var string */
	private $manifest_key;

	/** @var string */
	private $path_key;

	/** @var string */
	private $prefix;

	public function __construct(string $manifest_key, string $path_key, string $prefix) {
		$this->manifest_key = $manifest_key;
		$this->path_key     = $path_key;
		$this->prefix       = $prefix;
	}

	public function run(array &$state, PipelineContext $ctx): bool {
		$files = isset($state['manifests'][ $this->manifest_key ])
			? $state['manifests'][ $this->manifest_key ]
			: array();

		$total = count($files);

		$cursor = isset($state['cursors'][ $this->manifest_key ])
			? $state['cursors'][ $this->manifest_key ]
			: array(
				'index'   => 0,
				'offset'  => 0,
				'written' => 0,
				'header'  => null,
			);

		$root = rtrim((string) ($state['paths'][ $this->path_key ] ?? ''), '/\\');

		$writer = new Writer(
			$state['archive_path'],
			$state['compression'],
			$state['password'],
			false
		);

		$deadline = $ctx->deadline();

		while ($cursor['index'] < $total) {
			$absolute = $files[ $cursor['index'] ];
			$relative = ltrim(substr($absolute, strlen($root)), '/\\');
			$relative = str_replace('\\', '/', $relative);

			$archive_name = $this->prefix === '' ? $relative : $this->prefix . '/' . $relative;

			$header = $cursor['header'];

			$completed = $writer->add_file_chunk(
				$archive_name,
				$absolute,
				$cursor['offset'],
				$cursor['written'],
				null,
				$header,
				$deadline
			);

			$cursor['header'] = $header;

			if (! $completed) {
				$state['cursors'][ $this->manifest_key ] = $cursor;
				$writer->close(false);
				return false;
			}

			$cursor = array(
				'index'   => $cursor['index'] + 1,
				'offset'  => 0,
				'written' => 0,
				'header'  => null,
			);

			$state['cursors'][ $this->manifest_key ] = $cursor;

			$ctx->report(
				sprintf('Archiving %s (%d/%d)', $this->manifest_key, $cursor['index'], $total)
			);

			if ($deadline !== null && microtime(true) >= $deadline && $cursor['index'] < $total) {
				$writer->close(false);
				return false;
			}
		}

		$writer->close(false);

		return true;
	}
}
