<?php
/**
 * WP-CLI commands.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cli;

use MigrateKit\Http\Options;
use MigrateKit\Archive\Inspector;
use MigrateKit\Job\Runner;
use MigrateKit\Storage\BackupsRepository;

final class Command {

	public static function register(): void {
		if (! defined('WP_CLI') || ! WP_CLI) {
			return;
		}

		\WP_CLI::add_command('migratekit', self::class);
	}

	/**
	 * Export the site to a .wpress backup.
	 *
	 * ## OPTIONS
	 *
	 * [--compression=<type>]
	 * : Compression type: gzip or bzip2.
	 *
	 * [--password=<password>]
	 * : Encrypt the archive with this password.
	 *
	 * [--network=<scope>]
	 * : Multisite scope: whole (entire network) or subsite (current site only).
	 *
	 * [--skip-media]
	 * : Skip the media library.
	 *
	 * [--skip-plugins]
	 * : Skip plugin files.
	 *
	 * [--skip-themes]
	 * : Skip theme files.
	 *
	 * [--skip-muplugins]
	 * : Skip must-use plugins.
	 *
	 * [--skip-cache]
	 * : Skip cache directories.
	 *
	 * [--skip-database]
	 * : Skip the database dump.
	 *
	 * [--skip-post-revisions]
	 * : Drop post revisions from the dump.
	 *
	 * [--skip-spam-comments]
	 * : Drop spam comments from the dump.
	 *
	 * [--skip-email-replace]
	 * : Skip email domain replacement metadata.
	 *
	 * [--skip-inactive-plugins]
	 * : Archive only files belonging to active plugins.
	 *
	 * [--skip-inactive-themes]
	 * : Archive only the active theme's files.
	 *
	 * [--replace-old=<old1,old2>]
	 * : Comma-separated find list for database replacement.
	 *
	 * [--replace-new=<new1,new2>]
	 * : Comma-separated replace list, paired with --replace-old.
	 *
	 * [--exclude-tables=<table1,table2>]
	 * : Full table names to omit from the dump.
	 *
	 * [--include-tables=<table1,table2>]
	 * : Restrict the dump to these full table names.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migratekit export --compression=gzip
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function export(array $args, array $assoc_args): void {
		$this->run_export($assoc_args, 'Backup created.');
	}

	/**
	 * Alias for `wp migratekit export`.
	 *
	 * ## OPTIONS
	 *
	 * [--compression=<type>]
	 * [--password=<password>]
	 * [--network=<scope>]
	 * [--skip-media|--skip-plugins|--skip-themes|--skip-muplugins|--skip-cache|--skip-database]
	 * [--skip-post-revisions|--skip-spam-comments|--skip-email-replace]
	 * [--skip-inactive-plugins|--skip-inactive-themes]
	 * [--replace-old=<old1,old2>] [--replace-new=<new1,new2>]
	 * [--exclude-tables=<table1,table2>] [--include-tables=<table1,table2>]
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function backup(array $args, array $assoc_args): void {
		$this->run_export($assoc_args, 'Backup created.');
	}

	/**
	 * Import a .wpress archive into this site.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the archive.
	 *
	 * [--password=<password>]
	 * : Decryption password for encrypted archives.
	 *
	 * [--no-email-replace]
	 * : Skip replacing email domains during import.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migratekit import /path/to/backup.wpress
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function import(array $args, array $assoc_args): void {
		$file = isset($args[0]) ? (string) $args[0] : '';

		if ($file === '' || ! is_file($file)) {
			\WP_CLI::error('Archive not found: ' . $file);
		}

		$options = Options::import(
			array(
				'password'         => isset($assoc_args['password']) ? $assoc_args['password'] : '',
				'no_email_replace' => ! empty($assoc_args['no-email-replace']),
			)
		);

		try {
			$state = Runner::start_import($file, basename($file), $options);
		} catch (\Throwable $e) {
			\WP_CLI::error($e->getMessage());
		}

		$this->drive($state['id']);
		\WP_CLI::success('Import complete.');
	}

	/**
	 * Restore a backup stored in the backups directory.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Backup file name.
	 *
	 * [--password=<password>]
	 * : Decryption password for encrypted archives.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migratekit restore example-20260101-120000-abcdef123456.wpress
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function restore(array $args, array $assoc_args): void {
		$name = isset($args[0]) ? (string) $args[0] : '';
		$repo = new BackupsRepository();

		if (! BackupsRepository::validate_name($name) || $repo->find($name) === null) {
			\WP_CLI::error('Backup not found: ' . $name);
		}

		$options = Options::import(
			array( 'password' => isset($assoc_args['password']) ? $assoc_args['password'] : '' )
		);

		try {
			$state = Runner::start_restore($name, $options);
		} catch (\Throwable $e) {
			\WP_CLI::error($e->getMessage());
		}

		$this->drive($state['id']);
		\WP_CLI::success('Restore complete.');
	}

	/**
	 * Reset the site: delete all content, keep theme and plugin settings.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the interactive confirmation prompt.
	 *
	 * [--skip-backup]
	 * : Do not create a database-only safety backup first.
	 *
	 * [--delete-media-files]
	 * : Also remove files from the uploads directory.
	 *
	 * [--delete-users]
	 * : Also remove users outside the administrator role. Skipped on multisite.
	 *
	 * [--purge-third-party]
	 * : Also empty plugin and third-party tables that share the table prefix.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migratekit reset --yes --delete-media-files
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function reset(array $args, array $assoc_args): void {
		$delete_users = ! empty($assoc_args['delete-users']);

		if ($delete_users && is_multisite()) {
			\WP_CLI::log('Multisite user accounts are shared across the network; the user purge is skipped.');
			$delete_users = false;
		}

		$delete_media = ! empty($assoc_args['delete-media-files']);

		\WP_CLI::confirm('This deletes ALL posts, pages, comments and terms on this site. Continue?', $assoc_args);

		if (! isset($assoc_args['skip-backup'])) {
			\WP_CLI::log('Creating the safety backup (database only)…');

			$db_backup = Options::export(array(
				'no_media'   => ! $delete_media,
				'no_plugins' => true,
				'no_themes'  => true,
			));

			try {
				$state = Runner::start_export($db_backup);
			} catch (\Throwable $e) {
				\WP_CLI::error($e->getMessage());
			}

			$db_state = $this->drive($state['id']);
			\WP_CLI::log('Safety backup: ' . (string) $db_state['archiveName']);
		}

		try {
			$state = Runner::start_reset(Options::reset(array(
				'delete_media_files' => $delete_media,
				'delete_users'       => $delete_users,
				'purge_third_party'  => ! empty($assoc_args['purge-third-party']),
			)));
		} catch (\Throwable $e) {
			\WP_CLI::error($e->getMessage());
		}

		$this->drive($state['id']);
		\WP_CLI::success('Site reset complete.');
	}

	/**
	 * List backups stored on this server.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migratekit backups
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function backups(array $args, array $assoc_args): void {
		$repository = new BackupsRepository();
		$rows       = array();

		foreach ($repository->list() as $backup) {
			$rows[] = array(
				'name'    => $backup['name'],
				'label'   => BackupsRepository::label_for($backup['name']),
				'size'    => size_format($backup['size']),
				'created' => gmdate('Y-m-d H:i:s', $backup['mtime']),
			);
		}

		if ($rows === array()) {
			\WP_CLI::log('No backups found.');
			return;
		}

		\WP_CLI\Utils\format_items('table', $rows, array( 'name', 'label', 'size', 'created' ));
	}

	/**
	 * Set or clear an archive label.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Backup file name.
	 *
	 * [<label>]
	 * : New label; omit to clear it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migratekit label example.wpress "Sunday snapshot"
	 *     wp migratekit label example.wpress
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function label(array $args, array $assoc_args): void {
		$name  = isset($args[0]) ? basename((string) $args[0]) : '';
		$value = isset($args[1]) ? (string) $args[1] : '';

		if (! BackupsRepository::validate_name($name)) {
			\WP_CLI::error('Invalid backup name.');
		}

		if (! BackupsRepository::set_label($name, $value)) {
			\WP_CLI::error('Backup not found: ' . $name);
		}

		if ($value === '') {
			\WP_CLI::success('Label cleared.');
		} else {
			\WP_CLI::success('Label set: ' . $value);
		}
	}

	/**
	 * List the files inside an archive.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Backup file name.
	 *
	 * [--password=<password>]
	 * : Decryption password for encrypted archives.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migratekit files example.wpress
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function files(array $args, array $assoc_args): void {
		$name = isset($args[0]) ? basename((string) $args[0]) : '';

		if (! BackupsRepository::validate_name($name)) {
			\WP_CLI::error('Invalid backup name.');
		}

		$password = isset($assoc_args['password']) ? (string) $assoc_args['password'] : '';
		$path     = ( new BackupsRepository() )->find($name);

		if ($path === null) {
			\WP_CLI::error('Backup not found: ' . $name);
		}

		try {
			$package = Inspector::package($path['path'], $password);

			Inspector::verify_password($package, $password);

			$rows = array();

			foreach (Inspector::entries($path['path'], $password) as $entry) {
				$rows[] = array(
					'path' => $entry['path'],
					'size' => size_format($entry['size']),
				);
			}

			if ($rows === array()) {
				\WP_CLI::log('The archive is empty.');
				return;
			}

			\WP_CLI\Utils\format_items('table', $rows, array( 'path', 'size' ));
		} catch (\Throwable $e) {
			\WP_CLI::error($e->getMessage());
		}
	}

	/**
	 * @param array  $assoc_args Associative arguments.
	 * @param string $success    Success message.
	 * @return void
	 */
	private function run_export(array $assoc_args, string $success): void {
		$source = array(
			'compression' => isset($assoc_args['compression']) ? $assoc_args['compression'] : '',
			'password'    => isset($assoc_args['password']) ? $assoc_args['password'] : '',
			'network'     => isset($assoc_args['network']) ? $assoc_args['network'] : '',
		);

		foreach (array_keys(Options::export(array())) as $key) {
			if (in_array($key, array( 'compression', 'password', 'network', 'replace_old', 'replace_new' ), true)) {
				continue;
			}

			if (strpos($key, 'no_') === 0) {
				$skip_flag = 'skip-' . str_replace('_', '-', substr($key, 3));
				$source[ $key ] = ! empty($assoc_args[ $skip_flag ]);
				continue;
			}

			$source[ $key ] = ! empty($assoc_args[ str_replace('_', '-', $key) ]);
		}

		$source['replace_old'] = isset($assoc_args['replace-old']) ? (array) $assoc_args['replace-old'] : array();
		$source['replace_new'] = isset($assoc_args['replace-new']) ? (array) $assoc_args['replace-new'] : array();
		$source['excluded_tables'] = isset($assoc_args['exclude-tables']) ? (array) $assoc_args['exclude-tables'] : array();
		$source['included_tables'] = isset($assoc_args['include-tables']) ? (array) $assoc_args['include-tables'] : array();

		try {
			$state = Runner::start_export(Options::export($source));
		} catch (\Throwable $e) {
			\WP_CLI::error($e->getMessage());
		}

		$this->drive($state['id']);
		\WP_CLI::success($success . ' Archive: ' . (string) $state['archiveName']);
	}

	/**
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	private function drive(string $job_id): array {
		$guard = 0;

		do {
			$state = Runner::advance($job_id);

			if (! empty($state['message'])) {
				\WP_CLI::log(sprintf('[%3d%%] %s', (int) $state['percent'], (string) $state['message']));
			}

			if (++$guard > 100000) {
				\WP_CLI::error('The job did not finish in a reasonable number of steps.');
			}
		} while (empty($state['done']));

		return $state;
	}
}
