<?php
/**
 * Append-only .wpress archive writer.
 *
 * Records are written as a 4377-byte header followed by the archived bytes.
 * Config records (package.json, multisite.json) are always stored raw. Other
 * records are optionally compressed (gzip/bzip2) and encrypted per 512 KB chunk.
 * A CRC32 end-of-archive sentinel is appended on close().
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Archive;

final class Writer {

	public const CHUNK_SIZE = 512000;

	private const COMPRESSION_TYPES = array('gzip', 'bzip2');

	/** @var string */
	private $path;

	/** @var resource */
	private $handle;

	/** @var string|null */
	private $compression;

	/** @var Encryptor|null */
	private $encryptor;

	/** @var bool */
	private $closed = false;

	/**
	 * @param string      $path        Destination archive path.
	 * @param string|null $compression "gzip", "bzip2" or null for none.
	 * @param string|null $password    Encryption password, or null for none.
	 * @param bool        $truncate    True to start a fresh archive, false to append.
	 * @param int         $offset      Recorded end of the archive when appending; anything past it is discarded (-1 keeps it all).
	 */
	public function __construct(string $path, ?string $compression = null, ?string $password = null, bool $truncate = true, int $offset = -1) {
		if ($compression === '') {
			$compression = null;
		}

		if ($compression !== null && ! in_array($compression, self::COMPRESSION_TYPES, true)) {
			throw new \InvalidArgumentException('Unsupported compression type: ' . $compression);
		}

		if ($compression === 'bzip2' && ! function_exists('bzcompress')) {
			throw new \RuntimeException('The bz2 extension is required for bzip2 compression.');
		}

		if ($password === '') {
			$password = null;
		}

		$this->compression = $compression;
		$this->encryptor   = $password !== null ? new Encryptor($password) : null;

		$handle = fopen($path, $truncate ? 'w+b' : 'c+b');

		if ($handle === false) {
			throw new \RuntimeException('Unable to open archive for writing: ' . $path);
		}

		fseek($handle, 0, SEEK_END);

		if (! $truncate && $offset >= 0) {
			// A request killed after writing leaves bytes its job never recorded; drop them so the archive matches the job.
			if (ftell($handle) > $offset) {
				ftruncate($handle, $offset);
			}

			fseek($handle, $offset);
		}

		$this->path   = $path;
		$this->handle = $handle;
	}

	/**
	 * Release the handle without finalizing. A writer abandoned by an exception
	 * must neither append a sentinel to a half-written archive nor read it all
	 * back to checksum it.
	 */
	public function __destruct() {
		if (! $this->closed) {
			$this->close(false);
		}
	}

	public function path(): string {
		return $this->path;
	}

	/**
	 * Current write position in the archive.
	 *
	 * @return int
	 */
	public function tell(): int {
		$position = ftell($this->handle);

		return $position === false ? 0 : $position;
	}

	/**
	 * Append a record from an in-memory string.
	 *
	 * @param string   $archive_path Archive-relative path, e.g. "package.json".
	 * @param string   $data         Plaintext contents.
	 * @param int|null $mtime        Modification time (defaults to now).
	 * @return int Archived byte count written.
	 */
	public function add_contents(string $archive_path, string $data, ?int $mtime = null): int {
		$mtime             = $mtime !== null ? $mtime : time();
		$raw               = in_array($archive_path, Header::UNPROCESSED, true);
		list($name, $dir)  = $this->split($archive_path);

		$context = hash_init('crc32b');
		$header_offset = $this->begin_record($name, $dir, $mtime);

		$written = 0;
		$length  = strlen($data);
		$offset  = 0;

		while ($offset < $length) {
			$chunk   = substr($data, $offset, self::CHUNK_SIZE);
			$offset += strlen($chunk);

			hash_update($context, $chunk);
			$out = $this->transform($chunk, $raw);

			$this->write($out);
			$written += strlen($out);
		}

		$this->finish_record($header_offset, $written, hash_final($context));

		return $written;
	}

	/**
	 * Append a record by streaming a source file.
	 *
	 * @param string   $archive_path Archive-relative path.
	 * @param string   $source_path  Local file to read.
	 * @param int|null $mtime        Modification time (defaults to the source mtime).
	 * @return int Archived byte count written.
	 */
	public function add_file(string $archive_path, string $source_path, ?int $mtime = null): int {
		if (! is_file($source_path)) {
			throw new \RuntimeException('Source file not found: ' . $source_path);
		}

		if ($mtime === null) {
			$mtime = (int) filemtime($source_path);
		}

		$raw              = in_array($archive_path, Header::UNPROCESSED, true);
		list($name, $dir) = $this->split($archive_path);

		$context       = hash_init('crc32b');
		$header_offset = $this->begin_record($name, $dir, $mtime);

		$source = fopen($source_path, 'rb');

		if ($source === false) {
			throw new \RuntimeException('Unable to read source file: ' . $source_path);
		}

		$written = 0;

		while (! feof($source)) {
			$chunk = fread($source, self::CHUNK_SIZE);

			if ($chunk === false || $chunk === '') {
				break;
			}

			hash_update($context, $chunk);
			$out = $this->transform($chunk, $raw);

			$this->write($out);
			$written += strlen($out);
		}

		fclose($source);

		$this->finish_record($header_offset, $written, hash_final($context));

		return $written;
	}

	/**
	 * Append one resumable chunk of a source file.
	 *
	 * State variables are passed by reference so a later request can continue:
	 *  - $source_offset: bytes already read from the source.
	 *  - $written:       archived bytes written for this record so far.
	 *  - $header_offset: absolute header offset, null until the first call.
	 *  - $crc:           CRC32 of the source bytes archived so far. Pass it to
	 *                    hash while streaming instead of re-reading the whole file at the end.
	 *
	 * @param string      $archive_path  Archive-relative path.
	 * @param string      $source_path   Local source file.
	 * @param int         $source_offset Source byte offset (by reference).
	 * @param int         $written       Archived bytes written (by reference).
	 * @param int|null    $mtime         Modification time, or null for source mtime.
	 * @param int|null    $header_offset Header offset (by reference).
	 * @param float|null  $deadline      Optional wall-clock deadline.
	 * @param string|null $crc           Running CRC32, 8 hex chars (by reference).
	 * @return bool True when the record is complete.
	 */
	public function add_file_chunk(
		string $archive_path,
		string $source_path,
		int &$source_offset,
		int &$written,
		?int $mtime,
		?int &$header_offset,
		?float $deadline = null,
		?string &$crc = null
	): bool {
		if (! is_file($source_path)) {
			throw new \RuntimeException('Source file not found: ' . $source_path);
		}

		$raw = in_array($archive_path, Header::UNPROCESSED, true);

		if ($header_offset === null) {
			if ($mtime === null) {
				$mtime = (int) filemtime($source_path);
			}

			list($name, $dir) = $this->split($archive_path);
			$header_offset    = $this->begin_record($name, $dir, $mtime);
		} elseif ($this->tell() !== $header_offset + Header::LENGTH + $written) {
			throw new \RuntimeException('The archive no longer lines up with the position recorded for this export.');
		}

		$source = fopen($source_path, 'rb');

		if ($source === false) {
			throw new \RuntimeException('Unable to read source file: ' . $source_path);
		}

		fseek($source, $source_offset);

		$context   = func_num_args() >= 9 ? hash_init('crc32b') : null;
		$hashed    = 0;
		$completed = true;

		while (! feof($source)) {
			$chunk = fread($source, self::CHUNK_SIZE);

			if ($chunk === false || $chunk === '') {
				break;
			}

			$source_offset += strlen($chunk);

			if ($context !== null) {
				hash_update($context, $chunk);
				$hashed += strlen($chunk);
			}

			$out = $this->transform($chunk, $raw);

			$this->write($out);
			$written += strlen($out);

			if ($deadline !== null && microtime(true) >= $deadline) {
				$completed = false;
				break;
			}
		}

		fclose($source);

		if ($context !== null) {
			$crc = Crc32::combine($crc, hash_final($context), $hashed);
		}

		if ($completed) {
			$this->finish_record($header_offset, $written, $crc ?? Crc32::of_file($source_path));
		}

		return $completed;
	}

	/**
	 * Close the archive, optionally appending the CRC32 end sentinel.
	 *
	 * Intermediate pipeline stages call close(false) and reopen in append mode.
	 *
	 * @param bool $complete Whether to finalize the archive.
	 * @return void
	 */
	public function close(bool $complete = true): void {
		if ($this->closed) {
			return;
		}

		fflush($this->handle);

		if ($complete) {
			fseek($this->handle, 0, SEEK_END);
			$eof_start = $this->tell();

			fseek($this->handle, 0);
			$crc = Crc32::of_handle($this->handle, $eof_start);

			fseek($this->handle, $eof_start);
			$this->write(Header::pack_eof((string) $eof_start, $crc));
		}

		fflush($this->handle);
		fclose($this->handle);

		$this->closed = true;
	}

	/**
	 * Append the end-of-archive sentinel with a checksum computed by the caller,
	 * so a large archive can be verified across several requests.
	 *
	 * @param string $archive_crc CRC32 of every byte before this block, 8 hex chars.
	 * @return void
	 */
	public function append_eof(string $archive_crc): void {
		fseek($this->handle, 0, SEEK_END);

		$this->write(Header::pack_eof((string) $this->tell(), $archive_crc));
	}

	/**
	 * Split an archive-relative path into [basename, dirname].
	 *
	 * @param string $archive_path Archive-relative path.
	 * @return array{0:string,1:string}
	 */
	private function split(string $archive_path): array {
		$archive_path = ltrim(str_replace('\\', '/', $archive_path), '/');
		$slash        = strrpos($archive_path, '/');

		if ($slash === false) {
			return array($archive_path, '.');
		}

		$dir  = substr($archive_path, 0, $slash);
		$name = substr($archive_path, $slash + 1);

		return array($name, $dir === '' ? '.' : $dir);
	}

	/**
	 * Write a placeholder header and return its offset.
	 *
	 * @param string $name  Basename.
	 * @param string $dir   Dirname.
	 * @param int    $mtime Modification time.
	 * @return int Header offset.
	 */
	private function begin_record(string $name, string $dir, int $mtime): int {
		$this->assert_field('name', $name, Header::NAME_LENGTH);
		$this->assert_field('path', $dir, Header::PATH_LENGTH);

		fseek($this->handle, 0, SEEK_END);
		$header_offset = $this->tell();

		$this->write(Header::pack_record($name, '', (string) $mtime, $dir, ''));

		return $header_offset;
	}

	/**
	 * Patch the size and CRC fields and leave the pointer at the record end.
	 *
	 * @param int    $header_offset Header offset.
	 * @param int    $written       Archived byte count.
	 * @param string $crc           8 hex chars.
	 * @return void
	 */
	private function finish_record(int $header_offset, int $written, string $crc): void {
		fseek($this->handle, $header_offset + Header::SIZE_OFFSET);
		$this->write(pack('a' . Header::SIZE_LENGTH, (string) $written));

		fseek($this->handle, $header_offset + Header::CRC_OFFSET);
		$this->write(pack('a' . Header::CRC_LENGTH, $crc));

		fseek($this->handle, $header_offset + Header::LENGTH + $written);
	}

	/**
	 * Apply compression/encryption to one chunk.
	 *
	 * Order: compress -> encrypt -> big-endian length prefix.
	 *
	 * @param string $chunk Raw chunk.
	 * @param bool   $raw   Whether the record is exempt from transformation.
	 * @return string
	 */
	private function transform(string $chunk, bool $raw): string {
		if ($raw) {
			return $chunk;
		}

		$out = $chunk;

		if ($this->compression === 'gzip') {
			$out = gzcompress($out, 9);

			if ($out === false) {
				throw new \RuntimeException('Unable to compress archive chunk with gzip.');
			}
		} elseif ($this->compression === 'bzip2') {
			$out = bzcompress($out, 9);

			if (is_int($out)) {
				throw new \RuntimeException('Unable to compress archive chunk with bzip2.');
			}
		}

		if ($this->encryptor !== null) {
			$out = $this->encryptor->encrypt($out);
		}

		if ($this->compression !== null) {
			$out = pack('N', strlen($out)) . $out;
		}

		return $out;
	}

	/**
	 * Write every byte, or fail loudly: a full disk must not pass as a finished archive.
	 *
	 * @param string $bytes Bytes to append.
	 * @return void
	 */
	private function write(string $bytes): void {
		if ($bytes !== '' && @fwrite($this->handle, $bytes) !== strlen($bytes)) {
			throw new \RuntimeException('Unable to write to the archive — the disk may be full.');
		}
	}

	/**
	 * @param string $label Field name for the error message.
	 * @param string $value Field value.
	 * @param int    $max   Maximum byte length.
	 * @return void
	 */
	private function assert_field(string $label, string $value, int $max): void {
		if ($value === '') {
			throw new \InvalidArgumentException('Archive record ' . $label . ' must not be empty.');
		}

		if (strlen($value) > $max) {
			throw new \InvalidArgumentException(
				sprintf('Archive record %s exceeds %d bytes: %s', $label, $max, $value)
			);
		}
	}
}
