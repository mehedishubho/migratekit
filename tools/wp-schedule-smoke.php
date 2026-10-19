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
