import { post } from './api';

/**
 * One sensible schedule, applied with a single click.
 *
 * Daily gzip archive of the whole site, the newest 14 kept, and an email to
 * the site admin (or a chosen address) after every run — success or failure.
 */
export function starterSchedulePayload(adminEmail: string): Record<string, unknown> {
	return {
		name: 'Daily backup',
		enabled: true,
		interval: 'daily',
		compression: 'gzip',
		password: '',
		network: '',
		no_media: false,
		no_plugins: false,
		no_themes: false,
		no_database: false,
		no_post_revisions: false,
		no_spam_comments: false,
		cloud_provider: '',
		retention_count: 14,
		retention_days: 0,
		notify_email: adminEmail,
	};
}

export async function saveStarterSchedule(adminEmail: string): Promise<void> {
	await post('migratekit_schedule_save', starterSchedulePayload(adminEmail));
}
