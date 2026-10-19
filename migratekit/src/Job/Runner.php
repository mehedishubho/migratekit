<?php
/**
 * Glue between WordPress jobs and the export/import/reset pipelines.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Job;

use MigrateKit\Archive\Reader;
use MigrateKit\Cloud\Manager;
use MigrateKit\Database\Driver\Factory;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Site\WordPressSiteContext;
use MigrateKit\Storage\BackupsRepository;
use MigrateKit\Support\Maintenance;
use MigrateKit\Support\Paths;

final class Runner {

	public const EXPORT = 'export';
	public const IMPORT = 'import';
	public const RESET  = 'reset';
	public const PUSH   = 'push';
	public const PULL   = 'pull';

	public const EXPORT_STAGES = 13;
	public const IMPORT_STAGES = 8;
	public const RESET_STAGES  = 8;
	public const PUSH_STAGES   = 3;
	public const PULL_STAGES   = 3;

	/**
	 * @param string $type Job type constant.
	 * @return int Stage count for the type.
	 */
	private static function stages_for(string $type): int {
		if ($type === self::IMPORT) {
			return self::IMPORT_STAGES;
		}

		if ($type === self::RESET) {
			return self::RESET_STAGES;
		}

		if ($type === self::PUSH) {
			return self::PUSH_STAGES;
		}

		if ($type === self::PULL) {
			return self::PULL_STAGES;
		}

		return self::EXPORT_STAGES;
	}

	/**
	 * @param string $type Job type constant.
	 * @return callable(): \MigrateKit\Pipeline\Pipeline
	 */
	private static function pipeline_factory(string $type): callable {
		if ($type === self::IMPORT) {
			return array( 'MigrateKit\\Import\\ImportPipeline', 'create' );
		}

		if ($type === self::RESET) {
			return array( 'MigrateKit\\Reset\\ResetPipeline', 'create' );
		}

		if ($type === self::PUSH) {
			return array( 'MigrateKit\\Cloud\\PushPipeline', 'create' );
		}

		if ($type === self::PULL) {
			return array( 'MigrateKit\\Cloud\\PullPipeline', 'create' );
		}

		return array( 'MigrateKit\\Export\\ExportPipeline', 'create' );
	}

	private function __construct() {
	}

	/**
	 * @param array $options Export options.
	 * @return array Public job state.
	 */
	public static function start_export(array $options): array {
		$id      = JobId::generate();
		$storage = self::storage_dir($id);
		$name    = self::archive_name();
		$archive = $storage . DIRECTORY_SEPARATOR . $name;

		$state = array(
			'id'         => $id,
			'type'       => self::EXPORT,
			'archive_path' => $archive,
			'storage_dir'  => $storage,
			'final_name'   => $name,
			'options'      => $options,
			'stage'        => 0,
			'percent'      => 0,
			'message'      => 'Preparing export…',
			'created'      => time(),
		);

		Status::write($id, $state);

		return self::public_state($state, false, self::stages_for(self::EXPORT));
	}

	/**
	 * @param string $tmp_path      Uploaded temporary file.
	 * @param string $original_name Original upload name.
	 * @param array  $options       Import options.
	 * @return array Public job state.
	 */
	public static function start_import(string $tmp_path, string $original_name, array $options = array()): array {
		$id      = JobId::generate();
		$storage = self::storage_dir($id);
		$archive = $storage . DIRECTORY_SEPARATOR . 'upload.wpress';

		if (is_uploaded_file($tmp_path)) {
			$stored = @move_uploaded_file($tmp_path, $archive);
		} else {
			$stored = @copy($tmp_path, $archive);
		}

		if (! $stored) {
			throw new \RuntimeException('Unable to store the uploaded archive.');
		}

		$state = array(
			'id'            => $id,
			'type'          => self::IMPORT,
			'archive_path'  => $archive,
			'storage_dir'   => $storage,
			'final_name'    => basename($original_name),
			'options'       => $options,
			'stage'         => 0,
			'percent'       => 0,
			'message'       => 'Upload complete',
			'created'       => time(),
		);

		Status::write($id, $state);

		return self::public_state($state, false, self::stages_for(self::IMPORT));
	}

	/**
	 * Advance a job by one request-sized chunk.
	 *
	 * @param string $job_id Job identifier.
	 * @return array Public job state.
	 */
	public static function advance(string $job_id): array {
		$state = Status::read($job_id);

		if ($state === array()) {
			throw new \RuntimeException('Job not found.');
		}

		$type   = isset($state['type']) ? (string) $state['type'] : self::EXPORT;
		$status = static function (string $message, ?int $percent = null) use (&$state): void {
			$state['message'] = $message;

			if ($percent !== null) {
				$state['percent'] = $percent;
			}

			Logger::append($state, $message);
		};

		// An import or restore replaces the running site: shield it while work is in flight.
		if ($type === self::IMPORT) {
			Maintenance::start();
		}

		$context = new PipelineContext(
			Factory::create($GLOBALS['wpdb']),
			WordPressSiteContext::build(),
			$status,
			8.0
		);

		$factory  = self::pipeline_factory($type);
		$pipeline = $factory();
		$done     = $pipeline->advance($state, $context);

		if ($done && $type === self::EXPORT) {
			self::publish_export($state);
		}

		if ($done) {
			$state['percent'] = 100;

			$messages = array(
				self::IMPORT => 'Import complete',
				self::RESET  => 'Site reset complete',
				self::PUSH   => 'Push complete',
				self::PULL   => 'Fetched from cloud',
			);

			$state['message'] = isset($messages[ $type ]) ? $messages[ $type ] : 'Backup created';

			if ($type === self::IMPORT) {
				Maintenance::stop();
			}
		}

		Status::write($job_id, $state);

		return self::public_state($state, $done, $pipeline->stage_count());
	}

	/**
	 * Reserve an import job before its archive is uploaded.
	 *
	 * @param array $options Import options.
	 * @return array Public job state.
	 */
	public static function create_import_job(array $options = array()): array {
		$id      = JobId::generate();
		$storage = self::storage_dir($id);

		$state = array(
			'id'           => $id,
			'type'         => self::IMPORT,
			'archive_path' => $storage . DIRECTORY_SEPARATOR . 'upload.wpress',
			'storage_dir'  => $storage,
			'final_name'   => '',
			'options'      => $options,
			'stage'        => 0,
			'percent'      => 0,
			'message'      => 'Waiting for upload',
			'created'      => time(),
			'received'     => 0,
			'uploaded'     => false,
		);

		Status::write($id, $state);

		return self::public_state($state, false, self::stages_for(self::IMPORT));
	}

	/**
	 * Attach an uploaded or local archive to a reserved import job.
	 *
	 * @param string $job_id   Job identifier.
	 * @param string $tmp_path Source archive path.
	 * @return array Public job state.
	 */
	public static function attach_import_file(string $job_id, string $tmp_path): array {		$state = Status::read($job_id);

		if ($state === array() || ( $state['type'] ?? '' ) !== self::IMPORT) {
			throw new \RuntimeException('Import job not found.');
		}

		if (! empty($state['uploaded'])) {
			throw new \RuntimeException('This import job already has an archive.');
		}

		if (! is_file($tmp_path)) {
			throw new \RuntimeException('The archive to import could not be read.');
		}

		$archive = (string) $state['archive_path'];

		if (is_uploaded_file($tmp_path)) {
			$stored = @move_uploaded_file($tmp_path, $archive);
		} else {
			$stored = @copy($tmp_path, $archive);
		}

		if (! $stored) {
			throw new \RuntimeException('Unable to store the archive for this import job.');
		}

		$state['uploaded'] = true;
		$state['stage']    = 0;
		$state['percent']  = 0;
		$state['message']  = 'Upload complete';

		Status::write($job_id, $state);

		return self::public_state($state, false, self::stages_for(self::IMPORT));
	}

	/**
	 * Append one uploaded chunk to an import job's staging archive. Chunks
	 * carry their byte offset so a retried send is idempotent and gaps are
	 * detected instead of silently corrupting the archive.
	 *
	 * @param string $job_id   Job identifier.
	 * @param string $tmp_path Uploaded chunk file.
	 * @param int    $offset   Byte offset of this chunk (-1 for legacy order).
	 * @return array Public job state.
	 */
	public static function append_import_chunk(string $job_id, string $tmp_path, int $offset = -1): array {
		$state = Status::read($job_id);

		if ($state === array() || ( $state['type'] ?? '' ) !== self::IMPORT) {
			throw new \RuntimeException('Import job not found.');
		}

		if (! empty($state['uploaded'])) {
			throw new \RuntimeException('This import job already has an archive.');
		}

		if (! is_uploaded_file($tmp_path) && ! self::accepts_test_upload($tmp_path)) {
			throw new \RuntimeException('Invalid chunk upload.');
		}

		$archive = (string) $state['archive_path'];
		$have    = is_file($archive) ? (int) filesize($archive) : 0;
		$size    = (int) filesize($tmp_path);

		if ($size <= 0) {
			throw new \RuntimeException('The upload arrived empty. Try again.');
		}

		if ($offset >= 0) {
			if ($offset + $size <= $have) {
				$state['received'] = $have;
				$state['message']  = self::upload_message($have);

				Status::write($job_id, $state);

				return self::public_state($state, false, self::stages_for(self::IMPORT));
			}

			if ($offset !== $have) {
				throw new \RuntimeException('The upload dropped out of sync. Restart the import.');
			}
		}

		$source = fopen($tmp_path, 'rb');
		$target = fopen($archive, 'ab');

		if ($source === false || $target === false) {
			throw new \RuntimeException('Unable to write the archive for this import job.');
		}

		stream_copy_to_stream($source, $target);
		fclose($source);
		fclose($target);

		$have += $size;

		$state['received'] = $have;
		$state['message']  = self::upload_message($have);

		Status::write($job_id, $state);

		return self::public_state($state, false, self::stages_for(self::IMPORT));
	}

	/**
	 * @param int $received Bytes accepted so far.
	 * @return string
	 */
	private static function upload_message(int $received): string {
		return sprintf('Uploading %.2f MB…', $received / 1048576);
	}

	/**
	 * Offline suites may feed plain files as chunks; production never sets the
	 * test flag so chunk sources remain genuine uploads only.
	 *
	 * @param string $path Chunk path.
	 * @return bool
	 */
	private static function accepts_test_upload(string $path): bool {
		return defined('MIGRATEKIT_TESTING') && is_file($path);
	}

	/**
	 * Validate a chunked upload and hand the assembled archive to the job.
	 *
	 * @param string $job_id     Job identifier.
	 * @param int    $total_size Declared archive size in bytes (0 to skip).
	 * @param string $name       Client archive name.
	 * @return array Public job state.
	 */
	public static function finish_import_chunks(string $job_id, int $total_size, string $name): array {
		$state = Status::read($job_id);

		if ($state === array() || ( $state['type'] ?? '' ) !== self::IMPORT) {
			throw new \RuntimeException('Import job not found.');
		}

		if (! empty($state['uploaded'])) {
			throw new \RuntimeException('This import job already has an archive.');
		}

		$archive = (string) $state['archive_path'];

		if (! is_file($archive)) {
			throw new \RuntimeException('No chunks have been uploaded for this job.');
		}

		if ($total_size > 0 && (int) filesize($archive) !== $total_size) {
			throw new \RuntimeException('The upload arrived incomplete. Restart the import.');
		}

		$reader = new Reader($archive);

		if (! $reader->is_valid()) {
			throw new \RuntimeException('The uploaded archive appears corrupted. Restart the import.');
		}

		$state['uploaded']   = true;
		$state['stage']      = 0;
		$state['percent']    = 0;
		$state['final_name'] = basename($name);
		$state['message']    = 'Upload complete';

		Status::write($job_id, $state);

		return self::public_state($state, false, self::stages_for(self::IMPORT));
	}

	/**
	 * Create an import job from a backup already on this server.
	 *
	 * @param string $backup_name Backup file name.
	 * @param array  $options     Import options.
	 * @return array Public job state.
	 */
	public static function start_restore(string $backup_name, array $options = array()): array {
		$repository = new BackupsRepository();
		$backup     = $repository->find($backup_name);

		if ($backup === null) {
			throw new \RuntimeException('Backup not found.');
		}

		$id      = JobId::generate();
		$storage = self::storage_dir($id);
		$archive = $storage . DIRECTORY_SEPARATOR . 'upload.wpress';

		if (! @copy($backup['path'], $archive)) {
			throw new \RuntimeException('Unable to read the backup for restore.');
		}

		$state = array(
			'id'           => $id,
			'type'         => self::IMPORT,
			'archive_path' => $archive,
			'storage_dir'  => $storage,
			'final_name'   => $backup_name,
			'options'      => $options,
			'stage'        => 0,
			'percent'      => 0,
			'message'      => 'Backup queued for restore',
			'created'      => time(),
			'uploaded'     => true,
		);

		Status::write($id, $state);

		return self::public_state($state, false, self::stages_for(self::IMPORT));
	}

	/**
	 * Create a site reset job.
	 *
	 * @param array $options Reset options (see Options::reset()).
	 * @return array Public job state.
	 */
	public static function start_reset(array $options = array()): array {
		$id      = JobId::generate();
		$storage = self::storage_dir($id);

		$state = array(
			'id'          => $id,
			'type'        => self::RESET,
			'storage_dir' => $storage,
			'options'     => $options,
			'user_id'     => get_current_user_id(),
			'stage'       => 0,
			'percent'     => 0,
			'message'     => 'Reset queued',
			'created'     => time(),
		);

		Status::write($id, $state);

		return self::public_state($state, false, self::stages_for(self::RESET));
	}

	/**
	 * Push an existing local backup to a cloud provider.
	 *
	 * @param string $backup_name  Backup file name.
	 * @param string $provider_id  Cloud provider id.
	 * @return array Public job state.
	 */
	public static function start_push(string $backup_name, string $provider_id): array {
		$repository = new BackupsRepository();
		$backup     = $repository->find($backup_name);

		if ($backup === null) {
			throw new \RuntimeException('Backup not found.');
		}

		if (! Manager::valid_remote($backup_name)) {
			throw new \RuntimeException('Invalid backup file name.');
		}

		$provider = Manager::provider($provider_id);

		if ($provider === null) {
			throw new \RuntimeException('Unknown cloud provider: ' . $provider_id);
		}

		if (! $provider->is_ready()) {
			throw new \RuntimeException('The ' . $provider->label() . ' settings are incomplete.');
		}

		$id      = JobId::generate();
		$storage = self::storage_dir($id);

		$state = array(
			'id'          => $id,
			'type'        => self::PUSH,
			'storage_dir' => $storage,
			'cloud'       => $provider_id,
			'remote'      => $backup_name,
			'source'      => (string) $backup['path'],
			'size'        => (int) filesize((string) $backup['path']),
			'options'     => array(),
			'stage'       => 0,
			'percent'     => 0,
			'message'     => 'Push queued',
			'created'     => time(),
		);

		Status::write($id, $state);

		return self::public_state($state, false, self::stages_for(self::PUSH));
	}

	/**
	 * Pull a remote backup from a cloud provider into the backups directory.
	 *
	 * @param string $provider_id Cloud provider id.
	 * @param string $remote_name Remote file name.
	 * @return array Public job state.
	 */
	public static function start_pull(string $provider_id, string $remote_name): array {
		if (! Manager::valid_remote($remote_name)) {
			throw new \RuntimeException('Invalid remote backup name.');
		}

		$provider = Manager::provider($provider_id);

		if ($provider === null) {
			throw new \RuntimeException('Unknown cloud provider: ' . $provider_id);
		}

		if (! $provider->is_ready()) {
			throw new \RuntimeException('The ' . $provider->label() . ' settings are incomplete.');
		}

		$id      = JobId::generate();
		$storage = self::storage_dir($id);

		$state = array(
			'id'          => $id,
			'type'        => self::PULL,
			'storage_dir' => $storage,
			'cloud'       => $provider_id,
			'remote'      => $remote_name,
			'size'        => 0,
			'options'     => array(),
			'stage'       => 0,
			'percent'     => 0,
			'message'     => 'Pull queued',
			'created'     => time(),
		);

		Status::write($id, $state);

		return self::public_state($state, false, self::stages_for(self::PULL));
	}

	/**
	 * Cancel a job and remove its working directory.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public static function cancel(string $job_id): void {
		$state = Status::read($job_id);

		if ($state !== array()) {
			$storage = isset($state['storage_dir']) ? (string) $state['storage_dir'] : '';

			if ($storage !== '' && is_dir($storage) && self::is_inside_storage($storage)) {
				self::delete_directory($storage);
			}

			if (( $state['type'] ?? '' ) === self::IMPORT) {
				Maintenance::stop();
			}
		}

		Status::delete($job_id);
	}

	/**
	 * Read a job's log.
	 *
	 * @param string $job_id Job identifier.
	 * @return string
	 */
	public static function log(string $job_id): string {
		return Logger::read($job_id);
	}

	/**
	 * @param string $job_id Job identifier.
	 * @return array Public job state.
	 */
	public static function status(string $job_id): array {
		$state = Status::read($job_id);

		if ($state === array()) {
			throw new \RuntimeException('Job not found.');
		}

		$type = isset($state['type']) ? (string) $state['type'] : self::EXPORT;

		return self::public_state($state, ! empty($state['done']), self::stages_for($type));
	}

	/**
	 * Move a finished export into the backups directory.
	 *
	 * @param array $state Job state (by reference).
	 * @return void
	 */
	private static function publish_export(array &$state): void {
		if (empty($state['archive_path']) || ! is_file($state['archive_path'])) {
			return;
		}

		Paths::ensure_backups();

		$name   = isset($state['final_name']) ? (string) $state['final_name'] : basename((string) $state['archive_path']);
		$target = rtrim(Paths::backups(), '/\\') . DIRECTORY_SEPARATOR . $name;

		if (realpath((string) $state['archive_path']) !== realpath($target)) {
			if (! @rename((string) $state['archive_path'], $target)) {
				if (! @copy((string) $state['archive_path'], $target)) {
					throw new \RuntimeException('Unable to move the backup into the backups directory.');
				}

				@unlink((string) $state['archive_path']);
			}
		}

		$state['archive_path'] = $target;
		$state['published']    = true;
	}

	/**
	 * @param array $state  Job state.
	 * @param bool  $done   Completion flag.
	 * @param int   $stages Total stage count.
	 * @return array
	 */
	private static function public_state(array $state, bool $done, int $stages): array {
		$percent = isset($state['percent']) ? (int) $state['percent'] : 0;

		if ($done) {
			$percent = 100;
		}

		return array(
			'id'          => isset($state['id']) ? (string) $state['id'] : '',
			'type'        => isset($state['type']) ? (string) $state['type'] : '',
			'stage'       => isset($state['stage']) ? (int) $state['stage'] : 0,
			'stages'      => $stages,
			'percent'     => max(0, min(100, $percent)),
			'message'     => isset($state['message']) ? (string) $state['message'] : '',
			'done'        => $done || ! empty($state['done']),
			'uploaded'    => ! empty($state['uploaded']),
			'received'    => isset($state['received']) ? (int) $state['received'] : 0,
			'archiveName' => isset($state['archive_path']) ? basename((string) $state['archive_path']) : '',
			'cloud'       => isset($state['cloud']) ? (string) $state['cloud'] : '',
			'remote'      => isset($state['remote']) ? (string) $state['remote'] : '',
		);
	}

	private static function is_inside_storage(string $directory): bool {
		$base = rtrim(str_replace('\\', '/', Paths::storage()), '/');
		$path = rtrim(str_replace('\\', '/', $directory), '/');

		return $base !== '' && strpos($path, $base . '/') === 0;
	}

	private static function delete_directory(string $directory): void {
		$items = @scandir($directory);

		if (! is_array($items)) {
			return;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$path = $directory . DIRECTORY_SEPARATOR . $item;

			if (is_dir($path) && ! is_link($path)) {
				self::delete_directory($path);
			} else {
				@unlink($path);
			}
		}

		@rmdir($directory);
	}

	private static function storage_dir(string $id): string {
		$dir = rtrim(Paths::storage(), '/\\') . DIRECTORY_SEPARATOR . $id;

		if (! is_dir($dir) && ! wp_mkdir_p($dir) && ! is_dir($dir)) {
			throw new \RuntimeException('Unable to create the job storage directory.');
		}

		return $dir;
	}

	private static function archive_name(): string {
		$host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
		$host = preg_replace('/[^A-Za-z0-9._-]+/', '-', $host);

		if ($host === null || $host === '') {
			$host = 'site';
		}

		$base = sprintf(
			'%s-%s-%s-%s',
			trim((string) $host, '-'),
			gmdate('Ymd'),
			gmdate('His'),
			substr(bin2hex(random_bytes(6)), 0, 12)
		);

		return substr($base, 0, 247) . '.wpress';
	}
}
