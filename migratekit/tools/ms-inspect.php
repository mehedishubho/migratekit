<?php
/**
 * Prints an archive manifest for smoke debugging.
 *
 * Usage: wp eval-file /smoke/ms-inspect.php <archive-path> [entry]
 *
 * @package MigrateKit
 */

$path = isset($args[0]) ? (string) $args[0] : '';
$entry = isset($args[1]) ? (string) $args[1] : '';

if ($path === '' || ! is_file($path)) {
	echo "archive not found: {$path}\n";
	exit(1);
}

$wp_dir     = ABSPATH;
$plugin_dir = $wp_dir . 'wp-content/plugins/migratekit';

if (! class_exists('MigrateKit\\Support\\Autoloader')) {
	require $plugin_dir . '/src/Support/Autoloader.php';
}

\MigrateKit\Support\Autoloader::register('MigrateKit\\', $plugin_dir . '/src');

$reader = new \MigrateKit\Archive\Reader($path);

if (! $reader->is_valid()) {
	echo "invalid archive\n";
	exit(1);
}

if ($entry !== '') {
	$found = $reader->find($entry);

	if ($found === null) {
		echo "entry not found: {$entry}\n";
		exit(1);
	}

	echo $reader->read($found);
	echo "\n";
	exit(0);
}

foreach ($reader->entries() as $record) {
	echo $record->relative_path() . ' ' . $record->size() . "\n";
}
