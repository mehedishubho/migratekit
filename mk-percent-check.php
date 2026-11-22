<?php
declare(strict_types=1);
require __DIR__ . '/tests/bootstrap.php';

use MigrateKit\Job\Runner;

$state = Runner::start_export(array('compression' => '', 'password' => ''));
$seen = array();
$guard = 0;

while ($guard++ < 300) {
	$state = Runner::advance($state['id']);
	$seen[] = sprintf('%d:%s(%d%%)', $state['stage'], $state['message'], $state['percent']);
	if (!empty($state['done'])) break;
}

$pcts = array();
foreach ($seen as $s) { if (preg_match('/\((\d+)%\)$/', $s, $m)) $pcts[] = (int)$m[1]; }
echo 'steps: ' . count($seen) . "\n";
echo 'percent progression: ' . implode(',', array_slice($pcts, 0, 20)) . " ...\n";
$sorted = $pcts; sort($sorted);
echo 'monotonic: ' . ($sorted === $pcts ? 'yes' : 'NO') . "\n";
echo 'final: ' . end($pcts) . "%\n";
