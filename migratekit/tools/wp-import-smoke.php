<?php
/**
 * Runs inside WordPress via `wp eval-file` to smoke-test a live import.
 *
 * @package MigrateKit
 */

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run this file through wp eval-file.\n");
	exit(1);
}

$GLOBALS['import_pass'] = 0;
$GLOBALS['import_fail'] = 0;

function import_ok(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['import_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['import_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

echo "MigrateKit — WordPress import smoke test\n";

$repository = new \MigrateKit\Storage\BackupsRepository();
$backups    = $repository->list();

if (count($backups) === 0) {
	echo "No backup available to import.\n";
	exit(1);
}

$source = $backups[0]['path'];
$copy   = rtrim(\MigrateKit\Support\Paths::storage(), '/\\') . '/restore-smoke-' . bin2hex(random_bytes(4)) . '.wpress';

if (! copy($source, $copy)) {
	echo "Unable to copy the backup for the import test.\n";
	exit(1);
}

$state = \MigrateKit\Job\Runner::start_import($copy, 'restore-smoke.wpress', array());
$job   = $state['id'];
$guard = 0;
$done  = false;

do {
	$state = \MigrateKit\Job\Runner::advance($job);
	++$guard;

	if (! empty($state['done'])) {
		$done = true;
		break;
	}
} while ($guard < 300);

import_ok('import completed', $done);
import_ok('import percent reaches 100', (int) $state['percent'] === 100);

wp_cache_flush();

import_ok('site URL intact', get_option('siteurl') === 'http://localhost:8099');
import_ok('home URL intact', get_option('home') === 'http://localhost:8099');
import_ok('admin user still exists', (bool) get_user_by('login', 'admin'));
import_ok('active theme intact', get_option('template') !== '');
import_ok('posts table readable', is_array(get_posts(array( 'numberposts' => 1 ))));
import_ok('plugin still active', in_array('migratekit/migratekit.php', (array) get_option('active_plugins'), true));

echo "\n";
echo 'passed: ' . $GLOBALS['import_pass'] . ', failed: ' . $GLOBALS['import_fail'] . "\n";
exit($GLOBALS['import_fail'] === 0 ? 0 : 1);
