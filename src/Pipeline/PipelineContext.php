<?php
/**
 * Runtime services and time budget for a pipeline run.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Pipeline;

use MigrateKit\Database\Driver\DriverInterface;
use MigrateKit\Site\SiteContext;

final class PipelineContext {

	/** @var DriverInterface|null */
	public $db;

	/** @var SiteContext */
	public $site;

	/** @var callable|null */
	private $status;

	/** @var float */
	private $started;

	/** @var float */
	private $time_limit;

	/**
	 * @param DriverInterface|null $db         Database driver, or null when not needed.
	 * @param SiteContext          $site       Site metadata.
	 * @param callable|null        $status     Receives (string $message, int|null $percent).
	 * @param float                $time_limit Seconds per request, or 0 for unlimited.
	 */
	public function __construct(?DriverInterface $db, SiteContext $site, $status = null, float $time_limit = 0.0) {
		$this->db         = $db;
		$this->site       = $site;
		$this->status     = $status;
		$this->started    = microtime(true);
		$this->time_limit = $time_limit;
	}

	/**
	 * Absolute wall-clock deadline, or null when unlimited.
	 *
	 * @return float|null
	 */
	public function deadline(): ?float {
		return $this->time_limit > 0 ? $this->started + $this->time_limit : null;
	}

	public function time_exceeded(): bool {
		$deadline = $this->deadline();

		return $deadline !== null && microtime(true) >= $deadline;
	}

	/**
	 * @param int|null $percent Progress percentage, if known.
	 * @return void
	 */
	public function report(string $message, ?int $percent = null): void {
		if (is_callable($this->status)) {
			call_user_func($this->status, $message, $percent);
		}
	}
}
