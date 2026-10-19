<?php
/**
 * Empties plugin and third-party tables that share the site prefix.
 *
 * Opt-in: `purge_third_party`. Tables outside the current prefix are never
 * touched, and core tables never reach this stage.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Reset\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class ThirdPartyStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		$db = $ctx->db;

		if ($db === null) {
			throw new \RuntimeException('The reset pipeline requires a database connection.');
		}

		$preset = isset($state['reset']) && is_array($state['reset']) ? $state['reset'] : null;

		if ($preset === null || ! array_key_exists('third_party', $preset)) {
			throw new \RuntimeException('Reset state is missing the third-party survey.');
		}

		$done = isset($state['third_party_done']) && is_array($state['third_party_done'])
			? $state['third_party_done']
			: array();

		foreach ((array) $preset['third_party'] as $table) {
			if (isset($done[ $table ])) {
				continue;
			}

			$db->query('TRUNCATE TABLE `' . $table . '`');
			$done[ $table ] = true;

			if ($db->last_errno() !== 0) {
				$db->query('DELETE FROM `' . $table . '`');

				if ($db->last_errno() !== 0) {
					throw new \RuntimeException(
						'Could not clear ' . $table . ': ' . $db->last_error() . ' [' . $db->last_errno() . ']'
					);
				}
			}

			$ctx->report('Emptied ' . $table);
		}

		$state['third_party_done'] = $done;

		$ctx->report('Third-party tables emptied');

		return true;
	}
}
