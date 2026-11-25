<?php
/**
 * Runs inside WordPress via `wp eval-file`: a scheduled backup too big for one
 * request has to be carried across cron requests to the end.
 *
 * Thousands of small files make the export outlast one request, and a short
 * execution limit gives each request a two-second budget. Nothing listens on
 * the site URL where this runs, so the continuation the run spawns is driven
 * by hand.
 *
 * @package MigrateKit
 */

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run this file through wp eval-file.\n");
	exit(1);
}

$GLOBALS['long_pass'] = 0;
$GLOBALS['long_fail'] = 0;

function long_ok(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['long_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['long_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function long_remove(string $dir): void {
	$entries = @scandir($dir);

	if (! is_array($entries)) {
		return;
	}

	foreach ($entries as $entry) {
		if ($entry !== '.' && $entry !== '..') {
			@unlink($dir . '/' . $entry);
		}
	}

	@rmdir($dir);
}

echo "MigrateKit — long scheduled backup smoke test\n\n";

$files = 60000;

// Publishing has to stay a rename: across two disks the last request would spend its whole budget copying the archive.
$backups_option = 'migratekit_backups_path';
$had_backups    = get_option($backups_option, null);
$backups        = rtrim((string) MIGRATEKIT_PATH, '/\\') . '/storage/long-smoke-backups';

update_option($backups_option, $backups, false);

$repository = new \MigrateKit\Storage\BackupsRepository();
$before     = count($repository->list());

// Compression is on: gzip has to work through the incompressible file below, which costs a steady amount of time on any machine.
$schedule = \MigrateKit\Scheduler\Schedule::save(
	array(
		'name'            => 'Long smoke',
		'enabled'         => true,
		'interval'        => 'daily',
		'compression'     => 'gzip',
		'retention_count' => 0,
		'retention_days'  => 0,
		'notify_email'    => '',
	)
);
$id   = (string) $schedule['id'];
$bulk = rtrim((string) wp_get_upload_dir()['basedir'], '/\\') . '/long-smoke';

wp_mkdir_p($bulk);

for ($i = 0; $i < $files; $i++) {
	file_put_contents($bulk . '/' . $i . '.txt', 'long smoke ' . $i);
}

$blob = fopen($bulk . '/incompressible.bin', 'wb');

for ( $i = 0; $i < 1000; $i++ ) {
	fwrite($blob, random_bytes(512000));
}

fclose($blob);

$tick = static function (string $method) use ($id): void {
	// A four-second limit leaves a request two seconds of work before it hands the run on.
	ini_set('max_execution_time', 4);

	try {
		\MigrateKit\Scheduler\Cron::$method($id);
	} finally {
		ini_set('max_execution_time', 0);
	}
};

echo "[1] The first request runs out of time\n";

$armed = static function () use ($id): bool {
	return wp_next_scheduled(\MigrateKit\Scheduler\Cron::RESUME_HOOK, array( $id )) !== false;
};

// The scheduler keeps the run of a schedule in this option (Cron::RUN_PREFIX).
$in_flight = static function () use ($id): bool {
	return \MigrateKit\Support\OptionStore::get( 'migratekit_run_' . $id ) !== null;
};

$tick('run');

long_ok('continuation armed', $armed());
long_ok('run in flight', $in_flight());
long_ok('no outcome recorded yet', (string) \MigrateKit\Scheduler\Schedule::get($id)['last_status'] === '');

// Only asked while a refusal is expected: a Run now that is allowed really starts one.
$refused = false;

try {
	\MigrateKit\Scheduler\Cron::start_inline($id);
} catch (\Throwable $e) {
	$refused = strpos($e->getMessage(), 'still running') !== false;
}

long_ok('Run now refused while the scheduled backup is in flight', $refused);
long_ok('nothing published yet', count($repository->list()) === $before);

echo "\n[2] Each continuation picks the run up\n";

$ticks = 1;

// Ten minutes of requests: slow disks get many more of them than fast ones.
while ($armed() && $ticks < 300) {
	$tick('resume');
	++$ticks;
}

$status = (string) \MigrateKit\Scheduler\Schedule::get($id)['last_status'];

printf( "  %d requests, last status: %s\n", $ticks, $status );

long_ok('run carried to the end', $ticks < 300 && strpos($status, 'Success') === 0);
long_ok('run needed several requests', $ticks > 1);
long_ok('continuation cleared when the run ended', ! $armed());
long_ok('no run left in flight', ! $in_flight());
long_ok('exactly one backup published', count($repository->list()) === $before + 1);

echo "\n[3] The archive a long run produces\n";

$published = strpos($status, 'Success: ') === 0 ? $repository->find(substr($status, strlen('Success: '))) : null;
$path      = $published !== null && isset($published['path']) ? (string) $published['path'] : '';

if ($path !== '' && is_file($path)) {
	$reader = new \MigrateKit\Archive\Reader($path, 'gzip');

	long_ok('archive is valid', $reader->is_valid());
	long_ok('archive CRC verifies', $reader->verify_archive_crc());

	$entries = $reader->entries();

	$archived = count(
		array_filter(
			$entries,
			static function ($entry): bool {
				return strpos($entry->relative_path(), 'uploads/long-smoke/') === 0;
			}
		)
	);

	printf( "  archive: %s, %.1f MB\n", basename($path), filesize($path) / 1048576 );
	long_ok('every file of the long site is in the archive', $archived === $files + 1);

	$blob_entry = $reader->find('uploads/long-smoke/incompressible.bin');

	if ($blob_entry !== null) {
		// Extracted to a file: the record is far too large to read into memory.
		$reader->extract($blob_entry, $backups . '/extracted.bin');
	}

	$round_trips = $blob_entry !== null && is_file($backups . '/extracted.bin')
		&& hash_file('sha256', $backups . '/extracted.bin') === hash_file('sha256', $bulk . '/incompressible.bin');

	@unlink($backups . '/extracted.bin');

	long_ok('the large file round-trips through compression', $round_trips);
} else {
	long_ok('published backup was found', false);
}

echo "\n[4] A leftover continuation cannot start a backup\n";

// WordPress-Cron drops the event itself before it fires the hook, so only the hook's own behaviour is checked here.
wp_schedule_single_event(time() - 60, \MigrateKit\Scheduler\Cron::RESUME_HOOK, array( $id ));

$tick('resume');

long_ok('stale continuation starts no run', ! $in_flight());
long_ok('stale continuation published nothing', count($repository->list()) === $before + 1);

// A run still in flight is abandoned the way any deleted schedule's run is, which removes its half-built archive.
\MigrateKit\Scheduler\Schedule::delete($id);

$tick('resume');

wp_clear_scheduled_hook(\MigrateKit\Scheduler\Cron::RESUME_HOOK, array( $id ));

foreach ( $repository->list() as $backup ) {
	@unlink( (string) $backup['path'] );
}

@rmdir($backups);
long_remove($bulk);

if ($had_backups === null) {
	delete_option($backups_option);
} else {
	update_option($backups_option, $had_backups, false);
}

echo "\n";
echo 'passed: ' . $GLOBALS['long_pass'] . ', failed: ' . $GLOBALS['long_fail'] . "\n";
exit($GLOBALS['long_fail'] === 0 ? 0 : 1);
