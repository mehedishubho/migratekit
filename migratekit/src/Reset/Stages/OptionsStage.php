<?php
/**
 * Clears transients and forces a rewrite-rules regeneration after the reset.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Reset\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class OptionsStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		$db = $ctx->db;

		if ($db === null) {
			throw new \RuntimeException('The reset pipeline requires a database connection.');
		}

		// The options table is never emptied by a reset — only transient rows
		// and the cached rewrite rules go, so plugins and theme settings stay.
		$options = (string) $ctx->site->get('table_prefix') . 'options';

		// Single statement: delete every transient plus its timeout twin, the
		// site variants, and the cached rewrite rules so WordPress regenerates
		// them on the next request.
		$db->query(
			'DELETE FROM `' . $options . '` '
			. "WHERE option_name LIKE '\\_transient\\_%' "
			. "OR option_name LIKE '\\_transient\\_timeout\\_%' "
			. "OR option_name LIKE '\\_site\\_transient\\_%' "
			. "OR option_name LIKE '\\_site\\_transient\\_timeout\\_%' "
			. "OR option_name = 'rewrite_rules'"
		);

		if ($db->last_errno() !== 0) {
			throw new \RuntimeException(
				'Could not clear transients: ' . $db->last_error() . ' [' . $db->last_errno() . ']'
			);
		}

		$ctx->report('Transients and cached rewrite rules cleared');

		return true;
	}
}
