<?php
/**
 * Packs storage/database.sql into the archive, a few chunks per request.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export\Stages;

use MigrateKit\Archive\Header;
use MigrateKit\Archive\Writer;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class DatabaseFileStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (! empty($state['options']['no_database']) || ! empty($state['database_added'])) {
			return true;
		}

		if (empty($state['database_file']) || ! is_file($state['database_file'])) {
			throw new \RuntimeException('The database dump went missing before it could be packed. Start a new backup.');
		}

		$cursor = isset($state['cursors']['database']) && is_array($state['cursors']['database'])
			? $state['cursors']['database']
			: array( 'offset' => 0, 'written' => 0, 'header' => null, 'crc' => null );

		// The dump is exactly as long as the dump stage recorded; anything past that is not part of the backup.
		$dumped = (int) ( $state['database_cursor']['bytes'] ?? -1 );

		if ($dumped >= 0) {
			$dump = fopen((string) $state['database_file'], 'r+b');

			if ($dump === false) {
				throw new \RuntimeException('Unable to read the database dump for packing.');
			}

			fseek($dump, 0, SEEK_END);

			if (ftell($dump) > $dumped) {
				ftruncate($dump, $dumped);
			}

			fclose($dump);
		}

		$writer = new Writer(
			$state['archive_path'],
			$state['compression'],
			$state['password'],
			false,
			(int) ( $state['archive_offset'] ?? 0 )
		);

		$completed = $writer->add_file_chunk(
			'database.sql',
			(string) $state['database_file'],
			$cursor['offset'],
			$cursor['written'],
			(int) filemtime((string) $state['database_file']),
			$cursor['header'],
			$ctx->deadline(),
			$cursor['crc']
		);

		$state['archive_offset']     = $cursor['header'] + Header::LENGTH + $cursor['written'];
		$state['cursors']['database'] = $cursor;

		$writer->close(false);

		if (! $completed) {
			return false;
		}

		$state['database_added'] = true;

		$ctx->report('Added the database to the archive');

		return true;
	}
}
