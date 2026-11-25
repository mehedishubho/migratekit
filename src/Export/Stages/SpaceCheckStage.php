<?php
/**
 * Refuses the export up front when the server cannot hold the archive it would build.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export\Stages;

use MigrateKit\Database\Driver\DriverInterface;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;
use MigrateKit\Support\Bytes;

final class SpaceCheckStage implements StageInterface {

	/** Slack for archive headers and for sites whose size is still moving. */
	private const MARGIN_BYTES = 67108864;
	private const MARGIN_RATIO = 0.1;

	public function run(array &$state, PipelineContext $ctx): bool {
		if (! empty($state['space_checked'])) {
			return true;
		}

		$free = @disk_free_space($state['storage_dir']);

		if ($free === false) {
			$state['space_checked'] = true;
			return true;
		}

		$needed = 0;

		foreach (array( 'content', 'media', 'plugins', 'themes' ) as $key) {
			$needed += (int) ( $state['scans'][ $key ]['bytes'] ?? 0 );
		}

		// The dump is written loose and then packed into the archive, so it needs its size twice.
		if (empty($state['options']['no_database']) && $ctx->db !== null) {
			$needed += 2 * self::database_bytes($ctx->db);
		}

		$needed += max(self::MARGIN_BYTES, (int) ($needed * self::MARGIN_RATIO));

		if ((int) $free < $needed) {
			throw new \RuntimeException(sprintf(
				'Not enough disk space for this backup: about %s is needed but only %s is free on this server. Free up space or exclude some folders, then try again.',
				Bytes::format($needed),
				Bytes::format((int) $free)
			));
		}

		$state['space_needed']  = $needed;
		$state['space_checked'] = true;

		return true;
	}

	/**
	 * Upper estimate of the dump size: data plus index bytes of every table in the schema.
	 *
	 * @param DriverInterface $db Database driver.
	 * @return int Bytes, 0 when the server does not report it.
	 */
	private static function database_bytes(DriverInterface $db): int {
		$schema = $db->escape($db->database_name());
		$result = $db->query(
			"SELECT SUM(`DATA_LENGTH` + `INDEX_LENGTH`) AS `bytes` FROM `information_schema`.`TABLES`"
			. " WHERE `TABLE_SCHEMA` = '" . $schema . "' AND `TABLE_TYPE` = 'BASE TABLE'"
		);

		if ($result === false) {
			return 0;
		}

		$row = $db->fetch_assoc($result);

		$db->free_result($result);

		return isset($row['bytes']) ? max(0, (int) $row['bytes']) : 0;
	}
}
