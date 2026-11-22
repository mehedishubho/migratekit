<?php
/**
 * Runs inside WordPress via `wp eval-file` to smoke-test schedules.
 *
 * @package MigrateKit
 */

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run this file through wp eval-file.\n");
	exit(1);
}

$GLOBALS['sched_pass'] = 0;
$GLOBALS['sched_fail'] = 0;

function sched_ok(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['sched_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['sched_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

echo "MigrateKit — scheduler smoke test\n";

$schedule = \MigrateKit\Scheduler\Schedule::save(
	array(
		'name'            => 'Smoke schedule',
		'enabled'         => true,
		'interval'        => 'daily',
		'compression'     => '',
		'retention_count' => 2,
		'retention_days'  => 0,
		'notify_email'    => '',
	)
);

sched_ok('schedule saved', ! empty($schedule['id']));
sched_ok('interval normalised', $schedule['interval'] === 'daily');
sched_ok('schedule is retrievable', \MigrateKit\Scheduler\Schedule::get($schedule['id']) !== null);

$next = wp_next_scheduled(\MigrateKit\Scheduler\Cron::HOOK, array( $schedule['id'] ));
sched_ok('cron event scheduled', (bool) $next);
sched_ok('sync event scheduled', (bool) wp_next_scheduled(\MigrateKit\Scheduler\Cron::SYNC_HOOK));

\MigrateKit\Scheduler\Cron::run($schedule['id']);

$reloaded = \MigrateKit\Scheduler\Schedule::get($schedule['id']);
sched_ok('run recorded', $reloaded !== null && strpos((string) $reloaded['last_status'], 'Success') === 0);
sched_ok('last_run timestamp set', $reloaded !== null && (int) $reloaded['last_run'] > 0);

$repository = new \MigrateKit\Storage\BackupsRepository();
sched_ok('scheduled backup created', count($repository->list()) >= 1);

// --- Cron::next_run (powering the Schedules page "Next run" column) ---
$next_run = \MigrateKit\Scheduler\Cron::next_run($schedule['id']);
sched_ok('next_run matches the queued cron event', $next_run === (int) $next);
sched_ok('next_run unarmed id resolves to 0', \MigrateKit\Scheduler\Cron::next_run('nope') === 0);

// --- Inline Run now: start_inline drives via export steps, finish_inline records ---
$finished_twice = false;

try {
	\MigrateKit\Scheduler\Cron::finish_inline($schedule['id'], 'not-a-job');
} catch (\Throwable $e) {
	$finished_twice = true;
}
sched_ok('finish rejects unbound job', $finished_twice);

$first = \MigrateKit\Scheduler\Cron::start_inline($schedule['id']);
sched_ok('inline start returns a live export job', (string) $first['type'] === 'export' && empty($first['done']));

$refused = false;

try {
	\MigrateKit\Scheduler\Cron::start_inline($schedule['id']);
} catch (\Throwable $e) {
	$refused = strpos((string) $e->getMessage(), 'already running') !== false;
}
sched_ok('double start refused while running', $refused);

$guard = 0;
do {
	$first = \MigrateKit\Job\Runner::advance((string) $first['id']);
	if (++$guard > 100000) {
		break;
	}
} while (empty($first['done']));
sched_ok('inline export finished', ! empty($first['done']));

$finished  = \MigrateKit\Scheduler\Cron::finish_inline($schedule['id'], (string) $first['id']);
$reloaded2 = \MigrateKit\Scheduler\Schedule::get($schedule['id']);
sched_ok('inline finish recorded success', isset($finished['status']) && strpos((string) $finished['status'], 'Success') === 0);
sched_ok('inline status stored on schedule', $reloaded2 !== null && (string) $reloaded2['last_status'] === (string) $finished['status']);
sched_ok('binding cleared after finish', get_transient(\MigrateKit\Scheduler\Cron::INLINE_BINDING_PREFIX . $schedule['id']) === false);

// --- Retention (count-based, standalone of any schedule) ---
$directory = \MigrateKit\Support\Paths::backups();

for ($i = 0; $i < 4; $i++) {
	$file = $directory . '/dummy-' . $i . '.wpress';
	file_put_contents($file, 'x');
	touch($file, time() - ( ( $i + 1 ) * DAY_IN_SECONDS ));
}

$deleted    = \MigrateKit\Scheduler\Retention::apply(
	array(
		'retention_count' => 2,
		'retention_days'  => 0,
	)
);
$after      = count(( new \MigrateKit\Storage\BackupsRepository() )->list());

sched_ok('retention removed backups', $deleted > 0);
sched_ok('retention kept the two newest', $after === 2);

sched_ok('schedule deleted', \MigrateKit\Scheduler\Schedule::delete($schedule['id']));
sched_ok('cron event removed', ! wp_next_scheduled(\MigrateKit\Scheduler\Cron::HOOK, array( $schedule['id'] )));

echo "\n";
echo 'passed: ' . $GLOBALS['sched_pass'] . ', failed: ' . $GLOBALS['sched_fail'] . "\n";
exit($GLOBALS['sched_fail'] === 0 ? 0 : 1);
