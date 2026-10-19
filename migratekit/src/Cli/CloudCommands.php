<?php
/**
 * WP-CLI commands for cloud storage transfers.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cli;

use MigrateKit\Cloud\Manager;
use MigrateKit\Job\Runner;

final class CloudCommands {

	public static function register(): void {
		if (! defined('WP_CLI') || ! WP_CLI) {
			return;
		}

		\WP_CLI::add_command('migratekit cloud', self::class);
	}

	/**
	 * Show configured cloud providers.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migratekit cloud list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function list(array $args, array $assoc_args): void {
		$rows = array();

		foreach (Manager::status() as $provider) {
			$rows[] = array(
				'id'      => (string) $provider['id'],
				'label'   => (string) $provider['label'],
				'ready'   => ! empty($provider['ready']) ? 'yes' : 'no',
				'account' => (string) ( $provider['account'] ?? '' ),
			);
		}

		if ($rows === array()) {
			\WP_CLI::log('No cloud providers configured.');
			return;
		}

		\WP_CLI\Utils\format_items('table', $rows, array( 'id', 'label', 'ready', 'account' ));
	}

	/**
	 * Test the connection of one provider (or all of them).
	 *
	 * ## OPTIONS
	 *
	 * [--provider=<id>]
	 * : Provider id: s3, dropbox or gdrive. Defaults to all configured ones.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migratekit cloud test --provider=s3
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function test(array $args, array $assoc_args): void {
		$ids = isset($assoc_args['provider'])
			? array( (string) $assoc_args['provider'] )
			: Manager::PROVIDERS;

		foreach ($ids as $id) {
			try {
				Manager::test($id);
				\WP_CLI::success($id . ': connection OK.');
			} catch (\Throwable $e) {
				\WP_CLI::warning($id . ': ' . $e->getMessage());
			}
		}
	}

	/**
	 * List remote backups on a provider.
	 *
	 * ## OPTIONS
	 *
	 * [--provider=<id>]
	 * : Provider id. Defaults to the only ready provider.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migratekit cloud files --provider=s3
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function files(array $args, array $assoc_args): void {
		$id       = $this->pick_provider($assoc_args);
		$provider = $this->provider($id);
		$rows     = array();

		foreach ($provider->list() as $object) {
			$rows[] = array(
				'name'    => (string) $object['name'],
				'size'    => size_format((int) $object['size']),
				'modified' => gmdate('Y-m-d H:i:s', (int) $object['mtime']),
			);
		}

		if ($rows === array()) {
			\WP_CLI::log('No remote backups found on ' . $provider->label() . '.');
			return;
		}

		\WP_CLI\Utils\format_items('table', $rows, array( 'name', 'size', 'modified' ));
	}

	/**
	 * Push a local backup to a cloud provider.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Backup file name from the backups directory.
	 *
	 * [--provider=<id>]
	 * : Provider id. Defaults to the only ready provider.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migratekit cloud push site-20260101-120000-abcdef123456.wpress --provider=s3
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function push(array $args, array $assoc_args): void {
		$name = isset($args[0]) ? (string) $args[0] : '';
		$id   = $this->pick_provider($assoc_args);

		try {
			$state = Runner::start_push($name, $id);
		} catch (\Throwable $e) {
			\WP_CLI::error($e->getMessage());
		}

		$this->drive($state['id']);
		\WP_CLI::success('Pushed ' . $name . ' to ' . $id . '.');
	}

	/**
	 * Pull a remote backup into the local backups directory.
	 *
	 * ## OPTIONS
	 *
	 * <remote>
	 * : Remote backup file name.
	 *
	 * [--provider=<id>]
	 * : Provider id. Defaults to the only ready provider.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migratekit cloud pull site-20260101-120000-abcdef123456.wpress --provider=s3
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function pull(array $args, array $assoc_args): void {
		$remote = isset($args[0]) ? (string) $args[0] : '';
		$id     = $this->pick_provider($assoc_args);

		try {
			$state = Runner::start_pull($id, $remote);
		} catch (\Throwable $e) {
			\WP_CLI::error($e->getMessage());
		}

		$this->drive($state['id']);
		\WP_CLI::success('Fetched ' . $remote . ' from ' . $id . '.');
	}

	/**
	 * @param array $assoc_args Associative arguments.
	 * @return string Provider id.
	 */
	private function pick_provider(array $assoc_args): string {
		if (isset($assoc_args['provider'])) {
			return (string) $assoc_args['provider'];
		}

		$ready = array();

		foreach (Manager::status() as $provider) {
			if (! empty($provider['ready'])) {
				$ready[] = (string) $provider['id'];
			}
		}

		if (count($ready) === 1) {
			return $ready[0];
		}

		\WP_CLI::error(
			$ready === array()
				? 'No cloud provider is configured. See `wp migratekit cloud list`.'
				: 'Several providers are ready. Choose one with --provider=<id>.'
		);
	}

	/**
	 * @param string $id Provider id.
	 * @return \MigrateKit\Cloud\CloudProviderInterface
	 */
	private function provider(string $id) {
		$provider = Manager::provider($id);

		if ($provider === null) {
			\WP_CLI::error('Unknown cloud provider: ' . $id);
		}

		if (! $provider->is_ready()) {
			\WP_CLI::error('The ' . $provider->label() . ' settings are incomplete.');
		}

		return $provider;
	}

	/**
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	private function drive(string $job_id): void {
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
	}
}
