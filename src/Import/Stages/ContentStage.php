<?php
/**
 * Extracts archive files to the destination site, resuming across requests.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Import\Stages;

use MigrateKit\Archive\Reader;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;
use MigrateKit\Support\PathGuard;

final class ContentStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (! empty($state['content_done'])) {
			return true;
		}

		$reader  = new Reader((string) $state['archive_path'], $state['compression'], $state['password']);
		$entries = $reader->entries();
		$total   = count($entries);

		$index    = isset($state['content_index']) ? (int) $state['content_index'] : 0;
		$deadline = $ctx->deadline();

		while ($index < $total) {
			$entry    = $entries[ $index ];
			$relative = $entry->relative_path();

			$destination = $this->destination($relative, $state);

			if ($destination !== null) {
				$reader->extract($entry, $destination);
			}

			++$index;
			$state['content_index'] = $index;

			$ctx->report(sprintf('Extracting files (%d/%d)', $index, $total), $total > 0 ? (int) floor($index * 100 / $total) : 100);

			if ($deadline !== null && microtime(true) >= $deadline && $index < $total) {
				return false;
			}
		}

		$state['content_done'] = true;

		return true;
	}

	/**
	 * Map an archive path to a destination file, or null to skip it.
	 *
	 * @param string $relative Archive-relative path.
	 * @param array  $state    Pipeline state.
	 * @return string|null
	 */
	private function destination(string $relative, array $state): ?string {
		$relative = ltrim(str_replace('\\', '/', $relative), '/');

		if ($relative === '' || strpos($relative, '..') !== false) {
			return null;
		}

		if (in_array($relative, array( 'package.json', 'multisite.json', 'database.sql' ), true)) {
			return null;
		}

		$prefixes = array(
			'plugins/' => (string) ( $state['paths']['plugins_path'] ?? '' ),
			'themes/'  => (string) ( $state['paths']['themes_path'] ?? '' ),
			'uploads/' => (string) ( $state['paths']['uploads_path'] ?? '' ),
		);

		foreach ($prefixes as $prefix => $base) {
			if (strpos($relative, $prefix) === 0) {
				return PathGuard::join($base, substr($relative, strlen($prefix)));
			}
		}

		return PathGuard::join((string) ( $state['paths']['content_path'] ?? '' ), $relative);
	}
}
