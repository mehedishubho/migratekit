<?php
/**
 * Imports a SQL dump into the destination database.
 *
 * Rewrites the archive placeholder prefix to the live table prefix, applies
 * URL/path replacement to string literals (serialization-safe), and retries
 * CREATE TABLE statements with portability normalization when needed.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Database;

use MigrateKit\Database\Driver\DriverInterface;
use MigrateKit\Database\Sql\IdentifierMapper;
use MigrateKit\Database\Sql\LiteralRewriter;
use MigrateKit\Database\Sql\Splitter;

final class Importer {

	/** @var DriverInterface */
	private $db;

	/** @var string */
	private $real_prefix;

	/** @var string */
	private $placeholder;

	/** @var ReplaceEngine|null */
	private $replace;

	/** @var array<int,array{0:string,1:string}> Ordered placeholder -> prefix pairs, longest placeholder first. */
	private $maps = array();

	public function __construct(
		DriverInterface $db,
		string $real_prefix,
		string $placeholder = 'SERVMASK_PREFIX_',
		?ReplaceEngine $replace = null
	) {
		$this->db = $db;
		$this->real_prefix = $real_prefix;
		$this->placeholder = $placeholder;
		$this->replace = $replace;
	}

	/**
	 * Register placeholder table-name mappings used while importing.
	 *
	 * Multisite dumps name subsite tables `PLACEHOLDER_{blog_id}_posts`; each
	 * mapping rewrites one placeholder family to its destination prefix. The
	 * bare archive prefix (base prefix) can be registered with
	 * set_base_mapping() and is applied LAST because the numbered placeholders
	 * contain it as their own prefix.
	 *
	 * @param array<int,array{placeholder:string,real:string}> $mappings Mapping list.
	 * @return void
	 */
	public function add_mappings(array $mappings): void {
		foreach ($mappings as $mapping) {
			if (! isset($mapping['placeholder'], $mapping['real'])) {
				continue;
			}

			$this->maps[] = array( (string) $mapping['placeholder'], (string) $mapping['real'] );
		}

		usort($this->maps, static function (array $a, array $b): int {
			return strlen($b[0]) <=> strlen($a[0]);
		});
	}

	/**
	 * Set the base placeholder mapping, applied after every numbered one.
	 *
	 * @param string $placeholder Archive placeholder.
	 * @param string $real_prefix Destination prefix.
	 * @return void
	 */
	public function set_base_mapping(string $placeholder, string $real_prefix): void {
		$this->placeholder = $placeholder;
		$this->real_prefix = $real_prefix;
	}

	/**
	 * Import a dump file, streaming statements.
	 *
	 * @param string $path SQL file path.
	 * @return void
	 */
	public function import_file(string $path): void {
		Splitter::each_file($path, function (string $statement): void {
			$this->import_statement($statement);
		});
	}

	/**
	 * Import a SQL string.
	 *
	 * @param string $sql SQL text.
	 * @return void
	 */
	public function import_sql(string $sql): void {
		foreach (Splitter::split($sql) as $statement) {
			$this->import_statement($statement);
		}
	}

	/**
	 * Import one statement.
	 *
	 * @param string $statement SQL statement.
	 * @return void
	 */
	public function import_statement(string $statement): void {
		$statement = trim($statement);

		if ($statement === '') {
			return;
		}

		foreach ($this->maps as $map) {
			if ($map[0] !== '' && strpos($statement, $map[0]) !== false) {
				$statement = IdentifierMapper::map_prefix($statement, $map[0], $map[1]);
			}
		}

		$statement = IdentifierMapper::map_prefix($statement, $this->placeholder, $this->real_prefix);

		if ($this->replace !== null && ! $this->replace->is_empty()) {
			$statement = LiteralRewriter::rewrite($statement, $this->replace, $this->db);
		}

		$result = $this->db->query($statement);

		if ($result === false && CreateTableNormalizer::is_create_table($statement)) {
			$result = $this->db->query(CreateTableNormalizer::normalize($statement));
		}

		if ($result === false) {
			throw new \RuntimeException(
				'Database import failed: ' . $this->db->last_error()
				. ' while executing: ' . substr($statement, 0, 240)
			);
		}
	}
}
