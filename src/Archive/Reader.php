<?php
/**
 * Streaming .wpress archive reader.
 *
 * Scans records linearly from offset 0 (there is no index), tolerates the v1
 * all-NUL and v2 CRC end-of-archive sentinels, and reverses per-chunk
 * compression/encryption using the archive-wide settings from package.json.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Archive;

final class Reader {

	public const CHUNK_SIZE = 512000;
	public const CHUNK_OVERHEAD = 32;

	/** @var string */
	private $path;

	/** @var string|null */
	private $compression;

	/** @var Encryptor|null */
	private $encryptor;

	/** @var Entry[]|null */
	private $entries = null;

	/**
	 * @param string      $path        Archive path.
	 * @param string|null $compression "gzip", "bzip2" or null.
	 * @param string|null $password    Encryption password, or null.
	 */
	public function __construct(string $path, ?string $compression = null, ?string $password = null) {
		if (! is_file($path)) {
			throw new \RuntimeException('Archive not found: ' . $path);
		}

		if ($compression === '') {
			$compression = null;
		}

		if ($password === '') {
			$password = null;
		}

		$this->path        = $path;
		$this->compression = $compression;
		$this->encryptor   = $password !== null ? new Encryptor($password) : null;
	}

	/**
	 * Whether the file carries a well-formed end-of-archive sentinel.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		$size = $this->file_size();

		if ($size < Header::LENGTH) {
			return false;
		}

		$handle = fopen($this->path, 'rb');

		if ($handle === false) {
			return false;
		}

		fseek($handle, $size - Header::LENGTH);
		$block = fread($handle, Header::LENGTH);
		fclose($handle);

		return is_string($block) && strlen($block) === Header::LENGTH && Header::is_eof($block);
	}

	/**
	 * All data records in archive order.
	 *
	 * @return Entry[]
	 */
	public function entries(): array {
		if ($this->entries !== null) {
			return $this->entries;
		}

		$size = $this->file_size();
		$handle = fopen($this->path, 'rb');

		if ($handle === false) {
			throw new \RuntimeException('Unable to read archive: ' . $this->path);
		}

		$offset  = 0;
		$entries = array();

		while ($offset + Header::LENGTH <= $size) {
			fseek($handle, $offset);
			$block = fread($handle, Header::LENGTH);

			if (! is_string($block) || strlen($block) < Header::LENGTH) {
				break;
			}

			if (Header::is_eof($block)) {
				$offset += Header::LENGTH;
				continue;
			}

			$fields = Header::parse($block);

			if ($fields['name'] === '') {
				break;
			}

			$record_size = (int) $fields['size'];

			$entries[] = new Entry(
				$fields['name'],
				$fields['path'],
				$record_size,
				(int) $fields['mtime'],
				$fields['crc'],
				$offset,
				$offset + Header::LENGTH
			);

			$offset += Header::LENGTH + $record_size;
		}

		fclose($handle);

		$this->entries = $entries;

		return $entries;
	}

	/**
	 * Find a record by its archive-relative path.
	 *
	 * @param string $relative_path e.g. "package.json" or "uploads/a.jpg".
	 * @return Entry|null
	 */
	public function find(string $relative_path): ?Entry {
		foreach ($this->entries() as $entry) {
			if ($entry->relative_path() === $relative_path) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * The archive-wide CRC32 stored in a v2 sentinel, or null for v1 archives.
	 *
	 * @return string|null 8 hex chars.
	 */
	public function archive_crc(): ?string {
		$size = $this->file_size();

		if ($size < Header::LENGTH) {
			return null;
		}

		$handle = fopen($this->path, 'rb');

		if ($handle === false) {
			return null;
		}

		fseek($handle, $size - Header::LENGTH);
		$block = fread($handle, Header::LENGTH);
		fclose($handle);

		if (! is_string($block) || ! Header::is_eof_v2($block)) {
			return null;
		}

		$fields = unpack('a255/a14size/a4100/a8crc32', $block);

		return is_array($fields) ? trim((string) $fields['crc32'], "\0 ") : null;
	}

	/**
	 * Recompute and verify the archive-wide CRC32.
	 *
	 * @return bool True for v1 archives (nothing to verify) or on a match.
	 */
	public function verify_archive_crc(): bool {
		$stored = $this->archive_crc();

		if ($stored === null) {
			return true;
		}

		$size      = $this->file_size();
		$eof_start = $size - Header::LENGTH;

		return hash_equals($stored, Crc32::of_file($this->path, 0, $eof_start));
	}

	/**
	 * Read one record into memory.
	 *
	 * @param Entry $entry Record to read.
	 * @return string Plaintext contents.
	 */
	public function read(Entry $entry): string {
		$handle = fopen($this->path, 'rb');

		if ($handle === false) {
			throw new \RuntimeException('Unable to read archive: ' . $this->path);
		}

		fseek($handle, $entry->data_offset());

		$contents = $this->read_payload($handle, $entry);

		fclose($handle);

		return $contents;
	}

	/**
	 * Extract one record to a destination file, streaming and preserving mtime.
	 *
	 * @param Entry  $entry     Record to extract.
	 * @param string $dest_path Destination file path.
	 * @return void
	 */
	public function extract(Entry $entry, string $dest_path): void {
		$dir = dirname($dest_path);

		if (! is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		$source = fopen($this->path, 'rb');

		if ($source === false) {
			throw new \RuntimeException('Unable to read archive: ' . $this->path);
		}

		fseek($source, $entry->data_offset());

		$dest = fopen($dest_path, 'wb');

		if ($dest === false) {
			fclose($source);
			throw new \RuntimeException('Unable to write extracted file: ' . $dest_path);
		}

		fwrite($dest, $this->read_payload($source, $entry));

		fclose($dest);
		fclose($source);

		if ($entry->mtime() > 0) {
			@touch($dest_path, $entry->mtime());
		}
	}

	/**
	 * Reverse the per-chunk transforms from an opened archive stream.
	 *
	 * @param resource $handle Archive stream positioned at the record data.
	 * @param Entry    $entry  Record being read.
	 * @return string Plaintext contents.
	 */
	private function read_payload($handle, Entry $entry): string {
		if ($entry->is_raw()) {
			return $this->read_exactly($handle, $entry->size());
		}

		if ($this->compression !== null) {
			return $this->read_compressed($handle, $entry->size());
		}

		return $this->read_encrypted($handle, $entry->size());
	}

	/**
	 * Read a compressed (and possibly encrypted) record.
	 *
	 * @param resource $handle    Archive stream.
	 * @param int      $size      Archived byte count.
	 * @return string
	 */
	private function read_compressed($handle, int $size): string {
		$out       = '';
		$remaining = $size;

		while ($remaining > 0) {
			$prefix = $this->read_exactly($handle, 4);

			if (strlen($prefix) < 4) {
				break;
			}

			$length  = (int) unpack('N', $prefix)[1];
			$payload = $this->read_exactly($handle, $length);

			$remaining -= 4 + $length;

			if ($this->encryptor !== null) {
				$payload = $this->encryptor->decrypt($payload);
			}

			$out .= $this->decompress($payload);
		}

		return $out;
	}

	/**
	 * Read an encrypted-only (uncompressed) record.
	 *
	 * @param resource $handle Archive stream.
	 * @param int      $size   Archived byte count.
	 * @return string
	 */
	private function read_encrypted($handle, int $size): string {
		$out       = '';
		$remaining = $size;

		while ($remaining > 0) {
			$chunk_length = $remaining > self::CHUNK_SIZE
				? self::CHUNK_SIZE + self::CHUNK_OVERHEAD
				: $remaining;

			$payload = $this->read_exactly($handle, $chunk_length);

			if ($payload === '') {
				break;
			}

			$remaining -= strlen($payload);

			$out .= $this->encryptor !== null ? $this->encryptor->decrypt($payload) : $payload;
		}

		return $out;
	}

	/**
	 * @param string $data Compressed bytes.
	 * @return string
	 */
	private function decompress(string $data): string {
		if ($data === '') {
			return '';
		}

		if ($this->compression === 'gzip') {
			$result = @gzuncompress($data);

			if ($result === false) {
				throw new \RuntimeException('Unable to decompress gzip archive chunk.');
			}

			return $result;
		}

		if ($this->compression === 'bzip2') {
			$result = @bzdecompress($data);

			if (! is_string($result)) {
				throw new \RuntimeException('Unable to decompress bzip2 archive chunk.');
			}

			return $result;
		}

		return $data;
	}

	/**
	 * Read exactly $length bytes, or as many as remain.
	 *
	 * @param resource $handle Stream.
	 * @param int      $length Byte count.
	 * @return string
	 */
	private function read_exactly($handle, int $length): string {
		if ($length <= 0) {
			return '';
		}

		$buffer = '';

		while (strlen($buffer) < $length) {
			$chunk = fread($handle, $length - strlen($buffer));

			if ($chunk === false || $chunk === '') {
				break;
			}

			$buffer .= $chunk;
		}

		return $buffer;
	}

	private function file_size(): int {
		$size = filesize($this->path);

		return $size === false ? 0 : (int) $size;
	}
}
