<?php
/**
 * REST API: asynchronous export/import jobs, backups and restore.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Http;

use MigrateKit\Archive\Inspector;
use MigrateKit\Archive\Reader;
use MigrateKit\Cloud\Manager;
use MigrateKit\Job\Runner;
use MigrateKit\Storage\BackupsRepository;
use MigrateKit\Support\Capabilities;
use WP_Error;
use WP_REST_Response;

final class RestController {

	public const REST_NAMESPACE = 'migratekit/v1';

	private const JOB      = '(?P<job_id>[a-f0-9]{13,40})';
	private const NAME     = '(?P<name>[A-Za-z0-9._-]{1,247}\.wpress)';
	private const PROVIDER = '(?P<provider_id>[a-z0-9]{2,12})';

	private function __construct() {
	}

	public static function register(): void {
		add_action('rest_api_init', array( 'MigrateKit\\Http\\RestController', 'routes' ));
	}

	public static function routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/capabilities',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'capabilities' ),
				'permission_callback' => array( self::class, 'can_export' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/exports',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create_export' ),
				'permission_callback' => array( self::class, 'can_export' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/exports/' . self::JOB,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'export_status' ),
					'permission_callback' => array( self::class, 'can_export' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'cancel_export' ),
					'permission_callback' => array( self::class, 'can_export' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/exports/' . self::JOB . '/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'export_step' ),
				'permission_callback' => array( self::class, 'can_export' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/exports/' . self::JOB . '/log',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'export_log' ),
				'permission_callback' => array( self::class, 'can_export' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create_import' ),
				'permission_callback' => array( self::class, 'can_import' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/' . self::JOB . '/file',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'upload_import' ),
				'permission_callback' => array( self::class, 'can_import' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/' . self::JOB . '/chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'upload_import_chunk' ),
				'permission_callback' => array( self::class, 'can_import' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/' . self::JOB . '/chunk/finish',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'finish_import_chunks' ),
				'permission_callback' => array( self::class, 'can_import' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/' . self::JOB . '/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'import_step' ),
				'permission_callback' => array( self::class, 'can_import' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/' . self::JOB,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'import_status' ),
					'permission_callback' => array( self::class, 'can_import' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'cancel_import' ),
					'permission_callback' => array( self::class, 'can_import' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/imports/' . self::JOB . '/log',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'import_log' ),
				'permission_callback' => array( self::class, 'can_import' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/backups',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'backups' ),
				'permission_callback' => array( self::class, 'can_export' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/backups/' . self::NAME,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'backup' ),
					'permission_callback' => array( self::class, 'can_export' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( self::class, 'label_backup' ),
					'permission_callback' => array( self::class, 'can_import' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'delete_backup' ),
					'permission_callback' => array( self::class, 'can_import' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/backups/' . self::NAME . '/files',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'backup_files' ),
				'permission_callback' => array( self::class, 'can_import' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/backups/' . self::NAME . '/restore',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'restore_backup' ),
				'permission_callback' => array( self::class, 'can_import' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/resets',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create_reset' ),
				'permission_callback' => array( self::class, 'can_reset' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/resets/' . self::JOB . '/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'reset_step' ),
				'permission_callback' => array( self::class, 'can_reset' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/resets/' . self::JOB,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'reset_status' ),
				'permission_callback' => array( self::class, 'can_reset' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/cloud/providers',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'providers' ),
				'permission_callback' => array( self::class, 'can_export' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/cloud/providers/' . self::PROVIDER . '/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'save_provider' ),
				'permission_callback' => array( self::class, 'can_export' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/cloud/providers/' . self::PROVIDER . '/connect',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'connect_provider' ),
				'permission_callback' => array( self::class, 'can_export' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/cloud/providers/' . self::PROVIDER . '/callback',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'callback_provider' ),
				'permission_callback' => array( self::class, 'can_export' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/cloud/providers/' . self::PROVIDER . '/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'test_provider' ),
				'permission_callback' => array( self::class, 'can_export' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/cloud/providers/' . self::PROVIDER,
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'disconnect_provider' ),
				'permission_callback' => array( self::class, 'can_export' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/cloud/providers/' . self::PROVIDER . '/objects',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'provider_objects' ),
					'permission_callback' => array( self::class, 'can_export' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'provider_object_delete' ),
					'permission_callback' => array( self::class, 'can_export' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/cloud/providers/' . self::PROVIDER . '/push',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'provider_push' ),
				'permission_callback' => array( self::class, 'can_export' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/cloud/providers/' . self::PROVIDER . '/pull',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'provider_pull' ),
				'permission_callback' => array( self::class, 'can_import' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/cloud-jobs/' . self::JOB,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'cloud_job_status' ),
					'permission_callback' => array( self::class, 'can_cloud_job' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'cancel_cloud_job' ),
					'permission_callback' => array( self::class, 'can_cloud_job' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/cloud-jobs/' . self::JOB . '/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'cloud_step' ),
				'permission_callback' => array( self::class, 'can_cloud_job' ),
			)
		);
	}

	/**
	 * @param mixed $request Request, when invoked by WordPress.
	 * @return bool
	 */
	public static function can_export($request = null): bool {
		return Capabilities::can_run_on_network() && current_user_can(Capabilities::EXPORT);
	}

	/**
	 * @param mixed $request Request, when invoked by WordPress.
	 * @return bool
	 */
	public static function can_import($request = null): bool {
		return Capabilities::can_run_on_network() && current_user_can(Capabilities::IMPORT);
	}

	/**
	 * @param mixed $request Request, when invoked by WordPress.
	 * @return bool
	 */
	public static function can_reset($request = null): bool {
		return Capabilities::can_run_on_network() && current_user_can(Capabilities::RESET);
	}

	/**
	 * Cloud job permissions follow the job type: pushes export data, pulls
	 * import it.
	 *
	 * @param mixed $request Request.
	 * @return bool
	 */
	public static function can_cloud_job($request = null): bool {
		if (! Capabilities::can_run_on_network()) {
			return false;
		}

		if (! is_object($request) || ! isset($request['job_id'])) {
			return false;
		}

		try {
			$state = Runner::status((string) $request['job_id']);
		} catch (\Throwable $e) {
			// Missing jobs surface a 404 from the callback itself.
			return true;
		}

		return current_user_can((string) $state['type'] === Runner::PULL ? Capabilities::IMPORT : Capabilities::EXPORT);
	}

	/**
	 * @param mixed $request Request.
	 * @return WP_REST_Response
	 */
	public static function providers($request) {
		return new WP_REST_Response(array( 'providers' => Manager::status() ), 200);
	}

	/**
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_provider($request) {
		try {
			Manager::save((string) $request['provider_id'], self::params($request));

			return new WP_REST_Response(array( 'providers' => Manager::status() ), 200);
		} catch (\Throwable $e) {
			return self::error($e, 400);
		}
	}

	/**
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function connect_provider($request) {
		try {
			$start = Manager::connecting((string) $request['provider_id']);

			return new WP_REST_Response(
				array(
					'providers' => Manager::status(),
					'url'       => $start['url'],
					'state'     => $start['state'],
					'paste'     => (bool) $start['paste'],
				),
				200
			);
		} catch (\Throwable $e) {
			return self::error($e, 400);
		}
	}

	/**
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function callback_provider($request) {
		$params = self::params($request);
		$code   = isset($params['code']) ? sanitize_text_field((string) $params['code']) : '';
		$state  = isset($params['state']) ? sanitize_text_field((string) $params['state']) : '';

		try {
			Manager::exchanging((string) $request['provider_id'], $code, $state);
			Manager::test((string) $request['provider_id']);

			return new WP_REST_Response(array( 'providers' => Manager::status() ), 200);
		} catch (\Throwable $e) {
			return self::error($e, 502);
		}
	}

	/**
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function test_provider($request) {
		try {
			Manager::test((string) $request['provider_id']);

			return new WP_REST_Response(array( 'providers' => Manager::status() ), 200);
		} catch (\Throwable $e) {
			return self::error($e, 502);
		}
	}

	/**
	 * @param mixed $request Request.
	 * @return WP_REST_Response
	 */
	public static function disconnect_provider($request) {
		Manager::reset((string) $request['provider_id']);

		return new WP_REST_Response(array( 'providers' => Manager::status() ), 200);
	}

	/**
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function provider_objects($request) {
		$provider = Manager::provider((string) $request['provider_id']);

		if ($provider === null) {
			return self::provider_error();
		}

		if (! $provider->is_ready()) {
			return new WP_REST_Response(array( 'objects' => array(), 'ready' => false ), 200);
		}

		try {
			$objects = $provider->list();
		} catch (\Throwable $e) {
			return self::error($e, 502);
		}

		return new WP_REST_Response(array( 'objects' => array_values($objects), 'ready' => true ), 200);
	}

	/**
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function provider_object_delete($request) {
		$provider = Manager::provider((string) $request['provider_id']);

		if ($provider === null) {
			return self::provider_error();
		}

		$remote = isset(self::params($request)['name']) ? (string) self::params($request)['name'] : '';

		if (! Manager::valid_remote($remote)) {
			return new WP_Error('migratekit_invalid_remote', 'Invalid remote backup name.', array( 'status' => 400 ));
		}

		try {
			$provider->delete($remote);
		} catch (\Throwable $e) {
			return self::error($e, 502);
		}

		return new WP_REST_Response(array( 'deleted' => $remote ), 200);
	}

	/**
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function provider_push($request) {
		$params = self::params($request);

		$name = isset($params['name']) ? basename((string) $params['name']) : '';

		try {
			return new WP_REST_Response(Runner::start_push($name, (string) $request['provider_id']), 201);
		} catch (\Throwable $e) {
			return self::error($e, 400);
		}
	}

	/**
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function provider_pull($request) {
		$params = self::params($request);

		$remote = isset($params['remote']) ? basename((string) $params['remote']) : '';

		try {
			return new WP_REST_Response(Runner::start_pull((string) $request['provider_id'], $remote), 201);
		} catch (\Throwable $e) {
			return self::error($e, 400);
		}
	}

	/**
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cloud_job_status($request) {
		try {
			return new WP_REST_Response(Runner::status((string) $request['job_id']), 200);
		} catch (\Throwable $e) {
			return self::error($e, 404);
		}
	}

	/**
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cancel_cloud_job($request) {
		try {
			Runner::cancel((string) $request['job_id']);

			return new WP_REST_Response(array( 'cancelled' => true ), 200);
		} catch (\Throwable $e) {
			return self::error($e);
		}
	}

	/**
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cloud_step($request) {
		try {
			$state = Runner::status((string) $request['job_id']);

			if ((string) $state['type'] !== Runner::PUSH && (string) $state['type'] !== Runner::PULL) {
				return new WP_Error('migratekit_not_cloud', 'Not a cloud transfer job.', array( 'status' => 400 ));
			}

			return new WP_REST_Response(Runner::advance((string) $request['job_id']), 200);
		} catch (\Throwable $e) {
			return self::error($e, 404);
		}
	}

	/**
	 * @return WP_Error
	 */
	private static function provider_error(): WP_Error {
		return new WP_Error('migratekit_unknown_provider', 'Unknown cloud provider.', array( 'status' => 404 ));
	}

	/**
	 * Site reset. Requires the typed confirmation phrase, mirroring the UI.
	 *
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_reset($request) {
		$params = self::params($request);
		$typed  = isset($params['confirm_text']) ? strtolower(trim((string) $params['confirm_text'])) : '';

		if ($typed !== 'reset') {
			return new WP_Error(
				'migratekit_reset_confirm',
				'Type RESET in the confirmation box to confirm this action.',
				array( 'status' => 400 )
			);
		}

		try {
			return new WP_REST_Response(Runner::start_reset(Options::reset($params)), 201);
		} catch (\Throwable $e) {
			return self::error($e);
		}
	}

	public static function reset_step($request) {
		return self::job_step($request);
	}

	public static function reset_status($request) {
		return self::job_status($request);
	}

	/**
	 * @param mixed $request Request.
	 * @return WP_REST_Response
	 */
	public static function capabilities($request) {
		return new WP_REST_Response(
			array(
				'canExport'   => self::can_export(),
				'canImport'   => self::can_import(),
				'maxUpload'   => wp_max_upload_size(),
				'siteUrl'     => home_url(),
				'version'     => defined('MIGRATEKIT_VERSION') ? MIGRATEKIT_VERSION : '',
				'compression' => array( 'gzip', 'bzip2' ),
				'encryption'  => function_exists('openssl_encrypt'),
				'backups'     => count(( new BackupsRepository() )->list()),
				'isMultisite' => (bool) is_multisite(),
				'siteCount'   => AssetLoader::site_count(),
			),
			200
		);
	}

	public static function create_export($request) {
		try {
			return new WP_REST_Response(Runner::start_export(Options::export(self::params($request))), 201);
		} catch (\Throwable $e) {
			return self::error($e);
		}
	}

	public static function export_status($request) {
		return self::job_status($request);
	}

	public static function import_status($request) {
		return self::job_status($request);
	}

	public static function export_step($request) {
		return self::job_step($request);
	}

	public static function import_step($request) {
		return self::job_step($request);
	}

	public static function cancel_export($request) {
		return self::cancel_job($request);
	}

	public static function cancel_import($request) {
		return self::cancel_job($request);
	}

	public static function export_log($request) {
		return self::job_log($request);
	}

	public static function import_log($request) {
		return self::job_log($request);
	}

	public static function create_import($request) {
		try {
			return new WP_REST_Response(Runner::create_import_job(Options::import(self::params($request))), 201);
		} catch (\Throwable $e) {
			return self::error($e);
		}
	}

	public static function upload_import($request) {
		$job_id = (string) $request['job_id'];
		$files  = $request->get_file_params();

		if (empty($files['file']) || ! is_array($files['file'])) {
			return new WP_Error('migratekit_missing_file', 'No archive was uploaded.', array( 'status' => 400 ));
		}

		$file = $files['file'];
		$code = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ($code !== UPLOAD_ERR_OK) {
			return new WP_Error('migratekit_upload_failed', 'The upload failed.', array( 'status' => 400 ));
		}

		$tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

		if ($tmp === '') {
			return new WP_Error('migratekit_upload_failed', 'Invalid upload.', array( 'status' => 400 ));
		}

		try {
			$reader = new Reader($tmp);

			if (! $reader->is_valid()) {
				throw new \RuntimeException('The selected file is not a valid .wpress archive.');
			}

			return new WP_REST_Response(Runner::attach_import_file($job_id, $tmp), 200);
		} catch (\Throwable $e) {
			return self::error($e, 400);
		}
	}

	public static function backups($request) {
		$repository = new BackupsRepository();
		$items      = array();

		foreach ($repository->list() as $backup) {
			$items[] = self::backup_payload($backup);
		}

		return new WP_REST_Response(array( 'backups' => $items ), 200);
	}

	public static function backup($request) {
		$repository = new BackupsRepository();
		$backup     = $repository->find((string) $request['name']);

		if ($backup === null) {
			return new WP_Error('migratekit_backup_not_found', 'Backup not found.', array( 'status' => 404 ));
		}

		return new WP_REST_Response(self::backup_payload($backup), 200);
	}

	public static function delete_backup($request) {
		$repository = new BackupsRepository();
		$name       = (string) $request['name'];

		if (! $repository->delete($name)) {
			return new WP_Error('migratekit_backup_not_found', 'Backup not found.', array( 'status' => 404 ));
		}

		return new WP_REST_Response(array( 'deleted' => $name ), 200);
	}

	/**
	 * Set or clear an archive label.
	 *
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function label_backup($request) {
		$params = self::params($request);
		$label  = isset($params['label']) ? sanitize_text_field((string) $params['label']) : '';
		$label  = substr(trim($label), 0, 255);
		$name   = (string) $request['name'];

		if (! BackupsRepository::set_label($name, $label)) {
			return new WP_Error('migratekit_backup_not_found', 'Backup not found.', array( 'status' => 404 ));
		}

		return new WP_REST_Response(array( 'name' => $name, 'label' => $label ), 200);
	}

	/**
	 * Browser listing of one archive, tolerating encrypted manifests.
	 *
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function backup_files($request) {
		$repository = new BackupsRepository();
		$backup     = $repository->find((string) $request['name']);

		if ($backup === null) {
			return new WP_Error('migratekit_backup_not_found', 'Backup not found.', array( 'status' => 404 ));
		}

		$params   = self::params($request);
		$password = isset($params['password']) ? (string) $params['password'] : '';

		try {
			$package = Inspector::package($backup['path'], $password);

			Inspector::verify_password($package, $password);

			return new WP_REST_Response(
				array(
					'name'      => $backup['name'],
					'encrypted' => Inspector::is_encrypted($package),
					'files'     => Inspector::entries($backup['path'], $password),
				),
				200
			);
		} catch (\Throwable $e) {
			return new WP_Error('migratekit_archive_error', $e->getMessage(), array( 'status' => 400 ));
		}
	}

	/**
	 * Append one uploaded slice to a reserved import job.
	 *
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload_import_chunk($request) {
		$files = $request->get_file_params();

		if (empty($files['file']) || ! is_array($files['file'])) {
			return new WP_Error('migratekit_missing_file', 'No chunk was uploaded.', array( 'status' => 400 ));
		}

		$file = $files['file'];
		$code = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		$tmp  = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

		if ($code !== UPLOAD_ERR_OK || $tmp === '') {
			return new WP_Error('migratekit_upload_failed', 'The chunk upload failed; retrying.', array( 'status' => 400 ));
		}

		$params = self::params($request);
		$offset = isset($params['offset']) ? (int) $params['offset'] : -1;

		try {
			return new WP_REST_Response(Runner::append_import_chunk((string) $request['job_id'], $tmp, $offset), 201);
		} catch (\Throwable $e) {
			return new WP_Error('migratekit_chunk_error', $e->getMessage(), array( 'status' => 400 ));
		}
	}

	/**
	 * Validate the assembled chunked archive and start the job.
	 *
	 * @param mixed $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function finish_import_chunks($request) {
		$params = self::params($request);
		$total  = isset($params['total']) ? (int) $params['total'] : 0;
		$name   = isset($params['name']) ? sanitize_file_name((string) $params['name']) : 'upload.wpress';

		try {
			return new WP_REST_Response(Runner::finish_import_chunks((string) $request['job_id'], $total, $name), 200);
		} catch (\Throwable $e) {
			return new WP_Error('migratekit_chunk_error', $e->getMessage(), array( 'status' => 400 ));
		}
	}

	public static function restore_backup($request) {
		try {
			return new WP_REST_Response(
				Runner::start_restore((string) $request['name'], Options::import(self::params($request))),
				201
			);
		} catch (\Throwable $e) {
			return self::error($e);
		}
	}

	/**
	 * @param array $backup Backup row.
	 * @return array
	 */
	private static function backup_payload(array $backup): array {
		return array(
			'name'        => $backup['name'],
			'label'       => BackupsRepository::label_for($backup['name']),
			'size'        => $backup['size'],
			'mtime'       => $backup['mtime'],
			'downloadUrl' => add_query_arg(
				array(
					'action'   => 'migratekit_download',
					'file'     => rawurlencode($backup['name']),
					'_wpnonce' => wp_create_nonce('migratekit_download'),
				),
				admin_url('admin-post.php')
			),
		);
	}

	private static function job_status($request) {
		try {
			return new WP_REST_Response(Runner::status((string) $request['job_id']), 200);
		} catch (\Throwable $e) {
			return self::error($e, 404);
		}
	}

	private static function job_step($request) {
		try {
			return new WP_REST_Response(Runner::advance((string) $request['job_id']), 200);
		} catch (\Throwable $e) {
			return self::error($e);
		}
	}

	private static function cancel_job($request) {
		try {
			Runner::cancel((string) $request['job_id']);
			return new WP_REST_Response(array( 'cancelled' => true ), 200);
		} catch (\Throwable $e) {
			return self::error($e);
		}
	}

	private static function job_log($request) {
		return new WP_REST_Response(
			array( 'log' => Runner::log((string) $request['job_id']) ),
			200
		);
	}

	/**
	 * @param mixed $request Request.
	 * @return array
	 */
	private static function params($request): array {
		if (is_object($request) && method_exists($request, 'get_json_params')) {
			$json = $request->get_json_params();

			if (is_array($json) && $json !== array()) {
				return $json;
			}
		}

		if (is_object($request) && method_exists($request, 'get_params')) {
			$params = $request->get_params();

			if (is_array($params)) {
				return $params;
			}
		}

		return array();
	}

	private static function error(\Throwable $exception, int $status = 500): WP_Error {
		return new WP_Error('migratekit_error', $exception->getMessage(), array( 'status' => $status ));
	}
}
