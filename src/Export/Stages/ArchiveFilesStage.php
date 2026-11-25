<?php
/**
 * Streams a manifest of files into the archive, resuming across requests.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export\Stages;

use MigrateKit\Archive\Header;
use MigrateKit\Archive\Writer;
use MigrateKit\Export\Manifest;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;
use MigrateKit\Support\Bytes;

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
		$scan = isset($state['scans'][ $this->manifest_key ]) && is_array($state['scans'][ $this->manifest_key ])
			? $state['scans'][ $this->manifest_key ]
			: array();

		$total_files = (int) ( $scan['files'] ?? 0 );
		$total_bytes = (int) ( $scan['bytes'] ?? 0 );

		if ($total_files === 0) {
			return true;
		}

		$cursor = isset($state['cursors'][ $this->manifest_key ]) && is_array($state['cursors'][ $this->manifest_key ])
			? $state['cursors'][ $this->manifest_key ]
			: array(
				'offset'        => 0,
				'path'          => null,
				'size'          => 0,
				'source_offset' => 0,
				'written'       => 0,
				'header'        => null,
				'crc'           => null,
				'count'         => 0,
				'bytes'         => 0,
			);

		$root = rtrim((string) ( $state['paths'][ $this->path_key ] ?? '' ), '/\\');
		$list = @fopen(Manifest::path((string) $state['storage_dir'], $this->manifest_key), 'rb');

		if ($list === false) {
			throw new \RuntimeException('The file list for ' . $this->manifest_key . ' is missing.');
		}

		$writer = new Writer(
			$state['archive_path'],
			$state['compression'],
			$state['password'],
			false,
			(int) ( $state['archive_offset'] ?? 0 )
		);

		$deadline = $ctx->deadline();
		$finished = false;

		while (true) {
			if ($cursor['path'] === null) {
				fseek($list, (int) $cursor['offset']);

				$entry = Manifest::read($list);

				if ($entry === null) {
					$finished = true;
					break;
				}

				$cursor['offset']        = (int) ftell($list);
				$cursor['path']          = $entry['path'];
				$cursor['size']          = (int) $entry['size'];
				$cursor['source_offset'] = 0;
				$cursor['written']       = 0;
				$cursor['header']        = null;
				$cursor['crc']           = null;
			}

			$absolute = $root . DIRECTORY_SEPARATOR . $cursor['path'];

			// Temp and cache files can vanish between listing and archiving on a busy site.
			if ($cursor['header'] === null && ! is_file($absolute)) {
				$ctx->report('Skipped ' . str_replace('\\', '/', $cursor['path']) . ': it no longer exists.');

				$this->complete($cursor, $total_files, $total_bytes, $ctx);
				continue;
			}

			$completed = $writer->add_file_chunk(
				str_replace('\\', '/', $this->prefix === '' ? $cursor['path'] : $this->prefix . '/' . $cursor['path']),
				$absolute,
				$cursor['source_offset'],
				$cursor['written'],
				null,
				$cursor['header'],
				$deadline,
				$cursor['crc']
			);

			$state['archive_offset'] = $cursor['header'] + Header::LENGTH + $cursor['written'];

			if (! $completed) {
				break;
			}

			$this->complete($cursor, $total_files, $total_bytes, $ctx);

			if ($deadline !== null && microtime(true) >= $deadline) {
				break;
			}
		}

		fclose($list);

		$writer->close(false);

		$state['cursors'][ $this->manifest_key ] = $cursor;

		return $finished;
	}

	/**
	 * Close out the current file, whether it was archived or skipped.
	 *
	 * @param array           $cursor      Archive cursor (by reference).
	 * @param int             $total_files Files listed for this manifest.
	 * @param int             $total_bytes Bytes listed for this manifest.
	 * @param PipelineContext $ctx         Pipeline services.
	 * @return void
	 */
	private function complete(array &$cursor, int $total_files, int $total_bytes, PipelineContext $ctx): void {
		++$cursor['count'];
		$cursor['bytes']         += (int) $cursor['size'];
		$cursor['path']           = null;
		$cursor['source_offset']  = 0;
		$cursor['written']        = 0;
		$cursor['header']         = null;
		$cursor['crc']            = null;

		$ctx->report(
			sprintf(
				'Archiving %s (%d/%d files, %s of %s)',
				$this->manifest_key,
				$cursor['count'],
				$total_files,
				Bytes::format((int) $cursor['bytes']),
				Bytes::format($total_bytes)
			),
			$total_bytes > 0 ? (int) min(100, floor((int) $cursor['bytes'] * 100 / $total_bytes)) : 100
		);
	}
}
