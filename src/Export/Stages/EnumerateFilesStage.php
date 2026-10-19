<?php
/**
 * Enumerates files under a source directory into a manifest.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

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

	/**
	 * @param string   $manifest_key State manifest key.
	 * @param string   $path_key     State path key holding the source root.
	 * @param string   $prefix       Archive path prefix ("" for the content root).
	 * @param string[] $exclude_dirs Directory names to skip at any depth.
	 * @param string[] $exclude_names File names to skip.
	 * @param string   $skip_option  Option that disables this stage entirely.
	 * @param string   $active_only_option Option that keeps only active roots.
	 * @param string   $active_kind        Active-root basis ("plugins"/"themes").
	 */
	public function __construct(
		string $manifest_key,
		string $path_key,
		string $prefix,
		array $exclude_dirs = array(),
		array $exclude_names = array(),
		string $skip_option = '',
		string $active_only_option = '',
		string $active_kind = ''
	) {
		$this->manifest_key       = $manifest_key;
		$this->path_key           = $path_key;
		$this->prefix             = $prefix;
		$this->exclude_dirs       = $exclude_dirs;
		$this->exclude_names      = $exclude_names;
		$this->skip_option        = $skip_option;
		$this->active_only_option = $active_only_option;
		$this->active_kind        = $active_kind;
	}

	public function run(array &$state, PipelineContext $ctx): bool {
		if (isset($state['manifests'][ $this->manifest_key ])) {
			return true;
		}

		if ($this->skip_option !== '' && ! empty($state['options'][ $this->skip_option ])) {
			$state['manifests'][ $this->manifest_key ] = array();
			$state['cursors'][ $this->manifest_key ]   = array(
				'index'   => 0,
				'offset'  => 0,
				'written' => 0,
				'header'  => null,
			);

			return true;
		}

		$root          = rtrim((string) ($state['paths'][ $this->path_key ] ?? ''), '/\\');
		$files         = array();
		$exclude_dirs  = $this->exclude_dirs;

		if ($this->manifest_key === 'content') {
			if (! empty($state['options']['no_cache'])) {
				$exclude_dirs[] = 'cache';
			}

			if (! empty($state['options']['no_muplugins'])) {
				$exclude_dirs[] = 'mu-plugins';
			}
		}

		if ($root !== '' && is_dir($root)) {
			$this->walk($root, $files, $exclude_dirs);
		}

		if ($this->active_only_option !== '' && ! empty($state['options'][ $this->active_only_option ])) {
			$roots = self::active_roots($this->active_kind, $ctx);
			$files = array_values(
				array_filter(
					$files,
					static function (string $file) use ($root, $roots): bool {
						return in_array(substr($file, strlen($root) + 1), $roots, true)
							|| in_array(strtok(substr($file, strlen($root) + 1), '/\\'), $roots, true);
					}
				)
			);
		}

		$state['manifests'][ $this->manifest_key ] = $files;
		$state['cursors'][ $this->manifest_key ]   = array(
			'index'   => 0,
			'offset'  => 0,
			'written' => 0,
			'header'  => null,
		);

		$ctx->report(sprintf('Found %d files for %s', count($files), $this->manifest_key));

		return true;
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

				if ($root !== '') {
					$roots[] = $root;
				}
			}

			foreach ((array) $ctx->site->get('network_plugins', array()) as $plugin) {
				$source = (string) $plugin;
				$root   = preg_replace('#[/\\\\].*$#', '', $source);

				if ($root !== '' && $root !== $source) {
					$roots[] = $root;
				}
			}
		}

		if ($kind === 'themes') {
			foreach (array( 'template', 'stylesheet' ) as $key) {
				$root = (string) $ctx->site->get($key, '');

				if ($root !== '') {
					$roots[] = $root;
				}
			}
		}

		return array_values(array_unique($roots));
	}

	/**
	 * @param string   $dir          Directory to walk.
	 * @param string[] $files        Collected absolute paths (by reference).
	 * @param string[] $exclude_dirs Directory names to skip.
	 * @return void
	 */
	private function walk(string $dir, array &$files, array $exclude_dirs): void {
		$items = @scandir($dir);

		if ($items === false) {
			return;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$absolute = $dir . DIRECTORY_SEPARATOR . $item;

			if (is_dir($absolute)) {
				if (is_link($absolute) || in_array($item, $exclude_dirs, true)) {
					continue;
				}

				$this->walk($absolute, $files, $exclude_dirs);
				continue;
			}

			if (! is_file($absolute) || in_array($item, $this->exclude_names, true)) {
				continue;
			}

			$files[] = $absolute;
		}
	}
}
