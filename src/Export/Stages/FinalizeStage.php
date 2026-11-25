<?php
/**
 * Finalizes the archive: verifies its checksum over as many requests as it
 * takes, then appends the CRC32 end-of-archive sentinel.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export\Stages;

use MigrateKit\Archive\Crc32;
use MigrateKit\Archive\Header;
use MigrateKit\Archive\Writer;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;
use MigrateKit\Support\Bytes;

final class FinalizeStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		$limit = (int) ( $state['archive_offset'] ?? 0 );
		$done  = (int) ( $state['archive_bytes'] ?? 0 );

		if ($done < $limit) {
			$handle = fopen($state['archive_path'], 'rb');

			if ($handle === false) {
				throw new \RuntimeException('Unable to read the archive for its checksum.');
			}

			fseek($handle, $done);

			$part = Crc32::stream($handle, $limit - $done, $ctx->deadline());
			fclose($handle);

			if ($part['read'] === 0) {
				throw new \RuntimeException('The archive ended before the position recorded for this export. Start a new backup.');
			}

			$crc = (string) ( $state['archive_crc'] ?? '' );

			$state['archive_crc']   = Crc32::combine($crc !== '' ? $crc : null, $part['crc'], $part['read']);
			$state['archive_bytes'] = $done + $part['read'];

			if ($state['archive_bytes'] < $limit) {
				$ctx->report(
					sprintf('Verifying the archive (%s of %s)', Bytes::format((int) $state['archive_bytes']), Bytes::format($limit)),
					(int) min(99, floor((int) $state['archive_bytes'] * 100 / $limit))
				);

				return false;
			}
		}

		$writer = new Writer(
			$state['archive_path'],
			$state['compression'],
			$state['password'],
			false,
			$limit
		);

		$writer->append_eof((string) ( $state['archive_crc'] ?? '00000000' ));

		$state['archive_offset'] = $limit + Header::LENGTH;

		$writer->close(false);

		clearstatcache(true, $state['archive_path']);
		$state['archive_size'] = (int) filesize($state['archive_path']);

		$ctx->report('Archive finalized', 100);

		return true;
	}
}
