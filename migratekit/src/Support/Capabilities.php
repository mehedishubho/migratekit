<?php
/**
 * Migration capabilities and permission checks.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Support;

final class Capabilities {

	public const EXPORT = 'migratekit_export';
	public const IMPORT = 'migratekit_import';
	public const RESET  = 'migratekit_reset';

	private function __construct() {
	}

	/**
	 * Register capability mapping.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter('map_meta_cap', array( 'MigrateKit\\Support\\Capabilities', 'map_meta_cap' ), 10, 4);
	}

	/**
	 * Map MigrateKit capabilities to administrator-level capabilities.
	 *
	 * @param string[] $caps    Required primitive capabilities.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User id.
	 * @param array    $args    Extra arguments.
	 * @return string[]
	 */
	public static function map_meta_cap($caps, $cap, $user_id, $args) {
		if ($cap !== self::EXPORT && $cap !== self::IMPORT && $cap !== self::RESET) {
			return $caps;
		}

		return is_multisite() ? array( 'manage_network' ) : array( 'manage_options' );
	}

	public static function can_export(): bool {
		return current_user_can(self::EXPORT);
	}

	public static function can_import(): bool {
		return current_user_can(self::IMPORT);
	}

	public static function can_reset(): bool {
		return current_user_can(self::RESET);
	}

	/**
	 * Network-level operations require a super admin on multisite.
	 *
	 * @return bool
	 */
	public static function can_run_on_network(): bool {
		return ! is_multisite() || is_super_admin();
	}
}
