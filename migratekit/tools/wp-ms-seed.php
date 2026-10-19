<?php
/**
 * Seeds the source network with cross-linked sites, users, and media.
 *
 * Usage: wp eval-file /smoke/wp-ms-seed.php --url=http://wp-source
 *
 * Writes /shared/expected.json describing everything the target installs
 * must contain after import.
 *
 * @package MigrateKit
 */

$main_uploads = untrailingslashit((string) wp_upload_dir()['basedir']);

$shared = '/shared';
if (! is_dir($shared)) {
	mkdir($shared, 0777, true) || chown($shared, 33);
}

// ---------------------------------------------------------------------------
// Users.
$bod = get_user_by('login', 'bob');
if (! $bod) {
	switch_to_blog(2);
	$bod = wp_insert_user(array(
		'user_login' => 'bob',
		'user_pass'  => 'bob-pass-1',
		'user_email' => 'bob@src.test',
		'first_name' => 'Bob',
		'role'       => 'editor',
	));
	restore_current_blog();
}

$carol = get_user_by('login', 'carol');
if (! $carol) {
	switch_to_blog(3);
	$carol = wp_insert_user(array(
		'user_login' => 'carol',
		'user_pass'  => 'carol-pass-1',
		'user_email' => 'carol@src.test',
		'first_name' => 'Carol',
		'role'       => 'administrator',
	));
	restore_current_blog();
}

// ---------------------------------------------------------------------------
// Media fixtures.
$note_two = $main_uploads . '/sites/2/2026/10/ms-note.txt';

if (! is_dir(dirname($note_two))) {
	mkdir(dirname($note_two), 0775, true);
}

file_put_contents($note_two, "Subsite two note for the MigrateKit multisite smoke.\n");

$note_three = $main_uploads . '/sites/3/2026/10/ms-three.txt';

if (! is_dir(dirname($note_three))) {
	mkdir(dirname($note_three), 0775, true);
}

file_put_contents($note_three, "Subsite three note for the MigrateKit multisite smoke.\n");

// ---------------------------------------------------------------------------
// Posts.
$root_id = (int) wp_insert_post(array(
	'post_title'   => 'Root Post on the Source Network',
	'post_content' => 'Follow the other sites: http://wp-source/site2 and http://wp-source/site3.',
	'post_status'  => 'publish',
));

switch_to_blog(2);

$sub_two_id = (int) wp_insert_post(array(
	'post_title'   => 'Subsite Post Two',
	'post_content' => 'Cross link to the third site http://wp-source/site3/ and the file http://wp-source/wp-content/uploads/sites/2/2026/10/ms-note.txt',
	'post_status'  => 'publish',
));

restore_current_blog();

switch_to_blog(3);

$sub_three_id = (int) wp_insert_post(array(
	'post_title'   => 'Subsite Post Three',
	'post_content' => 'Home of site three at http://wp-source/site3/ with three.mp3 assets.',
	'post_status'  => 'publish',
));

restore_current_blog();

// ---------------------------------------------------------------------------
$expected = array(
	'root_post_id'    => $root_id,
	'sub_two_id'      => $sub_two_id,
	'sub_three_id'    => $sub_three_id,
	'titles'          => array(
		'Root Post on the Source Network',
		'Subsite Post Two',
		'Subsite Post Three',
	),
	'files'           => array(
		'sites/2/2026/10/ms-note.txt'  => 'Subsite two note for the MigrateKit multisite smoke.' . "\n",
		'sites/3/2026/10/ms-three.txt' => 'Subsite three note for the MigrateKit multisite smoke.' . "\n",
	),
	'users'           => array('bob' => 'editor', 'carol' => 'administrator'),
	'subsite_prefix'  => 'wp_2_',
);

file_put_contents($shared . '/expected.json', (string) wp_json_encode($expected));

echo "seeded\n";
