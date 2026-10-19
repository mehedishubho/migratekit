<?php
/**
 * Front-end maintenance screen while an import or restore runs in place.
 *
 * The flag carries a TTL so a crashed job can never leave the site sealed
 * off for more than an hour.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Support;

final class Maintenance {

	public const OPTION = 'migratekit_maintenance';

	/** Seconds the flag stays valid without a heartbeat. */
	public const TTL = 3600;

	private function __construct() {
	}

	public static function register(): void {
		add_action('template_redirect', array( 'MigrateKit\\Support\\Maintenance', 'intercept' ));
	}

	/**
	 * Turn the maintenance screen on.
	 *
	 * @return void
	 */
	public static function start(): void {
		OptionStore::put(self::OPTION, time());
	}

	/**
	 * Turn the maintenance screen off.
	 *
	 * @return void
	 */
	public static function stop(): void {
		OptionStore::drop(self::OPTION);
	}

	/**
	 * @return bool
	 */
	public static function is_on(): bool {
		$started = OptionStore::get(self::OPTION);

		return is_int($started) || is_numeric($started);
	}

	/**
	 * @param int|null $now Current time (injectable for tests).
	 * @return bool True when the flag has not been refreshed inside the TTL.
	 */
	public static function expired(?int $now = null): bool {
		$started = OptionStore::get(self::OPTION);

		if (! is_numeric($started)) {
			return true;
		}

		$now = $now === null ? time() : $now;

		return $now - (int) $started > self::TTL;
	}

	/**
	 * Front-end interception: logged-in users browse normally, everyone else
	 * gets a plain 503 until the job finishes (or the TTL lapses).
	 *
	 * @return void
	 */
	public static function intercept(): void {
		if (! self::is_on()) {
			return;
		}

		if (function_exists('is_user_logged_in') && is_user_logged_in()) {
			if (! current_user_can(Capabilities::IMPORT) && ! current_user_can(Capabilities::EXPORT)) {
				// Signed-in users without migration rights still see the screen.
			} else {
				return;
			}
		}

		if (self::expired()) {
			self::stop();
			return;
		}

		if (! function_exists('wp_die')) {
			return;
		}

		wp_die(
			sprintf(
				'<h1>%s</h1><p>%s</p>',
				esc_html__('Briefly unavailable for scheduled maintenance', 'migratekit'),
				esc_html__('This site is being restored from a backup. It will be back in a moment — reload to check again.', 'migratekit')
			),
			esc_html__('Maintenance', 'migratekit'),
			array( 'response' => 503, 'back_link' => false )
		);
	}
}
