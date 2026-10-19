<?php
/**
 * Runs inside WordPress via `wp eval-file` to smoke-test the site reset.
 *
 * @package MigrateKit
 */

if (! defined('ABSPATH')) {
	fwrite(STDERR, "Run this file through wp eval-file.\n");
	exit(1);
}

$GLOBALS['reset_pass'] = 0;
$GLOBALS['reset_fail'] = 0;

function reset_ok(string $label, bool $condition): void {
	if ($condition) {
		$GLOBALS['reset_pass']++;
		echo "  ok  - {$label}\n";
	} else {
		$GLOBALS['reset_fail']++;
		echo "FAIL  - {$label}\n";
	}
}

function wp_rows(string $sql): int {
	global $wpdb;

	return (int) $wpdb->get_var($sql);
}

echo "MigrateKit — reset smoke test\n";
echo 'site: ' . home_url() . "\n\n";

echo "[1] Options::reset normalisation\n";
$normalized = \MigrateKit\Http\Options::reset(
	array(
		'delete_media_files' => '1',
		'delete_users'       => 'true',
		'purge_third_party'  => 'yes',
	)
);
reset_ok('flags normalise from request shapes', $normalized === array(
	'delete_media_files' => true,
	'delete_users'       => true,
	'purge_third_party'  => true,
));
$clean = \MigrateKit\Http\Options::reset(array());
reset_ok('defaults are conservative', $clean === array(
	'delete_media_files' => false,
	'delete_users'       => false,
	'purge_third_party'  => false,
));

echo "\n[2] Seed content\n";
wp_set_current_user(1);

$post_ids = array();
for ($i = 1; $i <= 3; $i++) {
	$created  = wp_insert_post(
		array(
			'post_title'  => 'Reset smoke ' . $i,
			'post_status' => 'publish',
			'post_type'   => 'post',
			'post_author' => 1,
		),
		true
	);

	if (is_wp_error($created)) {
		fwrite(STDERR, 'Seeding failed: ' . $created->get_error_message() . "\n");
		exit(1);
	}

	$post_ids[] = $created;
}

$tag = wp_insert_term('reset-smoke-tag', 'post_tag');
reset_ok('content seeded', count($post_ids) === 3 && ! is_wp_error($tag));

echo "\n[3] Reset through the job runner\n";
$started = \MigrateKit\Job\Runner::start_reset(
	\MigrateKit\Http\Options::reset(array())
);

reset_ok('reset job started', $started['type'] === 'reset' && $started['stages'] === 8);

$job_id = $started['id'];
$guard  = 0;
$done   = false;
$state  = $started;

do {
	$state = \MigrateKit\Job\Runner::advance($job_id);
	++$guard;

	if (! empty($state['done'])) {
		$done = true;
		break;
	}
} while ($guard < 200);

reset_ok('reset completed', $done);
reset_ok('reset percent', 100 === (int) $state['percent']);

echo "\n[4] Content gone, settings intact\n";
reset_ok('posts emptied', wp_rows("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type != 'revision'") === 0);
reset_ok('comments emptied', wp_rows("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->comments}") === 0);
reset_ok('terms emptied', wp_rows("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->terms}") === 0);
reset_ok('links emptied', wp_rows("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->links}") === 0);
reset_ok('postmeta emptied', wp_rows("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->postmeta}") === 0);

reset_ok('blogname survives', get_option('blogname') === get_bloginfo('name'));
reset_ok('template survives', '' !== (string) get_option('template'));
reset_ok('stylesheet survives', '' !== (string) get_option('stylesheet'));
reset_ok('siteurl survives', (bool) get_option('siteurl'));
reset_ok('active plugins survive', is_array(get_option('active_plugins', array())));

echo "\n[5] Fresh content restarts from ID 1\n";
$fresh = wp_insert_post(
	array(
		'post_title'  => 'After reset',
		'post_status' => 'publish',
		'post_type'   => 'post',
		'post_author' => 1,
	),
	true
);

if (is_wp_error($fresh)) {
	fwrite(STDERR, 'Post reset insert failed: ' . $fresh->get_error_message() . "\n");
	exit(1);
}

reset_ok('identifier rewound', (int) $fresh === 1);

echo "\n";
echo 'passed: ' . $GLOBALS['reset_pass'] . ', failed: ' . $GLOBALS['reset_fail'] . "\n";
exit($GLOBALS['reset_fail'] === 0 ? 0 : 1);
