<?php
/**
 * Scheduled backup email notifications.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Scheduler;

final class Notifier {

	private function __construct() {
	}

	/**
	 * @param array  $schedule Schedule definition.
	 * @param bool   $success  Whether the run succeeded.
	 * @param string $message  Result text.
	 * @return void
	 */
	public static function send(array $schedule, bool $success, string $message): void {
		$email = isset($schedule['notify_email']) ? (string) $schedule['notify_email'] : '';

		if ($email === '' || ! is_email($email)) {
			return;
		}

		$site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
		$name = isset($schedule['name']) ? (string) $schedule['name'] : 'Scheduled backup';

		$subject = sprintf(
			'[%s] %s: %s',
			$site,
			$name,
			$success ? 'Success' : 'Failed'
		);

		$body = sprintf(
			"Site: %s\nSchedule: %s\nStatus: %s\n\n%s\n",
			home_url(),
			$name,
			$success ? 'Success' : 'Failed',
			$message
		);

		wp_mail($email, $subject, $body);
	}
}
