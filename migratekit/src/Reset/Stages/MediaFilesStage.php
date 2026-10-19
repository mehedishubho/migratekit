<?php
/**
 * Deletes media files from the uploads directory.
 *
 * Opt-in: `delete_media_files`. Attachment rows already went with the post
 * reset; this stage removes the physical files. Directory structure is
 * recreated by WordPress on demand, so empty directories are pruned last,
 * deepest first. The stage keeps a per-directory cursor in the job state so
 * a request budget never repeats work.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Reset\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class MediaFilesStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (empty($state['options']['delete_media_files'])) {
			$ctx->report('Media files kept');

			return true;
		}

		$uploads = (string) $ctx->site->get('uploads_path');

		if ($uploads === '' || ! is_dir($uploads)) {
			$ctx->report('Media files kept (uploads directory missing)');

			return true;
		}

		$pending = isset($state['media_pending']) && is_array($state['media_pending'])
			? array_map(array( $this, 'normalise' ), $state['media_pending'])
			: array( $this->normalise($uploads) );

		$removed  = isset($state['media_removed']) ? (int) $state['media_removed'] : 0;
		$emptied  = isset($state['media_emptied']) && is_array($state['media_emptied'])
			? $state['media_emptied']
			: array();

		while ($pending !== array()) {
			$directory = (string) array_pop($pending);
			$items     = @scandir($directory);

			if ($items === false) {
				continue;
			}

			foreach ($items as $item) {
				if ($item === '.' || $item === '..') {
					continue;
				}

				$entry = $directory . '/' . $item;

				if (is_dir($entry) && ! is_link($entry)) {
					$pending[] = $entry;
				} elseif (is_file($entry)) {
					@unlink($entry);
					++$removed;
				}
			}

			$emptied[] = $directory;

			if (! $ctx->time_exceeded()) {
				continue;
			}

			$state['media_pending'] = $pending;
			$state['media_removed'] = $removed;
			$state['media_emptied'] = $emptied;

			return false;
		}

		// Two sweeps: children have been visited later, so sorting deepest
		// first prunes leaf directories before their parents.
		usort($emptied, array( $this, 'deepest_first' ));

		foreach ($emptied as $directory) {
			@rmdir((string) $directory);
		}

		unset($state['media_pending'], $state['media_removed'], $state['media_emptied']);

		$ctx->report(sprintf('Uploads cleared (%d files removed)', $removed));

		return true;
	}

	/**
	 * Sort callback: deeper paths first so children are pruned before parents.
	 *
	 * @param string $a Path.
	 * @param string $b Path.
	 * @return int
	 */
	private static function deepest_first(string $a, string $b): int {
		return substr_count($b, '/') <=> substr_count($a, '/');
	}

	/**
	 * Absolute path with forward separators.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	private function normalise(string $path): string {
		return rtrim(str_replace('\\', '/', $path), '/');
	}
}
