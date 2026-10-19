<?php
/**
 * Removes users outside the administrator role.
 *
 * Opt-in: `delete_users`. The current user and every administrator survive.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Reset\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class UsersStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (empty($state['options']['delete_users'])) {
			$ctx->report('Users kept');

			return true;
		}

		$db = $ctx->db;

		if ($db === null) {
			throw new \RuntimeException('The reset pipeline requires a database connection.');
		}

		$prefix     = (string) $ctx->site->get('table_prefix');
		$usersTable = $prefix . 'users';
		$metaTable  = $prefix . 'usermeta';
		$capKey     = $prefix . 'capabilities';

		// User 1 boots fresh installs and multisite networks, so it always
		// survives. The active user may keep managing the site (e.g. a fresh
		// login after an import); every administrator listed in the
		// capabilities meta survives too.
		$keep = array( 1 => true );

		$currentUser = isset($state['user_id']) ? (int) $state['user_id'] : 0;

		if ($currentUser > 0) {
			$keep[ $currentUser ] = true;
		}

		$result = $db->query(
			'SELECT * FROM `' . $metaTable . '` '
			. "WHERE meta_key = '" . $db->escape($capKey) . "' "
			. "AND meta_value LIKE '%administrator%'"
		);

		if ($result !== false) {
			while (($row = $db->fetch_assoc($result)) !== null) {
				if (isset($row['user_id'])) {
					$keep[ (int) $row['user_id'] ] = true;
				}
			}

			$db->free_result($result);
		}

		$excluded = implode(',', array_map('strval', array_keys(array_map('intval', $keep))));

		$db->query('DELETE FROM `' . $metaTable . '` WHERE user_id NOT IN (' . $excluded . ')');

		if ($db->last_errno() === 0) {
			$db->query('DELETE FROM `' . $usersTable . '` WHERE ID NOT IN (' . $excluded . ')');
		}

		if ($db->last_errno() !== 0) {
			throw new \RuntimeException(
				'Could not remove non-administrator users: ' . $db->last_error() . ' [' . $db->last_errno() . ']'
			);
		}

		$ctx->report('Non-administrator users removed');

		return true;
	}
}
