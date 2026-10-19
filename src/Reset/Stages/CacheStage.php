<?php
/**
 * Flushes object caches so removed rows stop appearing in this request.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Reset\Stages;

use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class CacheStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}

		// Attachment-heavy sites keep register metadata in global objects; a
		// fresh object cache is enough — no rewrite rewrite or network flush
		// is needed because the options stage dropped rewrite_rules.
		$ctx->report('Caches flushed');

		return true;
	}
}
