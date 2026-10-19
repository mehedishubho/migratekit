<?php
/**
 * Surveys the site and records which content tables a reset will empty.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Reset\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;
use MigrateKit\Reset\ResetTables;

final class SurveyStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		$prefix = (string) $ctx->site->get('table_prefix');

		if ($prefix === '') {
			throw new \RuntimeException('The site context did not provide a table prefix.');
		}

		$db     = $ctx->db;
		$tables = $db !== null ? $db->list_tables() : array();

		if ($tables === array()) {
			throw new \RuntimeException('MigrateKit could not list the tables of this site.');
		}

		// Build the survey fully before publishing it to the state, so the
		// count helper never reads a half-written structure.
		$surveyed = ResetTables::present($prefix, $tables);
		$third    = ! empty($state['options']['purge_third_party'])
			? ResetTables::third_party($prefix, $tables)
			: array();

		$rows  = $this->counts($surveyed, $ctx);
		$total = 0;

		foreach ($rows as $count) {
			if ($count >= 0) {
				$total += $count;
			}
		}

		$state['reset'] = array(
			'prefix'      => $prefix,
			'tables'      => $surveyed,
			'third_party' => $third,
			'rows'        => $rows,
		);

		$ctx->report(
			sprintf(
				'Reset inventory: %d content tables, %d third-party tables, %d content rows.',
				count($surveyed),
				count($third),
				$total
			)
		);

		return true;
	}

	/**
	 * Row counts for every table about to be emptied. A table we cannot count
	 * is recorded as unknown (-1) and simply excluded from progress totals.
	 *
	 * @param array<string,string> $tables Present content tables, suffix => name.
	 * @param PipelineContext      $ctx    Runtime services.
	 * @return array<string,int>
	 */
	private function counts(array $tables, PipelineContext $ctx): array {
		$db = $ctx->db;

		if ($db === null) {
			return array();
		}

		$rows = array();

		foreach ($tables as $suffix => $table) {
			$result = $db->query('SELECT COUNT(*) AS n FROM `' . $table . '`');

			if ($result === false) {
				$rows[ $suffix ] = -1;
				continue;
			}

			$row = $db->fetch_assoc($result);
			$db->free_result($result);

			$rows[ $suffix ] = is_array($row) && isset($row['n']) ? (int) $row['n'] : -1;
		}

		return $rows;
	}
}
