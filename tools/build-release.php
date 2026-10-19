<?php
/**
 * Builds the installable release ZIP of MigrateKit.
 *
 * Usage: php tools/build-release.php [--out=<dir>]
 *
 * Ships the distribution payload (plugin entry, uninstall, readme, license,
 * src/ and the compiled build/) inside a top-level `migratekit/` folder, so
 * the ZIP activates straight from Plugins -> Add New -> Upload Plugin.
 *
 * Uses PharData (zip extension is not part of the CLI image).
 *
 * @package MigrateKit
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	exit("CLI only.\n");
}

$root = dirname(__DIR__);

$header   = (string) file_get_contents($root . '/migratekit.php');
$version  = '';
$constant = '';

if (preg_match('/^ \* Version:\s+([0-9a-zA-Z.\-]+)/m', $header, $m)) {
	$version = $m[1];
}

if (preg_match("/define\('MIGRATEKIT_VERSION',\s*'([^']+)'\)/", $header, $m)) {
	$constant = $m[1];
}

if ($version === '' || $version !== $constant) {
	exit("ERROR  - migratekit.php header and MIGRATEKIT_VERSION disagree ('{$version}' vs '{$constant}').\n");
}

$manifest = $root . '/build/.vite/manifest.json';

if (! is_file($manifest) || ! is_dir($root . '/build/assets')) {
	exit("ERROR  - the compiled front-end is missing. Run `npm run build` inside the plugin root first.\n");
}

$manifest_data = json_decode((string) file_get_contents($manifest), true);
$missing       = array();

foreach ((array) $manifest_data as $entry) {
	foreach (array( 'file', 'css' ) as $key) {
		if (empty($entry[ $key ])) {
			continue;
		}

		foreach ((array) $entry[ $key ] as $asset) {
			$asset = is_string($asset) ? $asset : '';

			if ($asset !== '' && ! is_file($root . '/build/' . $asset)) {
				$missing[] = 'build/' . $asset;
			}
		}
	}
}

if ($missing !== array()) {
	exit("ERROR  - the manifest references missing assets: " . implode(', ', $missing) . "\n");
}

$out_dir = $root . '/releases';

foreach (array_slice($argv, 1) as $arg) {
	if (strpos($arg, '--out=') === 0) {
		$out_dir = rtrim(substr($arg, 6), '/\\') === '' ? false : rtrim(substr($arg, 6), '/\\');
	}
}

if ($out_dir === '' || $out_dir === false) {
	exit("ERROR  - empty output directory.\n");
}

if (! is_dir($out_dir) && ! @mkdir($out_dir, 0777, true) && ! is_dir($out_dir)) {
	exit("ERROR  - cannot create the output directory: {$out_dir}\n");
}

$zip_path = $out_dir . '/migratekit-' . $version . '.zip';
$staging  = $out_dir . '/.staging-migratekit';

if (is_file($zip_path) && ! unlink($zip_path)) {
	exit("ERROR  - cannot replace the existing archive: {$zip_path}\n");
}

rm_down($staging);

$plugin_dir = $staging . '/migratekit';

if (! @mkdir($plugin_dir, 0777, true) && ! is_dir($plugin_dir)) {
	exit("ERROR  - cannot create the staging directory: {$plugin_dir}\n");
}

foreach (array( 'migratekit.php', 'uninstall.php', 'readme.txt', 'LICENSE' ) as $file) {
	if (! @copy($root . '/' . $file, $plugin_dir . '/' . basename($file))) {
		exit("ERROR  - cannot stage {$file}\n");
	}
}

foreach (array( 'src', 'build' ) as $dir) {
	copy_dir($root . '/' . $dir, $plugin_dir . '/' . $dir);
}

$phar = new PharData($zip_path, 0, null, Phar::ZIP);
$phar->buildFromDirectory($staging);

$count = $phar->count();
unset($phar);

rm_down($staging);

$size   = (int) filesize($zip_path);
$mbytes = round($size / 1048576, 2);

echo "release  : {$zip_path}\n";
echo "version  : {$version}\n";
echo "entries  : {$count}\n";
echo "size     : {$mbytes} MB\n";

/**
 * Recursively delete a directory.
 *
 * @param string $path Directory to remove.
 * @return void
 */
function rm_down(string $path): void {
	if (! is_dir($path)) {
		return;
	}

	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
		if ($item->isDir()) {
			@rmdir($item->getPathname());
		} else {
			@unlink($item->getPathname());
		}
	}

	@rmdir($path);
}

/**
 * Recursively copy a directory.
 *
 * @param string $source Source directory.
 * @param string $target Destination directory.
 * @return void
 */
function copy_dir(string $source, string $target): void {
	if (! is_dir($target) && ! @mkdir($target, 0777, true) && ! is_dir($target)) {
		fwrite(STDERR, "ERROR  - cannot create {$target}\n");
		exit(1);
	}

	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

	foreach ($it as $item) {
		$child = $target . '/' . $it->getSubPathName();

		if ($item->isDir()) {
			if (! is_dir($child) && ! @mkdir($child, 0777, true) && ! is_dir($child)) {
				fwrite(STDERR, "ERROR  - cannot create {$child}\n");
				exit(1);
			}
		} elseif (! @copy($item->getPathname(), $child)) {
			fwrite(STDERR, "ERROR  - cannot copy {$item->getPathname()}\n");
			exit(1);
		}
	}
}
