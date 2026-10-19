<?php
/**
 * Runs inside WordPress via `wp eval-file` to smoke-test the plugin wiring.
 *
 * @package MigrateKit
 */

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run this file through wp eval-file.\n");
	exit(1);
}

$GLOBALS['smoke_pass'] = 0;
$GLOBALS['smoke_fail'] = 0;

function smoke_ok(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['smoke_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['smoke_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function smoke_same(string $label, $expected, $actual): void {
	smoke_ok($label, $expected === $actual);

	if ($expected !== $actual) {
		echo '        expected: ' . var_export($expected, true) . "\n";
		echo '        actual:   ' . var_export($actual, true) . "\n";
	}
}

echo "MigrateKit — WordPress smoke test\n";
echo 'site: ' . home_url() . "\n";
echo 'theme: ' . get_option('template') . "\n";
echo 'wp: ' . get_bloginfo('version') . "\n\n";

smoke_ok('plugin classes autoload', class_exists('MigrateKit\\Job\\Runner'));
smoke_ok('database driver available', class_exists('MigrateKit\\Database\\Driver\\MysqliDriver'));

echo "\n[1] Export through the job runner\n";
$state = \MigrateKit\Job\Runner::start_export(
	array(
		'compression' => '',
		'password'    => '',
	)
);

$job_id = $state['id'];
$guard  = 0;
$done   = false;

do {
	$state = \MigrateKit\Job\Runner::advance($job_id);
	++$guard;

	if (! empty($state['done'])) {
		$done = true;
		break;
	}
} while ($guard < 200);

smoke_ok('export completed', $done);
smoke_same('export percent', 100, (int) $state['percent']);
echo '  archive: ' . $state['archiveName'] . "\n";

echo "\n[2] Backup management\n";
$repository = new \MigrateKit\Storage\BackupsRepository();
$backups    = $repository->list();

smoke_ok('backup is listed', count($backups) >= 1);

$archive = count($backups) > 0 ? $backups[0]['path'] : '';
smoke_ok('backup file exists', $archive !== '' && is_file($archive));

echo "\n[3] Archive contents\n";

if ($archive !== '' && is_file($archive)) {
	$reader = new \MigrateKit\Archive\Reader($archive);
	smoke_ok('archive is valid', $reader->is_valid());
	smoke_ok('archive CRC verifies', $reader->verify_archive_crc());

	$entries = $reader->entries();
	$paths   = array_map(
		static function ($entry) {
			return $entry->relative_path();
		},
		$entries
	);

	smoke_ok('archive has entries', count($entries) > 0);
	smoke_same('first record is package.json', 'package.json', $paths[0]);
	smoke_ok('database.sql archived', in_array('database.sql', $paths, true));

	$database = $reader->find('database.sql');

	if ($database !== null) {
		$sql = $reader->read($database);
		smoke_ok('dump contains the options table', strpos($sql, 'wp_options') !== false || strpos($sql, 'SERVMASK_PREFIX_options') !== false);
		smoke_ok('dump uses the archive prefix', strpos($sql, 'SERVMASK_PREFIX_') !== false);
	}

	$self_archived = false;

	foreach ($paths as $path) {
		if (strpos($path, 'plugins/migratekit/') === 0) {
			$self_archived = true;
			break;
		}
	}

	smoke_ok('plugin does not archive itself', ! $self_archived);
}

echo "\n";
echo 'passed: ' . $GLOBALS['smoke_pass'] . ', failed: ' . $GLOBALS['smoke_fail'] . "\n";
exit($GLOBALS['smoke_fail'] === 0 ? 0 : 1);
