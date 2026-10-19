<?php
/**
 * Plugin Name:       MigrateKit
 * Plugin URI:        https://getMigrateKit.com
 * Description:       One-click WordPress migration, backup and restore. Database, media, themes and plugins in a single archive. Every feature included, free, forever — no extensions required.
 * Version:           0.0.3
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            MEHEDI HASSAN SHUBHO
 * Author URI:        https://wpmhs.com
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       migratekit
 * Domain Path:       /languages
 *
 * @package MigrateKit
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

if (defined('MIGRATEKIT_VERSION')) {
	return;
}

define('MIGRATEKIT_VERSION', '0.0.3');
define('MIGRATEKIT_PLUGIN_FILE', __FILE__);
define('MIGRATEKIT_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('MIGRATEKIT_PATH', plugin_dir_path(__FILE__));
define('MIGRATEKIT_URL', plugin_dir_url(__FILE__));
define('MIGRATEKIT_SRC_PATH', MIGRATEKIT_PATH . 'src');

/**
 * Public Dropbox App key baked into the distribution so the Cloud page can
 * offer a one-click connection (paste the displayed authorisation code).
 * Empty until a key is set; users can always use their own Dropbox app.
 */
define('MIGRATEKIT_DROPBOX_APP_ID', '');

require_once MIGRATEKIT_SRC_PATH . '/Support/Autoloader.php';
\MigrateKit\Support\Autoloader::register('MigrateKit\\', MIGRATEKIT_SRC_PATH);

register_activation_hook(__FILE__, array('\\MigrateKit\\Activation', 'activate'));
register_deactivation_hook(__FILE__, array('\\MigrateKit\\Activation', 'deactivate'));

\MigrateKit\Plugin::instance()->boot();
