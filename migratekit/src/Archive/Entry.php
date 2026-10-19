<?php
/**
 * A single record inside a .wpress archive.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Archive;

final class Entry {

	/** @var string */
	private $name;

	/** @var string */
	private $path;

	/** @var int */
	private $size;

	/** @var int */
	private $mtime;

	/** @var string */
	private $crc;

	/** @var int */
	private $block_offset;

	/** @var int */
	private $data_offset;

	public function __construct(
		string $name,
		string $path,
		int $size,
		int $mtime,
		string $crc,
		int $block_offset,
		int $data_offset
	) {
		$this->name         = $name;
		$this->path         = $path;
		$this->size         = $size;
		$this->mtime        = $mtime;
		$this->crc          = $crc;
		$this->block_offset = $block_offset;
		$this->data_offset  = $data_offset;
	}

	public function name(): string {
		return $this->name;
	}

	public function path(): string {
		return $this->path;
	}

	/**
	 * Archive-relative path, always "/" separated (e.g. "uploads/2024/a.jpg").
	 *
	 * @return string
	 */
	public function relative_path(): string {
		if ($this->path === '' || $this->path === '.') {
			return $this->name;
		}

		return $this->path . '/' . $this->name;
	}

	/**
	 * Archived byte count (post compression/encryption), i.e. bytes to skip.
	 *
	 * @return int
	 */
	public function size(): int {
		return $this->size;
	}

	public function mtime(): int {
		return $this->mtime;
	}

	/**
	 * Per-record CRC32 of the original (plaintext) content, or "" when absent.
	 *
	 * @return string
	 */
	public function crc(): string {
		return $this->crc;
	}

	public function block_offset(): int {
		return $this->block_offset;
	}

	public function data_offset(): int {
		return $this->data_offset;
	}

	/**
	 * Whether this record is stored raw (config files are never transformed).
	 *
	 * @return bool
	 */
	public function is_raw(): bool {
		return in_array($this->relative_path(), Header::UNPROCESSED, true);
	}
}
