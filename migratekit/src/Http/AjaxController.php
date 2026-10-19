<?php
/**
 * Admin AJAX endpoints for export, import and backups.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Http;

use MigrateKit\Archive\Inspector;
use MigrateKit\Archive\Reader;
use MigrateKit\Cloud\Manager;
use MigrateKit\Job\Runner;
use MigrateKit\Scheduler\Cron;
use MigrateKit\Scheduler\Schedule;
use MigrateKit\Storage\BackupsRepository;
use MigrateKit\Support\Capabilities;
use MigrateKit\Support\SecretKey;

final class AjaxController {

	private function __construct() {
	}

	public static function register(): void {
		$actions = array(
			'migratekit_export_start'  => 'export_start',
			'migratekit_export_step'   => 'export_step',
			'migratekit_import_upload' => 'import_upload',
			'migratekit_import_step'   => 'import_step',
			'migratekit_import_chunk'  => 'import_chunk',
			'migratekit_status'        => 'status',
			'migratekit_backups_list'  => 'backups_list',
			'migratekit_backup_delete' => 'backup_delete',
			'migratekit_backup_restore' => 'backup_restore',
			'migratekit_backup_label'   => 'backup_label',
			'migratekit_backup_files'   => 'backup_files',
			'migratekit_schedules_list' => 'schedules_list',
			'migratekit_schedule_save'  => 'schedule_save',
			'migratekit_schedule_delete' => 'schedule_delete',
			'migratekit_schedule_run'   => 'schedule_run',
			'migratekit_reset_start'    => 'reset_start',
			'migratekit_reset_step'     => 'reset_step',
			'migratekit_cloud_status'   => 'cloud_status',
			'migratekit_cloud_save'     => 'cloud_save',
			'migratekit_cloud_test'     => 'cloud_test',
			'migratekit_cloud_disconnect' => 'cloud_disconnect',
			'migratekit_cloud_files'    => 'cloud_files',
			'migratekit_cloud_delete'   => 'cloud_remote_delete',
			'migratekit_cloud_push'     => 'cloud_push',
			'migratekit_cloud_pull'     => 'cloud_pull',
			'migratekit_cloud_step'     => 'cloud_step',
		);

		foreach ($actions as $action => $method) {
			add_action('wp_ajax_' . $action, array( 'MigrateKit\\Http\\AjaxController', $method ));
		}
	}

	public static function export_start(): void {
		self::guard(Capabilities::EXPORT);

		try {
			wp_send_json_success(Runner::start_export(Options::export($_POST)));
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 500);
		}
	}

	public static function export_step(): void {
		self::guard(Capabilities::EXPORT);
		self::advance();
	}

	public static function import_upload(): void {
		self::guard(Capabilities::IMPORT);

		if (empty($_FILES['file']) || ! is_array($_FILES['file'])) {
			wp_send_json_error(array( 'message' => 'No archive was uploaded.' ), 400);
		}

		$file = $_FILES['file'];

		if (! isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
			wp_send_json_error(array( 'message' => 'The upload failed. Check your server upload limit and try again.' ), 400);
		}

		$tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

		if ($tmp === '' || ! is_uploaded_file($tmp)) {
			wp_send_json_error(array( 'message' => 'Invalid upload.' ), 400);
		}

		try {
			$reader = new Reader($tmp);

			if (! $reader->is_valid()) {
				throw new \RuntimeException('The selected file is not a valid .wpress archive.');
			}
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 400);
		}

		$name = isset($file['name']) ? sanitize_file_name((string) $file['name']) : 'upload.wpress';

		try {
			wp_send_json_success(Runner::start_import($tmp, $name, Options::import($_POST)));
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 500);
		}
	}

	public static function import_step(): void {
		self::guard(Capabilities::IMPORT);
		self::advance();
	}

	/**
	 * Chunked import upload: phase=start reserves the job, phase=append
	 * streams one slice, phase=finish validates the assembled archive.
	 *
	 * @return void
	 */
	public static function import_chunk(): void {
		self::guard(Capabilities::IMPORT);

		$phase = isset($_POST['phase']) ? sanitize_key((string) $_POST['phase']) : '';
		$job_id = isset($_POST['job_id']) ? sanitize_text_field((string) wp_unslash($_POST['job_id'])) : '';

		try {
			if ($phase === 'start') {
				wp_send_json_success(Runner::create_import_job(Options::import($_POST)));
			}

			if ($phase === 'append') {
				if ($job_id === '' || empty($_FILES['file']) || ! is_array($_FILES['file'])) {
					wp_send_json_error(array( 'message' => 'No chunk was uploaded.' ), 400);
				}

				$file   = $_FILES['file'];
				$tmp    = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
				$offset = isset($_POST['offset']) ? (int) $_POST['offset'] : -1;

				if (! isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK || $tmp === '' || ! is_uploaded_file($tmp)) {
					wp_send_json_error(array( 'message' => 'The chunk upload failed; retrying.' ), 500);
				}

				wp_send_json_success(Runner::append_import_chunk($job_id, $tmp, $offset));
			}

			if ($phase === 'finish') {
				$total = isset($_POST['total']) ? (int) $_POST['total'] : 0;
				$name  = isset($_POST['name']) ? sanitize_file_name((string) wp_unslash($_POST['name'])) : 'upload.wpress';

				wp_send_json_success(Runner::finish_import_chunks($job_id, $total, $name));
			}

			wp_send_json_error(array( 'message' => 'Unknown upload phase.' ), 400);
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 400);
		}
	}

	public static function status(): void {
		$job_id = isset($_POST['job_id']) ? sanitize_text_field((string) wp_unslash($_POST['job_id'])) : '';

		if (! self::authorize()) {
			wp_send_json_error(array( 'message' => 'You do not have permission to do that.' ), 403);
		}

		try {
			wp_send_json_success(Runner::status($job_id));
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 404);
		}
	}

	public static function backups_list(): void {
		self::guard(Capabilities::IMPORT);

		$repository = new BackupsRepository();
		$items      = array();

		foreach ($repository->list() as $backup) {
			$items[] = array(
				'name'        => $backup['name'],
				'label'       => BackupsRepository::label_for($backup['name']),
				'size'        => $backup['size'],
				'mtime'       => $backup['mtime'],
				'downloadUrl' => self::download_url($backup['name']),
			);
		}

		wp_send_json_success(array( 'backups' => $items, 'labels' => BackupsRepository::labels() ));
	}

	/**
	 * Nonce-gated download URL for one archive.
	 *
	 * @param string $name Archive name.
	 * @return string
	 */
	private static function download_url(string $name): string {
		return add_query_arg(
			array(
				'action'   => 'migratekit_download',
				'file'     => rawurlencode($name),
				'_wpnonce' => wp_create_nonce('migratekit_download'),
			),
			admin_url('admin-post.php')
		);
	}

	public static function backup_delete(): void {
		self::guard(Capabilities::IMPORT);

		$repository = new BackupsRepository();
		$names      = array();

		if (isset($_POST['names']) && is_array($_POST['names'])) {
			foreach ($_POST['names'] as $candidate) {
				$names[] = basename((string) wp_unslash($candidate));
			}
		} else {
			$names[] = basename((string) wp_unslash((string) ( $_POST['name'] ?? '' )));
		}

		$deleted = array();

		foreach ($names as $name) {
			if (! BackupsRepository::validate_name($name)) {
				continue;
			}

			if ($repository->delete($name)) {
				$deleted[] = $name;
			}
		}

		if ($deleted === array()) {
			wp_send_json_error(array( 'message' => 'No matching backup could be deleted.' ), 404);
		}

		wp_send_json_success(array( 'deleted' => $deleted ));
	}

	/**
	 * Start an import job from a backup stored on this server.
	 *
	 * @return void
	 */
	public static function backup_restore(): void {
		self::guard(Capabilities::IMPORT);

		$name = basename((string) wp_unslash((string) ( $_POST['name'] ?? '' )));

		if (! BackupsRepository::validate_name($name)) {
			wp_send_json_error(array( 'message' => 'Invalid backup name.' ), 400);
		}

		try {
			wp_send_json_success(Runner::start_restore($name, Options::import($_POST)));
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 400);
		}
	}

	/**
	 * Rename a backup.
	 *
	 * @return void
	 */
	public static function backup_label(): void {
		self::guard(Capabilities::IMPORT);

		$name  = basename((string) wp_unslash((string) ( $_POST['name'] ?? '' )));
		$label = substr(trim(sanitize_text_field((string) ( $_POST['label'] ?? '' ))), 0, 255);

		if (! BackupsRepository::set_label($name, $label)) {
			wp_send_json_error(array( 'message' => 'Backup not found.' ), 404);
		}

		wp_send_json_success(array( 'name' => $name, 'label' => $label ));
	}

	/**
	 * List the entries of an archive for the backup browser.
	 *
	 * @return void
	 */
	public static function backup_files(): void {
		self::guard(Capabilities::IMPORT);

		$name     = basename((string) wp_unslash((string) ( $_POST['name'] ?? '' )));
		$password = (string) ( $_POST['password'] ?? '' );

		if (! BackupsRepository::validate_name($name)) {
			wp_send_json_error(array( 'message' => 'Invalid backup name.' ), 400);
		}

		$repository = new BackupsRepository();
		$backup     = $repository->find($name);

		if ($backup === null) {
			wp_send_json_error(array( 'message' => 'Backup not found.' ), 404);
		}

		try {
			$package = Inspector::package($backup['path'], $password);

			Inspector::verify_password($package, $password);

			wp_send_json_success(
				array(
					'name'      => $name,
					'encrypted' => Inspector::is_encrypted($package),
					'files'     => Inspector::entries($backup['path'], $password),
				)
			);
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 400);
		}
	}

	public static function schedules_list(): void {
		self::guard(Capabilities::EXPORT);

		wp_send_json_success(
			array(
				'schedules' => Schedule::all(),
				'intervals' => Schedule::INTERVALS,
			)
		);
	}

	public static function schedule_save(): void {
		self::guard(Capabilities::EXPORT);

		try {
			wp_send_json_success(array( 'schedule' => Schedule::save($_POST) ));
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 500);
		}
	}

	public static function schedule_delete(): void {
		self::guard(Capabilities::EXPORT);

		$id = isset($_POST['id']) ? sanitize_text_field((string) wp_unslash($_POST['id'])) : '';

		if ($id === '' || ! Schedule::delete($id)) {
			wp_send_json_error(array( 'message' => 'Schedule not found.' ), 404);
		}

		wp_send_json_success(array( 'deleted' => $id ));
	}

	public static function schedule_run(): void {
		self::guard(Capabilities::EXPORT);

		$id = isset($_POST['id']) ? sanitize_text_field((string) wp_unslash($_POST['id'])) : '';

		if ($id === '' || Schedule::get($id) === null) {
			wp_send_json_error(array( 'message' => 'Schedule not found.' ), 404);
		}

		wp_schedule_single_event(time(), Cron::HOOK, array( $id ));

		wp_send_json_success(array( 'queued' => true ));
	}

	public static function reset_start(): void {
		self::guard_reset();

		$confirm = isset($_POST['confirm_text'])
			? strtolower(trim((string) wp_unslash($_POST['confirm_text'])))
			: '';

		if ($confirm !== 'reset') {
			wp_send_json_error(
				array(
					'message' => 'Type RESET in the confirmation box to confirm this action.',
					'confirm' => true,
				),
				400
			);
		}

		try {
			wp_send_json_success(Runner::start_reset(Options::reset($_POST)));
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 500);
		}
	}

	public static function reset_step(): void {
		self::guard_reset();
		self::advance();
	}

	/**
	 * @return void
	 */
	public static function cloud_status(): void {
		self::guard(Capabilities::EXPORT);

		wp_send_json_success(array( 'providers' => Manager::status() ));
	}

	/**
	 * @return void
	 */
	public static function cloud_save(): void {
		self::guard(Capabilities::EXPORT);

		$provider = self::cloud_provider_id();

		try {
			Manager::save($provider, $_POST);

			wp_send_json_success(array( 'providers' => Manager::status() ));
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 400);
		}
	}

	/**
	 * @return void
	 */
	public static function cloud_test(): void {
		self::guard(Capabilities::EXPORT);

		$provider = self::cloud_provider_id();

		try {
			Manager::test($provider);

			wp_send_json_success(array( 'providers' => Manager::status() ));
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 502);
		}
	}

	/**
	 * @return void
	 */
	public static function cloud_disconnect(): void {
		self::guard(Capabilities::EXPORT);

		Manager::reset(self::cloud_provider_id());

		wp_send_json_success(array( 'providers' => Manager::status() ));
	}

	/**
	 * Remote archive listing for one provider.
	 *
	 * @return void
	 */
	public static function cloud_files(): void {
		self::guard(Capabilities::EXPORT);

		$provider = self::cloud_provider_id();

		try {
			$instance = Manager::provider($provider);

			if ($instance === null) {
				throw new \RuntimeException('Unknown cloud provider: ' . $provider);
			}

			$objects = $instance->is_ready() ? $instance->list() : array();

			$items = array();

			foreach ($objects as $object) {
				$items[] = array(
					'name'  => $object['name'],
					'size'  => $object['size'],
					'mtime' => $object['mtime'],
				);
			}

			wp_send_json_success(array( 'objects' => $items, 'ready' => $instance->is_ready() ));
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 502);
		}
	}

	/**
	 * @return void
	 */
	public static function cloud_remote_delete(): void {
		self::guard(Capabilities::EXPORT);

		$provider = self::cloud_provider_id();
		$remote   = isset($_POST['name']) ? basename((string) wp_unslash($_POST['name'])) : '';

		if (! Manager::valid_remote($remote)) {
			wp_send_json_error(array( 'message' => 'Invalid remote backup name.' ), 400);
		}

		try {
			$instance = Manager::provider($provider);

			if ($instance === null) {
				throw new \RuntimeException('Unknown cloud provider: ' . $provider);
			}

			$instance->delete($remote);

			wp_send_json_success(array( 'deleted' => $remote ));
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 502);
		}
	}

	/**
	 * @return void
	 */
	public static function cloud_push(): void {
		self::guard(Capabilities::EXPORT);

		$provider = self::cloud_provider_id();
		$name     = isset($_POST['name']) ? basename((string) wp_unslash($_POST['name'])) : '';

		try {
			wp_send_json_success(Runner::start_push($name, $provider));
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 400);
		}
	}

	/**
	 * @return void
	 */
	public static function cloud_pull(): void {
		self::guard(Capabilities::IMPORT);

		$provider = self::cloud_provider_id();
		$remote   = isset($_POST['remote']) ? basename((string) wp_unslash($_POST['remote'])) : '';

		try {
			wp_send_json_success(Runner::start_pull($provider, $remote));
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 400);
		}
	}

	/**
	 * Advance a push or pull job; the capability follows the job type.
	 *
	 * @return void
	 */
	public static function cloud_step(): void {
		$job_id = isset($_POST['job_id']) ? sanitize_text_field((string) wp_unslash($_POST['job_id'])) : '';

		if (! self::nonce_ok()) {
			wp_send_json_error(array( 'message' => 'You do not have permission to do that.' ), 403);
		}

		if (! Capabilities::can_run_on_network()) {
			wp_send_json_error(array( 'message' => 'You do not have permission to do that.' ), 403);
		}

		try {
			$state = Runner::status($job_id);
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 404);
		}

		$type = isset($state['type']) ? (string) $state['type'] : '';

		if ($type !== Runner::PUSH && $type !== Runner::PULL) {
			wp_send_json_error(array( 'message' => 'Not a cloud transfer job.' ), 400);
		}

		if (! current_user_can($type === Runner::PULL ? Capabilities::IMPORT : Capabilities::EXPORT)) {
			wp_send_json_error(array( 'message' => 'You do not have permission to do that.' ), 403);
		}

		self::advance();
	}

	/**
	 * Normalised cloud provider id from the request.
	 *
	 * @return string
	 */
	private static function cloud_provider_id(): string {
		return sanitize_key((string) ( $_POST['provider'] ?? '' ));
	}

	/**
	 * Advance a job by one request-sized chunk. Job capabilities follow the
	 * job type; after the users table is replaced mid-import the browser may
	 * lose its session, so a valid shared secret may resume import jobs only.
	 *
	 * @return void
	 */
	private static function advance(): void {
		$job_id = isset($_POST['job_id']) ? sanitize_text_field((string) wp_unslash($_POST['job_id'])) : '';

		try {
			$state = Runner::status($job_id);
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 404);
		}

		$type = isset($state['type']) ? (string) $state['type'] : '';

		if (! self::nonce_ok()) {
			$secret = isset($_POST['secret']) ? (string) wp_unslash($_POST['secret']) : '';

			if ($type !== Runner::IMPORT || ! SecretKey::verify($secret)) {
				wp_send_json_error(array( 'message' => 'You do not have permission to do that.' ), 403);
			}
		} else {
			$caps = array(
				Runner::EXPORT => Capabilities::EXPORT,
				Runner::IMPORT => Capabilities::IMPORT,
				Runner::RESET  => Capabilities::RESET,
				Runner::PUSH   => Capabilities::EXPORT,
				Runner::PULL   => Capabilities::IMPORT,
			);

			$capability = isset($caps[ $type ]) ? $caps[ $type ] : null;

			if ($capability === null || ! Capabilities::can_run_on_network() || ! current_user_can($capability)) {
				wp_send_json_error(array( 'message' => 'You do not have permission to do that.' ), 403);
			}
		}

		try {
			wp_send_json_success(Runner::advance($job_id));
		} catch (\Throwable $e) {
			wp_send_json_error(array( 'message' => $e->getMessage() ), 500);
		}
	}

	/**
	 * Guard a mutating action: valid nonce plus live capabilities only.
	 *
	 * @param string $capability Required capability.
	 * @return void
	 */
	private static function guard(string $capability): void {
		if (! self::nonce_ok()) {
			wp_send_json_error(array( 'message' => 'You do not have permission to do that.' ), 403);
		}

		if (! Capabilities::can_run_on_network() || ! current_user_can($capability)) {
			wp_send_json_error(array( 'message' => 'You do not have permission to do that.' ), 403);
		}
	}

	/**
	 * Read access to job state: live admin (nonce, export or import) or the
	 * shared secret fallback.
	 *
	 * @return bool
	 */
	private static function authorize(): bool {
		$secret = isset($_POST['secret']) ? (string) wp_unslash($_POST['secret']) : '';

		if (SecretKey::verify($secret)) {
			return true;
		}

		return self::nonce_ok()
			&& Capabilities::can_run_on_network()
			&& ( current_user_can(Capabilities::EXPORT) || current_user_can(Capabilities::IMPORT) );
	}

	/**
	 * @return bool
	 */
	private static function nonce_ok(): bool {
		$nonce = isset($_POST['nonce']) ? (string) wp_unslash($_POST['nonce']) : '';

		return $nonce !== '' && wp_verify_nonce($nonce, 'migratekit_ajax') !== false;
	}

	/**
	 * Reset actions are permission-, network- and capability-guarded.
	 *
	 * @return void
	 */
	private static function guard_reset(): void {
		if (! self::nonce_ok()) {
			wp_send_json_error(array( 'message' => 'You do not have permission to do that.' ), 403);
		}

		if (! Capabilities::can_run_on_network() || ! current_user_can(Capabilities::RESET)) {
			wp_send_json_error(array( 'message' => 'You do not have permission to do that.' ), 403);
		}
	}
}
