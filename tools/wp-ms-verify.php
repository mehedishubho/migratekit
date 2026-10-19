<?php
/**
 * Verifies a MigrateKit multisite import outcome.
 *
 * Usage: wp eval-file /smoke/wp-ms-verify.php <mode> --url=<site>
 * Modes: network-target | plain
 *
 * @package MigrateKit
 */

global $wpdb;

$GLOBALS['ms_pass'] = 0;
$GLOBALS['ms_fail'] = 0;

function ms_v(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['ms_pass']++;
		echo "  ok  - {$label}\n";
		return;
	}

	$GLOBALS['ms_fail']++;
	echo "FAIL  - {$label}\n";
}

$mode     = isset($args[0]) ? (string) $args[0] : '';
$expected = json_decode((string) file_get_contents('/shared/expected.json'), true);

if (! is_array($expected)) {
	echo "FAIL  - expected.json unreadable\n";
	exit(1);
}

$uploads = untrailingslashit((string) wp_upload_dir()['basedir']);

// ---------------------------------------------------------------------------
// Whole-network import (target must mirror the source network).
if ($mode === 'network-target') {
	$network_sites = get_sites(array( 'number' => 20 ));

	ms_v('network holds every source site', count($network_sites) === 3);

	$root = get_post((int) $expected['root_post_id']);

	ms_v('root post restored', (bool) $root && $root->post_title === $expected['titles'][0]);
	ms_v('root post links moved to the target', strpos((string) $root->post_content, 'http://wp-target/site2') !== false);
	ms_v('source host no longer referenced on the root', strpos((string) $root->post_content, 'wp-source') === false);
	ms_v('root guid rewritten', strpos((string) $root->guid, 'http://wp-target') === 0);

	$uploads = untrailingslashit((string) wp_upload_dir()['basedir']);

	switch_to_blog(2);

	$site_two = get_post((int) $expected['sub_two_id']);

	ms_v('subsite two post restored', (bool) $site_two && $site_two->post_title === $expected['titles'][1]);

	if ($site_two) {
		ms_v('subsite two content references site three via target', strpos((string) $site_two->post_content, 'http://wp-target/site3/') !== false);
		ms_v('subsite two media link points at the target', strpos((string) $site_two->post_content, 'wp-content/uploads/sites/2/2026/10/ms-note.txt') !== false && strpos((string) $site_two->post_content, 'wp-source') === false);
	}

	$tables_two = (array) $wpdb->get_results('SHOW TABLES LIKE \'wp\\_2\\_%\'', ARRAY_N);
	$stale_two  = (int) $wpdb->get_var("SELECT COUNT(1) FROM wp_2_posts WHERE post_content LIKE '%wp-source%'");
	$bob     = get_user_by('login', 'bob');
	$bob_cap = (bool) $bob && user_can($bob->ID, 'edit_posts');
	$two_note = $uploads . '/sites/2/2026/10/ms-note.txt';

	restore_current_blog();

	switch_to_blog(3);

	$site_three   = get_post((int) $expected['sub_three_id']);
	$tables_three = (array) $wpdb->get_results('SHOW TABLES LIKE \'wp\\_3\\_%\'', ARRAY_N);
	$carol     = get_user_by('login', 'carol');
	$carol_cap = (bool) $carol && user_can($carol->ID, 'edit_posts');
	$three_note   = $uploads . '/sites/3/2026/10/ms-three.txt';

	restore_current_blog();

	ms_v('subsite two tables carry the restored content', $stale_two === 0);
	ms_v('user bob restored with a subsite role', $bob_cap);
	ms_v('subsite two media file restored', is_file($two_note) && (string) file_get_contents($two_note) === $expected['files']['sites/2/2026/10/ms-note.txt']);
	ms_v('subsite three post restored', (bool) $site_three && $site_three->post_title === $expected['titles'][2]);
	ms_v('subsite three restores its table set', count($tables_three) >= 10);
	ms_v('user carol restored with a subsite role', $carol_cap);
	ms_v('subsite three media file restored', is_file($three_note) && (string) file_get_contents($three_note) === $expected['files']['sites/3/2026/10/ms-three.txt']);
} elseif ($mode === 'plain') {
	// Subsite archive into a standalone site.
	$posts   = (array) get_posts(array( 'numberposts' => 20, 'post_type' => 'any' ));
	$by_name = array();

	foreach ($posts as $post) {
		$by_name[ (string) $post->post_title ] = $post;
	}

	ms_v('subsite content restored on the standalone site', isset($by_name[ $expected['titles'][1] ]));
	ms_v('subsite post keeps its cross references', isset($by_name[ $expected['titles'][1] ]) && strpos((string) $by_name[ $expected['titles'][1] ]->post_content, 'site3') !== false);
	ms_v('standalone site url taken from the destination', strpos((string) get_option('siteurl'), 'wp-source') === false && strpos((string) get_option('siteurl'), 'wp-plain') !== false);

	$tables = (array) $wpdb->get_results('SHOW TABLES', ARRAY_N);
	$numbered = 0;

	foreach ($tables as $row) {
		if (preg_match('/^\w+_\d+_/', (string) $row[0]) === 1) {
			$numbered++;
		}
	}

	ms_v('standalone import left no numbered tables behind', $numbered === 0);

	$note = $uploads . '/2026/10/ms-note.txt';

	ms_v('subsite media lands in the uploads root', is_file($note) && (string) file_get_contents($note) === $expected['files']['sites/2/2026/10/ms-note.txt']);

	ms_v('shared users come along to the standalone site', (bool) get_user_by('login', 'bob') && (bool) get_user_by('login', 'carol'));
} else {
	echo "FAIL  - unknown verification mode {$mode}\n";
	exit(1);
}

echo "\n";

printf(
	"passed: %d, failed: %d\n",
	(int) $GLOBALS['ms_pass'],
	(int) $GLOBALS['ms_fail']
);

exit($GLOBALS['ms_fail'] > 0 ? 1 : 0);
