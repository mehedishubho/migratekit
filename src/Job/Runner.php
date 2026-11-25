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

	public const EXPORT_STAGES = 14;
	public const IMPORT_STAGES = 8;
	public const RESET_STAGES  = 8;
	public const PUSH_STAGES   = 3;
	public const PULL_STAGES   = 3;

	/** Seconds of work per request before the job yields to the next one. */
	private const STEP_SECONDS = 8.0;

	/** Seconds to wait for a concurrent step of the same job before reporting the job unchanged. */
	private const LOCK_WAIT = 2.0;

	/**
	 * Seconds after which an unfinished job is reported as left behind, so a
	 * page load waits for an explicit resume instead of continuing it on sight.
	 *
	 * A job that was idle for days should not start rewriting a site again unasked; a job paused overnight should simply carry on.
	 */
	public const STALE_AFTER = 43200;

	/**
	 * The same for a job that replaces the site. A reloaded page carries such a
	 * job on at once, but one that was walked away from is only ever resumed on request.
	 */
	public const RESTORE_STALE_AFTER = 1800;

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
			'source'        => 'upload',
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
		// A page that reloads or closes drops its connection. The work already in flight should still reach its next saved point.
		ignore_user_abort(true);

		$state = self::read_fresh($job_id);

		if ($state === array()) {
			throw new \RuntimeException('Job not found.');
		}

		if (( $state['type'] ?? '' ) === self::EXPORT && isset($state['manifests'])) {
			throw new \RuntimeException('This backup was started by an earlier version of MigrateKit and cannot be continued. Start a new backup.');
		}

		// Overlapping step requests (a browser retry while the first is still running) must never advance one job twice.
		$lock = self::lock($state);

		if ($lock === null) {
			return array_merge(self::status($job_id), array( 'busy' => true ));
		}

		try {
			$latest = self::read_fresh($job_id);

			if ($latest === array()) {
				throw new \RuntimeException('Job not found.');
			}

			return self::step($job_id, $latest);
		} catch (\Throwable $e) {
			self::record_failure($job_id, $e);

			throw $e;
		} finally {
			self::unlock($lock);
		}
	}

	/**
	 * Keep why the last step failed on the job itself. A page that loads later
	 * finds the job waiting either way; this is what tells "waiting" from "gave up",
	 * so a restore that failed is not simply run again on sight.
	 *
	 * @param string     $job_id Job identifier.
	 * @param \Throwable $error  What the step ended with.
	 * @return void
	 */
	private static function record_failure(string $job_id, \Throwable $error): void {
		$saved = self::read_fresh($job_id);

		if ($saved === array()) {
			return;
		}

		// An engine failure (out of memory, a missing class) describes server paths; that belongs in the log, not on the job.
		$saved['error'] = $error instanceof \Exception
			? $error->getMessage()
			: 'The server stopped this step with an internal error.';

		Status::write($job_id, $saved);
	}

	/**
	 * Run one chunk of a job while holding its lock.
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $state  Job state as stored.
	 * @return array Public job state.
	 */
	private static function step(string $job_id, array $state): array {
		$before = $state;
		$type   = isset($state['type']) ? (string) $state['type'] : self::EXPORT;
		$status = static function (string $message, ?int $percent = null) use (&$state): void {
			$state['message'] = $message;

			// A stage-reported percent is progress *within* that stage; blend it
			// with the stage position so the browser sees continuous movement.
			$total = isset($state['stage_total']) ? (int) $state['stage_total'] : 0;
			$index = isset($state['stage_index']) ? (int) $state['stage_index'] : 0;
			$local = $percent === null ? 0 : max(0, min(100, $percent));
			$global = $total > 0
				? (int) floor((( $index + ( $local / 100 )) / $total) * 100)
				: $local;

			$state['percent'] = max(0, min(100, max($global, (int) ( $state['percent'] ?? 0 ))));

			Logger::append($state, $message);
		};

		// An import or restore replaces the running site: shield it while work is in flight.
		if ($type === self::IMPORT) {
			Maintenance::start();
		}

		$tracked = ActiveJobs::has($job_id);

		if ($type === self::EXPORT) {
			self::assert_resumable($state);
			self::discard_dump($state);
		}

		$context = new PipelineContext(
			Factory::create($GLOBALS['wpdb']),
			WordPressSiteContext::build(),
			$status,
			self::time_budget()
		);

		$factory  = self::pipeline_factory($type);
		$pipeline = $factory();

		try {
			$done = $pipeline->advance($state, $context);
		} catch (\Throwable $e) {
			if (self::cancelled_while_running($job_id, $before, $state)) {
				return self::abandoned($state, $type, $pipeline->stage_count());
			}

			// The import has just replaced the table that held the job's record. It is written again, so the job stays known and can be carried on.
			if (self::took_its_record($before, $state)) {
				Status::write($job_id, $state);
			}

			throw $e;
		}

		// The job was cancelled while this step ran: its progress must not bring it back.
		if (self::cancelled_while_running($job_id, $before, $state)) {
			return self::abandoned($state, $type, $pipeline->stage_count());
		}

		unset($state['error']);

		if ($done && $type === self::EXPORT) {
			self::publish_export($state);
		}

		if ($done && $type === self::PULL && ! empty($state['restore'])) {
			// The backup has arrived: the restore it was asked for gets a job of its own, so it can be picked up like any other.
			$restore_id = (string) ( $state['restore_job'] ?? '' );

			if ($restore_id === '') {
				$restore_id           = JobId::generate();
				$state['restore_job'] = $restore_id;

				// Saved before the job exists, so a request cut off from here on completes the same restore instead of adding a second one.
				Status::write($job_id, $state);
			}

			if (Status::read($restore_id) === array()) {
				self::start_restore(
					(string) $state['remote'],
					isset($state['restore_options']) && is_array($state['restore_options']) ? $state['restore_options'] : array(),
					array( 'cloud' => (string) ( $state['cloud'] ?? '' ), 'tracked' => ActiveJobs::has($job_id) ),
					$restore_id
				);
			}
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

		// Once the step that packed the dump has been saved, its loose copy is only using disk space.
		$saved = Status::write($job_id, $state);

		if ($type === self::IMPORT && ! empty($state['replaced_options'])) {
			// The backup's options have replaced this site's, the registry with them. What the backup knew about jobs belongs to the site it was taken on, so only this job is put back, until it is done.
			ActiveJobs::replace($done || ! $tracked ? array() : array( $job_id ));
		} elseif ($done) {
			ActiveJobs::remove($job_id);
		}

		if ($saved && $type === self::EXPORT) {
			self::discard_dump($state);
		}

		return self::public_state($state, $done, $pipeline->stage_count());
	}

	/**
	 * Whether this step of an import replaced the options table, and with it
	 * the job's own record: the backup's options are the site's from then on.
	 *
	 * @param array $before Job state as the step began.
	 * @param array $state  Job state as the step leaves it.
	 * @return bool
	 */
	private static function took_its_record(array $before, array $state): bool {
		return empty($before['replaced_options']) && ! empty($state['replaced_options']);
	}

	/**
	 * Whether the job was cancelled while a step of it ran. The job's record is
	 * the witness, except where that step took the record itself: a missing
	 * record means nothing there, so the job's working folder stands in, since
	 * cancelling removes that.
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $before Job state as the step began.
	 * @param array  $state  Job state as the step leaves it.
	 * @return bool
	 */
	private static function cancelled_while_running(string $job_id, array $before, array $state): bool {
		if (self::took_its_record($before, $state)) {
			return ! is_dir((string) ( $state['storage_dir'] ?? '' ));
		}

		return Status::read($job_id) === array();
	}

	/**
	 * The reply for a step whose job was cancelled while it ran: nothing of
	 * the step is kept, and an upload it opened meanwhile is discarded.
	 *
	 * @param array  $state  Job state as this step knew it.
	 * @param string $type   Job type constant.
	 * @param int    $stages Stage count of the pipeline.
	 * @return array Public job state.
	 */
	private static function abandoned(array $state, string $type, int $stages): array {
		if ($type === self::PUSH) {
			self::discard_upload($state);
		}

		$state['message'] = 'Cancelled';
		$state['percent'] = 0;

		return array_merge(self::public_state($state, false, $stages), array( 'done' => true, 'cancelled' => true ));
	}

	/**
	 * Job state straight from storage.
	 *
	 * Another request may have advanced the job while this one waited for its
	 * lock, and that write is not in this request's object cache.
	 *
	 * @param string $job_id Job identifier.
	 * @return array
	 */
	private static function read_fresh(string $job_id): array {
		if (function_exists('wp_cache_delete')) {
			wp_cache_delete(Status::OPTION_PREFIX . $job_id, 'options');
		}

		return Status::read($job_id);
	}

	/**
	 * Seconds of work per request: the standard slice, or less when the host's
	 * execution limit is too short to fit it.
	 *
	 * @return float
	 */
	private static function time_budget(): float {
		$limit  = (int) @ini_get('max_execution_time');
		$budget = $limit <= 0 ? self::STEP_SECONDS : max(1.0, min(self::STEP_SECONDS, $limit * 0.5));

		/**
		 * Seconds of work a step may take before the job yields to the next request.
		 *
		 * @param float $budget The standard slice.
		 */
		$budget = (float) apply_filters('migratekit_step_seconds', $budget);

		return max(0.001, $budget);
	}

	/**
	 * Refuse an export whose built archive is gone. Storage cleanup removes the
	 * working files of a backup that waited too long to be resumed.
	 *
	 * @param array $state Job state as stored.
	 * @return void
	 */
	private static function assert_resumable(array $state): void {
		if (! empty($state['done']) || empty($state['archive_offset']) || empty($state['archive_path']) || is_file((string) $state['archive_path'])) {
			return;
		}

		throw new \RuntimeException('The partially built backup is no longer on this server, so it cannot be continued. Start a new backup.');
	}

	/**
	 * Delete the loose database dump once the step that packed it into the
	 * archive has been saved.
	 *
	 * @param array $state Job state as stored.
	 * @return void
	 */
	private static function discard_dump(array $state): void {
		if (empty($state['database_added']) || empty($state['database_file']) || ! is_file($state['database_file'])) {
			return;
		}

		@unlink($state['database_file']);
	}

	/**
	 * Take the job's exclusive lock, briefly waiting for a concurrent step to finish.
	 *
	 * @param array $state Job state as stored.
	 * @return resource|bool|null The lock to release, true when there is nothing to lock, or null while another request holds it.
	 */
	private static function lock(array $state) {
		$directory = isset($state['storage_dir']) ? (string) $state['storage_dir'] : '';

		if ($directory === '') {
			return true;
		}

		$handle = @fopen($directory . DIRECTORY_SEPARATOR . 'lock', 'c');

		if ($handle === false) {
			return true;
		}

		$waited = 0.0;

		while (true) {
			$would_block = 0;

			if (@flock($handle, LOCK_EX | LOCK_NB, $would_block)) {
				return $handle;
			}

			// Without $would_block the failure is not contention: locking is unsupported here, so carry on without it.
			if ($would_block === 0) {
				return $handle;
			}

			if ($waited >= self::LOCK_WAIT) {
				fclose($handle);

				return null;
			}

			usleep(200000);
			$waited += 0.2;
		}
	}

	/**
	 * @param resource|bool|null $lock Lock returned by lock().
	 * @return void
	 */
	private static function unlock($lock): void {
		if (is_resource($lock)) {
			@flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/**
	 * Reserve an import job before its archive is uploaded.
	 *
	 * @param array  $options Import options.
	 * @param string $name    File name the browser will upload.
	 * @param int    $size    Size it announces for that file, 0 when unknown.
	 * @return array Public job state.
	 */
	public static function create_import_job(array $options = array(), string $name = '', int $size = 0): array {
		$id      = JobId::generate();
		$storage = self::storage_dir($id);

		$state = array(
			'id'            => $id,
			'type'          => self::IMPORT,
			'archive_path'  => $storage . DIRECTORY_SEPARATOR . 'upload.wpress',
			'storage_dir'   => $storage,
			'final_name'    => $name !== '' ? basename($name) : '',
			'source'        => 'upload',
			'options'       => $options,
			'stage'         => 0,
			'percent'       => 0,
			'message'       => 'Waiting for upload',
			'created'       => time(),
			'received'      => 0,
			'expected_size' => max(0, $size),
			'uploaded'      => false,
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
	 * @param array  $context     'source' names where the archive came from ('backup', 'cloud'); 'tracked' lists the job for the admin pages.
	 * @param string $job_id      Identifier to give the job, when it was reserved by the job that fetched the archive.
	 * @return array Public job state.
	 */
	public static function start_restore(string $backup_name, array $options = array(), array $context = array(), string $job_id = ''): array {
		$repository = new BackupsRepository();
		$backup     = $repository->find($backup_name);

		if ($backup === null) {
			throw new \RuntimeException('Backup not found.');
		}

		$id      = JobId::is_valid($job_id) ? $job_id : JobId::generate();
		$storage = self::storage_dir($id);
		$archive = $storage . DIRECTORY_SEPARATOR . 'upload.wpress';

		// A second name for the same file beats a copy of it wherever the file system allows; the archive is only ever read here.
		if ( ! @link($backup['path'], $archive) && ! @copy($backup['path'], $archive)) {
			throw new \RuntimeException('Unable to read the backup for restore.');
		}

		$tracked = ! empty($context['tracked']);
		$cloud   = isset($context['cloud']) ? (string) $context['cloud'] : '';

		$state = array(
			'id'           => $id,
			'type'         => self::IMPORT,
			'archive_path' => $archive,
			'storage_dir'  => $storage,
			'final_name'   => $backup_name,
			'source'       => $cloud !== '' ? 'cloud' : 'backup',
			'cloud'        => $cloud,
			'remote'       => $cloud !== '' ? $backup_name : '',
			'options'      => $options,
			'stage'        => 0,
			'percent'      => 0,
			'message'      => 'Backup queued for restore',
			'created'      => time(),
			'uploaded'     => true,
		);

		Status::write($id, $state);

		if ($tracked) {
			ActiveJobs::add($id);
		}

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
	 * @param string $provider_id     Cloud provider id.
	 * @param string $remote_name     Remote file name.
	 * @param bool   $restore         Whether the backup is restored on this site once it has arrived.
	 * @param array  $restore_options Import options for that restore.
	 * @return array Public job state.
	 */
	public static function start_pull(string $provider_id, string $remote_name, bool $restore = false, array $restore_options = array()): array {
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
			'id'              => $id,
			'type'            => self::PULL,
			'storage_dir'     => $storage,
			'cloud'           => $provider_id,
			'remote'          => $remote_name,
			'size'            => 0,
			'options'         => array(),
			'stage'           => 0,
			'percent'         => 0,
			'message'         => 'Pull queued',
			'created'         => time(),
			'restore'         => $restore,
			'restore_options' => $restore ? $restore_options : array(),
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
		ActiveJobs::remove($job_id);

		$state = Status::read($job_id);

		if ($state !== array()) {
			// A step still running would save its progress over the cancellation, so give it a moment to end first.
			self::unlock(self::lock($state));
			$state = Status::read($job_id);

			if (( $state['type'] ?? '' ) === self::PUSH && empty($state['done'])) {
				self::discard_upload($state);
			}
		}

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
	 * Free what an abandoned push already put on the remote, which a listing there does not show.
	 *
	 * @param array $state Job state as stored.
	 * @return void
	 */
	private static function discard_upload(array $state): void {
		if (empty($state['session']) || ! is_array($state['session'])) {
			return;
		}

		$provider = Manager::provider((string) ( $state['cloud'] ?? '' ));

		if ($provider === null) {
			return;
		}

		try {
			$provider->upload_abort($state['session']);
		} catch (\Throwable $e) {
			// Storage on the remote is all that is at stake, and the provider may keep it until its own expiry.
		}
	}

	/**
	 * Jobs the admin pages have not finished, oldest first. Entries whose job
	 * is missing or finished are dropped.
	 *
	 * @return array[] Public job states, each with a `stale` flag.
	 */
	public static function open_jobs(): array {
		$open = array();
		$now  = time();

		foreach (ActiveJobs::ids() as $id) {
			$state = Status::read($id);

			if ($state === array() || ! empty($state['done'])) {
				ActiveJobs::remove($id);
				continue;
			}

			$type    = isset($state['type']) ? (string) $state['type'] : self::EXPORT;
			$public  = self::public_state($state, false, self::stages_for($type));
			$touched = (int) ( $state['updated'] ?? ( $state['created'] ?? $now ) );
			$limit   = $type === self::IMPORT ? self::RESTORE_STALE_AFTER : self::STALE_AFTER;

			$public['stale'] = ( $now - $touched ) > $limit;

			$open[] = $public;
		}

		return $open;
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

		$label  = '';
		$origin = isset($state['source']) ? (string) $state['source'] : '';

		if (! empty($state['cloud'])) {
			$provider = Manager::provider((string) $state['cloud']);
			$label    = $provider === null ? '' : $provider->label();
		}

		// A push keeps the path of the file it sends; only where an archive came from is anyone's business outside the job.
		if ( ! in_array($origin, array( 'upload', 'backup', 'cloud' ), true )) {
			$origin = '';
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
			'cloudLabel'  => $label,
			'remote'      => isset($state['remote']) ? (string) $state['remote'] : '',
			'backupName'  => isset($state['final_name']) ? (string) $state['final_name'] : '',
			'source'      => $origin,
			'restore'     => ! empty($state['restore']),
			'restoreJob'  => isset($state['restore_job']) ? (string) $state['restore_job'] : '',
			'total'       => isset($state['expected_size']) ? (int) $state['expected_size'] : 0,
			// The time of the last change kept by the server; a job that has just been made has only its creation so far.
			'updatedAt'   => isset($state['updated']) ? (int) $state['updated'] : (int) ( $state['created'] ?? 0 ),
			'error'       => isset($state['error']) && is_string($state['error']) ? $state['error'] : '',
			'busy'        => false,
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
