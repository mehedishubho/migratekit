<?php
/**
 * Lists files under a source directory into an on-disk manifest, resuming across requests.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export\Stages;

use MigrateKit\Export\Manifest;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;
use MigrateKit\Support\Bytes;

final class EnumerateFilesStage implements StageInterface {

	/** @var string */
	private $manifest_key;

	/** @var string */
	private $path_key;

	/** @var string */
	private $prefix;

	/** @var string[] */
	private $exclude_dirs;

	/** @var string[] */
	private $exclude_names;

	/** @var string */
	private $skip_option;

	/** @var string Option flag that keeps only active plugin/theme roots. */
	private $active_only_option;

	/** @var string Active-root basis: "plugins" or "themes" (empty disables). */
	private $active_kind;

	/** @var string[] Absolute directories skipped wherever they sit under the root. */
	private $exclude_paths;

	/**
	 * @param string   $manifest_key       State key for this manifest's scan progress.
	 * @param string   $path_key           State path key holding the source root.
	 * @param string   $prefix             Archive path prefix ("" for the content root).
	 * @param string[] $exclude_dirs       Directory names to skip at any depth.
	 * @param string[] $exclude_names      File names to skip.
	 * @param string   $skip_option        Option that disables this stage entirely.
	 * @param string   $active_only_option Option that keeps only active roots.
	 * @param string   $active_kind        Active-root basis ("plugins"/"themes").
	 * @param string[] $exclude_paths      Absolute directories to skip wherever they sit.
	 */
	public function __construct(
		string $manifest_key,
		string $path_key,
		string $prefix,
		array $exclude_dirs = array(),
		array $exclude_names = array(),
		string $skip_option = '',
		string $active_only_option = '',
		string $active_kind = '',
		array $exclude_paths = array()
	) {
		$this->manifest_key       = $manifest_key;
		$this->path_key           = $path_key;
		$this->prefix             = $prefix;
		$this->exclude_dirs       = $exclude_dirs;
		$this->exclude_names      = $exclude_names;
		$this->skip_option        = $skip_option;
		$this->active_only_option = $active_only_option;
		$this->active_kind        = $active_kind;
		$this->exclude_paths      = array_map(
			static function (string $path): string {
				return str_replace('\\', '/', rtrim($path, '/\\'));
			},
			$exclude_paths
		);
	}

	public function run(array &$state, PipelineContext $ctx): bool {
		$scan = isset($state['scans'][ $this->manifest_key ]) && is_array($state['scans'][ $this->manifest_key ])
			? $state['scans'][ $this->manifest_key ]
			: null;

		if ($scan !== null && ! empty($scan['done'])) {
			return true;
		}

		$storage = isset($state['storage_dir']) ? rtrim((string) $state['storage_dir'], '/\\') : '';
		$root    = rtrim((string) ( $state['paths'][ $this->path_key ] ?? '' ), '/\\');
		$skip    = ( $this->skip_option !== '' && ! empty($state['options'][ $this->skip_option ] ) )
			|| $root === ''
			|| ! is_dir($root);

		$fresh = $scan === null;

		if ($fresh) {
			$scan = array(
				'dir_offset' => 0,
				'dir_bytes'  => 0,
				'list_bytes' => 0,
				'files'      => 0,
				'bytes'      => 0,
				'done'       => false,
			);

			$state['scans'][ $this->manifest_key ] = $scan;
		}

		if ($skip) {
			$scan['done'] = true;
			$state['scans'][ $this->manifest_key ] = $scan;

			$ctx->report(sprintf('Found 0 files for %s', $this->manifest_key));

			return true;
		}

		if ($storage === '') {
			throw new \RuntimeException('Export state is missing storage_dir.');
		}

		$list  = @fopen(Manifest::path($storage, $this->manifest_key), 'c+b');
		$queue = @fopen(Manifest::queue_path($storage, $this->manifest_key), 'c+b');

		if ($list === false || $queue === false) {
			throw new \RuntimeException('Unable to write the file list for ' . $this->manifest_key . '.');
		}

		if ($fresh) {
			ftruncate($list, 0);
			ftruncate($queue, 0);

			Manifest::append($queue, $root);

			$scan['dir_bytes'] = (int) ftell($queue);
		} else {
			// A request killed mid-scan appends entries its state never recorded; drop them.
			self::trim($list, (int) $scan['list_bytes']);
			self::trim($queue, (int) $scan['dir_bytes']);
		}

		$exclude_dirs = $this->exclude_dirs;

		if ($this->manifest_key === 'content') {
			if (! empty($state['options']['no_cache'])) {
				$exclude_dirs[] = 'cache';
			}

			if (! empty($state['options']['no_muplugins'])) {
				$exclude_dirs[] = 'mu-plugins';
			}
		}

		$roots = $this->active_only_option !== '' && ! empty($state['options'][ $this->active_only_option ])
			? self::active_roots($this->active_kind, $ctx)
			: null;

		$deadline = $ctx->deadline();

		while (true) {
			// scan() appends to the queue, so the next pending directory is always sought by position.
			fseek($queue, (int) $scan['dir_offset']);

			$directory = Manifest::read($queue);

			if ($directory === null) {
				$scan['done'] = true;
				break;
			}

			$scan['dir_offset'] = (int) ftell($queue);

			$this->scan($root, $directory['path'], $queue, $list, $exclude_dirs, $roots, $scan);

			if ($deadline !== null && microtime(true) >= $deadline) {
				break;
			}
		}

		fseek($queue, 0, SEEK_END);

		$scan['dir_bytes']  = (int) ftell($queue);
		$scan['list_bytes'] = (int) ftell($list);

		fclose($list);
		fclose($queue);

		$state['scans'][ $this->manifest_key ] = $scan;

		$ctx->report(
			sprintf('Found %d files for %s (%s)', $scan['files'], $this->manifest_key, Bytes::format((int) $scan['bytes']))
		);

		return ! empty($scan['done']);
	}

	/**
	 * Top-level directories (or exact file names) considered active for the
	 * active-only filter: active plugins (plus network-activated ones) or the
	 * current template/stylesheet themes.
	 *
	 * @param string          $kind Active-root basis.
	 * @param PipelineContext $ctx  Pipeline services.
	 * @return string[]
	 */
	private static function active_roots(string $kind, PipelineContext $ctx): array {
		$roots = array();

		if ($kind === 'plugins') {
			foreach ((array) $ctx->site->get('plugins', array()) as $plugin) {
				$source = (string) $plugin;
				$root   = strchr($source, '/') !== false || strchr($source, '\\') !== false
					? preg_replace('#[/\\\\].*$#', '', $source)
					: $source;

				if ($root !== '' && $root !== null) {
					$roots[] = $root;
				}
			}

			foreach ((array) $ctx->site->get('network_plugins', array()) as $plugin) {
				$source = (string) $plugin;
				$root   = preg_replace('#[/\\\\].*$#', '', $source);

				if ($root !== '' && $root !== null && $root !== $source) {
					$roots[] = $root;
				}
			}
		}

		if ($kind === 'themes') {
			foreach ( array( 'template', 'stylesheet' ) as $key) {
				$root = (string) $ctx->site->get($key, '');

				if ($root !== '') {
					$roots[] = $root;
				}
			}
		}

		return array_values(array_unique($roots));
	}

	/**
	 * Whether a path belongs to one of the active roots: it is one itself, or
	 * its first segment under the source root is.
	 *
	 * @param string   $root     Source root.
	 * @param string   $absolute Absolute path.
	 * @param string[] $roots    Active roots.
	 * @return bool
	 */
	private static function is_active(string $root, string $absolute, array $roots): bool {
		$relative = substr($absolute, strlen($root) + 1);

		return in_array($relative, $roots, true) || in_array(strtok($relative, '/\\'), $roots, true);
	}

	/**
	 * List the files of one directory and queue its subdirectories.
	 *
	 * @param string      $root         Source root.
	 * @param string      $dir          Directory to scan.
	 * @param resource    $queue        Directory queue stream.
	 * @param resource    $list         Manifest stream.
	 * @param string[]    $exclude_dirs Directory names to skip.
	 * @param string[]|null $roots      Active roots, or null when everything is included.
	 * @param array       $scan         Scan progress (by reference).
	 * @return void
	 */
	private function scan(string $root, string $dir, $queue, $list, array $exclude_dirs, ?array $roots, array &$scan): void {
		$items = @scandir($dir);

		if ($items === false) {
			return;
		}

		$subdirs = array();

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$absolute = $dir . DIRECTORY_SEPARATOR . $item;

			if (is_dir($absolute)) {
				if (
					is_link($absolute)
					|| self::excluded($item, $exclude_dirs)
					|| in_array(str_replace('\\', '/', $absolute), $this->exclude_paths, true)
				) {
					continue;
				}

				if ($roots !== null && ! self::is_active($root, $absolute, $roots)) {
					continue;
				}

				$subdirs[] = $absolute;
				continue;
			}

			if (! is_file($absolute) || in_array($item, $this->exclude_names, true)) {
				continue;
			}

			if ($roots !== null && ! self::is_active($root, $absolute, $roots)) {
				continue;
			}

			$size = (int) @filesize($absolute);

			Manifest::append($list, substr($absolute, strlen($root) + 1), $size);

			++$scan['files'];
			$scan['bytes'] += $size;
		}

		fseek($queue, 0, SEEK_END);

		foreach ($subdirs as $subdir) {
			Manifest::append($queue, $subdir);
		}
	}

	/**
	 * Whether a directory name is excluded: an exact match, or a match on a
	 * pattern's literal part when the pattern ends in "*".
	 *
	 * @param string   $name         Directory name.
	 * @param string[] $exclude_dirs Names and name patterns to skip.
	 * @return bool
	 */
	private static function excluded(string $name, array $exclude_dirs): bool {
		foreach ($exclude_dirs as $excluded) {
			$excluded = (string) $excluded;

			if ($excluded === $name) {
				return true;
			}

			if (substr($excluded, -1) === '*' && strpos($name, substr($excluded, 0, -1)) === 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Cut a stream back to a recorded length.
	 *
	 * @param resource $handle Stream.
	 * @param int      $length Length to keep.
	 * @return void
	 */
	private static function trim($handle, int $length): void {
		fseek($handle, 0, SEEK_END);

		if (ftell($handle) > $length) {
			ftruncate($handle, $length);
		}

		fseek($handle, $length);
	}
}
