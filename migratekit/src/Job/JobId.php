<?php
/**
 * Job identifier generation.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Job;

final class JobId {

	/** Matches the identifiers accepted by the REST routes. */
	public const PATTERN = '/^[a-f0-9]{13,40}$/';

	private function __construct() {
	}

	public static function generate(): string {
		return bin2hex(random_bytes(8));
	}

	public static function is_valid(string $id): bool {
		return preg_match(self::PATTERN, $id) === 1;
	}
}
