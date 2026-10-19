<?php
/**
 * Backups directory repository.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Storage;

use MigrateKit\Support\OptionStore;
use MigrateKit\Support\Paths;

final class BackupsRepository {

	public const NAME_PATTERN  = '/^[A-Za-z0-9._-]{1,247}\.wpress$/';
	public const LABELS_OPTION = 'migratekit_backup_labels';

	/** @var string */
	private $path;

	public function __construct(?string $path = null) {
		$this->path = rtrim($path !== null && $path !== '' ? $path : Paths::backups(), '/\\');
	}

	public function path(): string {
		return $this->path;
	}

	/**
	 * Human labels keyed by archive name.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		$labels = OptionStore::get(self::LABELS_OPTION, array());

		if (! is_array($labels)) {
			return array();
		}

		$labels = array_filter($labels, static function ($value, $key): bool {
			return is_string($key) && BackupsRepository::validate_name($key) && is_string($value) && $value !== '';
		}, ARRAY_FILTER_USE_BOTH);

		return is_array($labels) ? $labels : array();
	}

	/**
	 * @param string $name Archive name.
	 * @return string
	 */
	public static function label_for(string $name): string {
		$labels = self::labels();

		return isset($labels[ $name ]) ? $labels[ $name ] : '';
	}

	/**
	 * Set, change or clear (empty string) an archive label.
	 *
	 * @param string   $name  Archive name.
	 * @param string   $label New label (max 255 chars).
	 * @param ?string  $path  Optional backups directory (defaults to the configured one).
	 * @return bool False when the archive does not exist.
	 */
	public static function set_label(string $name, string $label, ?string $path = null): bool {
		if (! self::validate_name($name)) {
			return false;
		}

		$repository = new self($path);

		if ($repository->find($name) === null) {
			return false;
		}

		$labels         = self::labels();
		$stripped       = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($label) : strip_tags($label);
		$clean          = substr(trim((string) $stripped), 0, 255);
		$labels[ $name ] = $clean;

		if ($clean === '') {
			unset($labels[ $name ]);
		}

		OptionStore::put(self::LABELS_OPTION, $labels);

		return true;
	}

	/**
	 * Validate a backup file name (no directories, .wpress only).
	 *
	 * @param string $name Backup file name.
	 * @return bool
	 */
	public static function validate_name(string $name): bool {
		return preg_match(self::NAME_PATTERN, $name) === 1;
	}

	/**
	 * List backups, newest first.
	 *
	 * @return array<int,array{name:string,path:string,size:int,mtime:int}>
	 */
	public function list(): array {
		if (! is_dir($this->path)) {
			return array();
		}

		$matches = glob($this->path . '/*.wpress');
		$backups = array();

		if (! is_array($matches)) {
			return array();
		}

		foreach ($matches as $file) {
			$name = basename($file);

			if (! self::validate_name($name) || ! is_file($file)) {
				continue;
			}

			$backups[] = array(
				'name'  => $name,
				'path'  => $file,
				'size'  => (int) filesize($file),
				'mtime' => (int) filemtime($file),
			);
		}

		usort(
			$backups,
			static function (array $left, array $right): int {
				return $right['mtime'] <=> $left['mtime'];
			}
		);

		return $backups;
	}

	public function count(): int {
		return count($this->list());
	}

	/**
	 * Resolve a backup file by name.
	 *
	 * @param string $name Backup file name.
	 * @return array{name:string,path:string,size:int,mtime:int}|null
	 */
	public function find(string $name): ?array {
		if (! self::validate_name($name)) {
			return null;
		}

		foreach ($this->list() as $backup) {
			if ($backup['name'] === $name) {
				return $backup;
			}
		}

		return null;
	}

	/**
	 * Delete a backup by name.
	 *
	 * @param string $name Backup file name.
	 * @return bool True when a file was removed.
	 */
	public function delete(string $name): bool {
		$backup = $this->find($name);

		if ($backup === null) {
			return false;
		}

		$labels = self::labels();

		if (isset($labels[ $name ])) {
			unset($labels[ $name ]);
			OptionStore::put(self::LABELS_OPTION, $labels);
		}

		return @unlink($backup['path']) === true;
	}

	/**
	 * Build a safe destination path for a new backup.
	 *
	 * @param string $name Backup file name.
	 * @return string|null
	 */
	public function destination(string $name): ?string {
		if (! self::validate_name($name)) {
			return null;
		}

		return $this->path . DIRECTORY_SEPARATOR . $name;
	}
}
