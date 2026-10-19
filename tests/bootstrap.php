<?php
/**
 * Test bootstrap: prefer the Composer autoloader, fall back to the bundled one.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

$composer = __DIR__ . '/../vendor/autoload.php';

if (is_file($composer)) {
	require $composer;
}

require_once __DIR__ . '/../src/Support/Autoloader.php';
\MigrateKit\Support\Autoloader::register('MigrateKit\\', __DIR__ . '/../src');
