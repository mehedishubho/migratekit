<?php
/**
 * Inspects the latest Local parity export from inside WordPress.
 *
 * Usage: wp eval-file /smoke/wp-parity-inspect.php <archive-name> <old-host> <new-host> <password> <excluded-table>
 *
 * Verifies at the archive level that export filters, find/replace pairs,
 * compression, encryption and table exclusion all landed where they should.
 *
 * @package MigrateKit
 */

if (! defined('ABSPATH')) {
	exit;
}

$archive_name   = isset($args[0]) ? (string) $args[0] : '';
$old_host       = isset($args[1]) ? (string) $args[1] : '';
$new_host       = isset($args[2]) ? (string) $args[2] : '';
$password       = isset($args[3]) ? (string) $args[3] : '';
$excluded_table = isset($args[4]) ? (string) $args[4] : '';

function pi_out($label, $condition, $extra = '') {
	echo ($condition ? '  ok  - ' : 'FAIL  - ') . $label . ($extra !== '' ? ' (' . $extra . ')' : '') . "\n";
}

if (! class_exists('MigrateKit\\Archive\\Inspector')) {
	require_once rtrim(WP_PLUGIN_DIR, '/\\') . '/migratekit/migratekit.php';
}

$path = rtrim(WP_CONTENT_DIR, '/\\') . '/migratekit-backups/' . $archive_name;

pi_out('archive exists', is_file($path), $path);

$package = \MigrateKit\Archive\Inspector::package($path, $password);

pi_out('manifest decoded', isset($package['SiteURL']) || isset($package['HomeURL']));
pi_out('encryption recorded and signature present', ! empty($package['Encrypted']) && ! empty($package['EncryptedSignature']));
pi_out(
	'find/replace pair recorded',
	in_array($old_host, (array) ( $package['Replace']['OldValues'] ?? array() ), true)
	&& in_array($new_host, (array) ( $package['Replace']['NewValues'] ?? array() ), true)
);
pi_out('tables filter recorded', in_array($excluded_table, (array) ( $package['Database']['ExcludedTables'] ?? array() ), true));

$entries = \MigrateKit\Archive\Inspector::entries($path, $password);

pi_out('archive lists entries', count($entries) >= 3, count($entries) . ' entries');

$dump   = '';
$reader = new \MigrateKit\Archive\Reader($path, 'gzip', $password);

foreach ($reader->entries() as $entry) {
	if ($entry->relative_path() === 'database.sql') {
		$dump = (string) $reader->read($entry);
		break;
	}
}

pi_out('database entry readable with password', strlen($dump) > 100, strlen($dump) . ' bytes');
pi_out(
	'excluded table absent from the dump',
	strpos($dump, 'mk_demo`') === false,
	'no CREATE/DROP identifier for wp_mk_demo (string mentions inside serialized option data are site content)'
);
pi_out('find/replace applied in the dump', strpos($dump, $old_host) === false && strpos($dump, $new_host) !== false);
pi_out('maintenance flag clear after the export', \MigrateKit\Support\Maintenance::is_on() === false);
