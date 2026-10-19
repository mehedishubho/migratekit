<?php
/**
 * MigrateKit uninstall routine.
 *
 * Runs only when the user deletes the plugin from the WordPress admin.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

// Drop scheduled events.
$crons = _get_cron_array();
if (is_array($crons)) {
	foreach ($crons as $timestamp => $hooks) {
		foreach ($hooks as $hook => $events) {
			if (strpos($hook, 'migratekit_') === 0) {
				unset($crons[$timestamp][$hook]);
			}
		}
	}
	_set_cron_array($crons);
}

// Remove plugin options and transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like('migratekit_') . '%',
		$wpdb->esc_like('_transient_migratekit_') . '%'
	)
);
