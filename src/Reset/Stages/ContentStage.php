<?php
/**
 * Empties the WordPress core content tables, one table per resumable step.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Reset\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class ContentStage implements StageInterface {

	/**
	 * Constraint-violation error codes signalling "a child table still holds
	 * FK references" on InnoDB. For those, fall back to plain DELETE.
	 *
	 * @var int[]
	 */
	private const FK_ERRORS = array( 1451, 1701 );

	public function run(array &$state, PipelineContext $ctx): bool {
		if ($ctx->db === null) {
			throw new \RuntimeException('The reset pipeline requires a database connection.');
		}

		$preset = isset($state['reset']) && is_array($state['reset']) ? $state['reset'] : null;

		if ($preset === null || ! isset($preset['tables'])) {
			throw new \RuntimeException('Reset state is missing the surveyed table list.');
		}

		$done = isset($state['content_done']) && is_array($state['content_done'])
			? $state['content_done']
			: array();

		foreach ((array) $preset['tables'] as $suffix => $table) {
			if (isset($done[ $suffix ])) {
				continue;
			}

			if (! $this->clear($suffix, $table, $state, $ctx)) {
				$state['content_done'] = $done;
				return false;
			}

			$done[ $suffix ] = true;
		}

		$state['content_done'] = $done;

		$ctx->report('Content tables emptied');

		return true;
	}

	/**
	 * TRUNCATE one content table, with a DELETE fallback for foreign keys.
	 *
	 * Emptying a big table can exceed the request budget, so a stage may
	 * report false and be resumed later with the same cursor (`content_done`).
	 *
	 * @param string          $suffix  Core suffix, e.g. "posts".
	 * @param string          $table   Qualified table name.
	 * @param array           $state   Pipeline state.
	 * @param PipelineContext $ctx     Runtime services.
	 * @return bool True when the table is emptied.
	 */
	private function clear(string $suffix, string $table, array &$state, PipelineContext $ctx): bool {
		$db = $ctx->db;

		$db->query('TRUNCATE TABLE `' . $table . '`');

		if ($db->last_errno() === 0) {
			$ctx->report('Emptied ' . $suffix);
			return true;
		}

		if (in_array($db->last_errno(), self::FK_ERRORS, true)) {
			$db->query('DELETE FROM `' . $table . '`');

			if ($db->last_errno() === 0) {
				$db->query('ALTER TABLE `' . $table . '` AUTO_INCREMENT = 1');
				$ctx->report('Emptied ' . $suffix . ' (delete fallback for foreign keys)');
				return true;
			}

			throw new \RuntimeException(
				'Could not clear ' . $table . ': ' . $db->last_error() . ' [' . $db->last_errno() . ']'
			);
		}

		throw new \RuntimeException(
			'Could not clear ' . $table . ': ' . $db->last_error() . ' [' . $db->last_errno() . ']'
		);
	}
}
