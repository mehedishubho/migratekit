<?php
/**
 * Backups repository tests.
 *
 * Usage: php tests/run-storage.php
 *
 * @package MigrateKit
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use MigrateKit\Job\JobId;
use MigrateKit\Storage\BackupsRepository;

$GLOBALS['st_pass'] = 0;
$GLOBALS['st_fail'] = 0;

function check_st(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['st_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['st_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function same_st(string $label, $expected, $actual): void {
	if ($expected === $actual) {
		$GLOBALS['st_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['st_fail']++;
		echo "FAIL  - {$label}\n";
		echo '        expected: ' . var_export($expected, true) . "\n";
		echo '        actual:   ' . var_export($actual, true) . "\n";
	}
}

$tmp = sys_get_temp_dir() . '/mk-storage-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

file_put_contents($tmp . '/first.wpress', 'one');
file_put_contents($tmp . '/second.wpress', 'two');
file_put_contents($tmp . '/notes.txt', 'ignore me');
file_put_contents($tmp . '/evil.php', '<?php');
touch($tmp . '/first.wpress', 1700000000);
touch($tmp . '/second.wpress', 1700001000);

echo "MigrateKit — storage suite\n\n";

echo "[1] Backups repository\n";
$repo = new BackupsRepository($tmp);
$list = $repo->list();

same_st('only .wpress files listed', 2, count($list));
same_st('newest first', 'second.wpress', $list[0]['name']);
same_st('size reported', 3, $list[0]['size']);
same_st('count matches', 2, $repo->count());

check_st('find existing backup', $repo->find('first.wpress') !== null);
check_st('find missing backup', $repo->find('missing.wpress') === null);
check_st('find rejects traversal', $repo->find('../first.wpress') === null);
check_st('find rejects wrong extension', $repo->find('notes.txt') === null);
check_st('destination builds safe path', $repo->destination('new.wpress') === $tmp . DIRECTORY_SEPARATOR . 'new.wpress');
check_st('destination rejects traversal', $repo->destination('../new.wpress') === null);

check_st('delete existing', $repo->delete('first.wpress'));
check_st('file removed', ! is_file($tmp . '/first.wpress'));
check_st('delete missing returns false', ! $repo->delete('first.wpress'));
same_st('count after delete', 1, $repo->count());

echo "\n[2] Name validation\n";
check_st('accepts normal name', BackupsRepository::validate_name('site-2026-01-01.wpress'));
check_st('accepts dots and underscores', BackupsRepository::validate_name('a_b.c-d.wpress'));
check_st('rejects path separator', ! BackupsRepository::validate_name('a/b.wpress'));
check_st('rejects non-wpress', ! BackupsRepository::validate_name('backup.zip'));
check_st('rejects empty', ! BackupsRepository::validate_name(''));
check_st('rejects overlong name', ! BackupsRepository::validate_name(str_repeat('a', 250) . '.wpress'));

echo "\n[3] Job identifiers\n";
$id = JobId::generate();
check_st('generated id is valid', JobId::is_valid($id));
same_st('generated id length', 16, strlen($id));
check_st('rejects short id', ! JobId::is_valid('abc'));
check_st('rejects non-hex id', ! JobId::is_valid('zzzzzzzzzzzzzzzz'));
check_st('accepts 13-40 hex', JobId::is_valid(str_repeat('a', 40)));

echo "\n";
echo "passed: {$GLOBALS['st_pass']}, failed: {$GLOBALS['st_fail']}\n";
exit($GLOBALS['st_fail'] === 0 ? 0 : 1);
