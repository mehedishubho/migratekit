<script setup lang="ts">
import { onMounted, ref } from 'vue';
import {
	config,
	driveJob,
	formatDate,
	friendlyInterval,
	inWords,
	intervalSeconds,
	post,
	runJob,
	type JobState,
	type RetryNotice,
} from '../api';
import { saveStarterSchedule } from '../presets';

interface ScheduleOptions {
	compression: string;
	password: string;
	network: string;
	no_media: boolean;
	no_plugins: boolean;
	no_themes: boolean;
	no_database: boolean;
	no_post_revisions: boolean;
	no_spam_comments: boolean;
}

interface Schedule {
	id: string;
	name: string;
	enabled: boolean;
	interval: string;
	options: ScheduleOptions;
	cloud_provider: string;
	retention_count: number;
	retention_days: number;
	notify_email: string;
	last_run: number;
	last_status: string;
	next_run?: number;
}

const schedules = ref<Schedule[]>([]);
const intervals = ref<string[]>(['hourly', 'twicedaily', 'daily', 'weekly', 'monthly']);
const cloudProviders = ref<Array<{ id: string; label: string; ready: boolean }>>([]);
const adminEmail = ref('');
const editing = ref<Schedule | null>(null);
const loading = ref(false);
const error = ref('');
const notice = ref('');
const starterBusy = ref(false);

/** An in-browser Run now: the export job state plus the schedule label. */
const activeRun = ref<{ name: string } | null>(null);
const runJobState = ref<JobState | null>(null);

/** Set while a step is retried because the server did not answer. */
const retrying = ref<RetryNotice | null>(null);

/** localStorage key of the Run now in flight, so a closed tab can be reopened onto it. */
const RUN_KEY = 'migratekit-schedule-run';

interface SavedRun {
	scheduleId: string;
	jobId: string;
}

function savedRun(): SavedRun | null {
	try {
		const raw = window.localStorage.getItem(RUN_KEY);

		return raw ? (JSON.parse(raw) as SavedRun) : null;
	} catch {
		return null;
	}
}

function rememberRun(scheduleId: string, jobId: string): void {
	window.localStorage.setItem(RUN_KEY, JSON.stringify({ scheduleId, jobId }));
}

function forgetRun(): void {
	window.localStorage.removeItem(RUN_KEY);
}

function blank(): Schedule {
	return {
		id: '',
		name: '',
		enabled: true,
		interval: 'daily',
		options: {
			compression: '',
			password: '',
			network: '',
			no_media: false,
			no_plugins: false,
			no_themes: false,
			no_database: false,
			no_post_revisions: false,
			no_spam_comments: false,
		},
		cloud_provider: '',
		retention_count: 0,
		retention_days: 0,
		notify_email: adminEmail.value,
		last_run: 0,
		last_status: '',
	};
}

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';

	try {
		const data = await post<{ schedules: Schedule[]; intervals: string[]; admin_email?: string }>(
			'migratekit_schedules_list'
		);
		schedules.value = data.schedules;
		intervals.value = data.intervals;
		adminEmail.value = typeof data.admin_email === 'string' ? data.admin_email : '';

		const cloud = await post<{ providers: Array<{ id: string; label: string; ready: boolean }> }>(
			'migratekit_cloud_status'
		);
		cloudProviders.value = cloud.providers.filter((provider) => provider.ready);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to load schedules.';
	} finally {
		loading.value = false;
	}
}

function createNew(): void {
	editing.value = blank();
	notice.value = '';
	error.value = '';
}

function edit(schedule: Schedule): void {
	editing.value = JSON.parse(JSON.stringify(schedule));
	notice.value = '';
	error.value = '';
}

function payload(schedule: Schedule): Record<string, unknown> {
	return {
		id: schedule.id,
		name: schedule.name,
		enabled: schedule.enabled,
		interval: schedule.interval,
		compression: schedule.options.compression,
		password: schedule.options.password,
		network: schedule.options.network,
		no_media: schedule.options.no_media,
		no_plugins: schedule.options.no_plugins,
		no_themes: schedule.options.no_themes,
		no_database: schedule.options.no_database,
		no_post_revisions: schedule.options.no_post_revisions,
		no_spam_comments: schedule.options.no_spam_comments,
		cloud_provider: schedule.cloud_provider,
		retention_count: schedule.retention_count,
		retention_days: schedule.retention_days,
		notify_email: schedule.notify_email,
	};
}

async function save(): Promise<void> {
	if (!editing.value) {
		return;
	}

	if (!editing.value.name.trim()) {
		error.value = 'Give this schedule a name.';
		return;
	}

	try {
		await post('migratekit_schedule_save', payload(editing.value));
		const wasNew = editing.value.id === '';
		notice.value = wasNew ? 'Schedule saved — it is now armed and will run on its own.' : 'Schedule saved.';
		editing.value = null;
		error.value = '';
		await load();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to save the schedule.';
	}
}

/**
 * One click → a sensible default schedule: daily gzip archive, newest 14
 * kept, and an email after every run addressed to the site admin.
 */
async function createStarter(): Promise<void> {
	if (starterBusy.value) {
		return;
	}

	starterBusy.value = true;
	error.value = '';

	try {
		await saveStarterSchedule(adminEmail.value);
		notice.value = 'Automatic backups are on — one runs every day, and you get an email after each one.';
		await load();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to create the schedule.';
	} finally {
		starterBusy.value = false;
	}
}

async function remove(schedule: Schedule): Promise<void> {
	if (!window.confirm(`Delete the schedule "${schedule.name}"? Existing backups are not deleted.`)) {
		return;
	}

	try {
		await post('migratekit_schedule_delete', { id: schedule.id });
		await load();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to delete the schedule.';
	}
}

/**
 * Record how a Run now ended. A job that started is closed out through the
 * server, so the schedule history and the notification email stay truthful.
 */
async function settle(schedule: Schedule, jobId: string, failure?: unknown): Promise<void> {
	if (jobId === '') {
		error.value = failure instanceof Error ? failure.message : 'The backup failed.';
		return;
	}

	try {
		const result = await post<{ status: string }>('migratekit_schedule_finish', {
			id: schedule.id,
			job_id: jobId,
		});
		const status = result.status.replace(/^(Success|Failed):\s*/, '');

		if (result.status.startsWith('Failed')) {
			error.value = `The backup failed: ${status}`;
		} else {
			notice.value = `Backup finished: ${status}`;
		}
	} catch (recordError) {
		const extra = recordError instanceof Error ? recordError.message : '';
		const message = failure instanceof Error ? failure.message : 'The backup failed.';

		error.value = extra === '' ? message : `${message} (${extra})`;
	}
}

/** Drive a schedule's export job to the end, then record the outcome. Never throws. */
async function drive(schedule: Schedule, start: JobState | Promise<JobState>): Promise<void> {
	activeRun.value = { name: schedule.name };
	runJobState.value = null;
	retrying.value = null;

	let jobId = '';

	const track = (current: JobState): void => {
		runJobState.value = current;
		jobId = current.id;
		rememberRun(schedule.id, current.id);
	};

	const trackRetry = (notice: RetryNotice): void => {
		retrying.value = notice;
	};

	try {
		if (start instanceof Promise) {
			await runJob(start, 'migratekit_export_step', track, undefined, trackRetry);
		} else {
			await driveJob(start, 'migratekit_export_step', track, undefined, trackRetry);
		}

		await settle(schedule, jobId);
	} catch (caught) {
		await settle(schedule, jobId, caught);
	} finally {
		forgetRun();
		runJobState.value = null;
		retrying.value = null;
		activeRun.value = null;
		await load();
	}
}

async function runNow(schedule: Schedule): Promise<void> {
	if (activeRun.value) {
		return;
	}

	error.value = '';
	notice.value = `"${schedule.name}" is running now. You can leave this page — it resumes when you return.`;

	await drive(schedule, post<JobState>('migratekit_schedule_start', { id: schedule.id }));
}

/** After a reload, pick the Run now that a closed tab left behind back up. */
async function resumeRun(): Promise<void> {
	if (activeRun.value) {
		return;
	}

	const saved = savedRun();

	if (saved === null || !saved.jobId) {
		return;
	}

	const schedule = schedules.value.find((item) => item.id === saved.scheduleId);

	if (schedule === undefined) {
		forgetRun();
		return;
	}

	try {
		const state = await post<JobState>('migratekit_status', { job_id: saved.jobId });

		notice.value = `"${schedule.name}" is resuming — it continues from where it stopped.`;

		await drive(schedule, state);
	} catch {
		// The job is gone (cancelled or cleaned up on the server), so there is nothing to reconnect to.
		forgetRun();
	}
}

onMounted(async () => {
	await load();
	await resumeRun();
});

/** "in 2h 5m", "Due now", "Paused", "Not armed". */
function nextRunLabel(schedule: Schedule): string {
	if (!schedule.enabled) {
		return 'Paused';
	}

	if (!schedule.next_run) {
		return 'Not armed';
	}

	const diff = schedule.next_run - Date.now();

	return diff <= 60_000 ? 'Due now' : `in ${inWords(diff / 1000)}`;
}

/** Skippable plain-language warnings about the schedule's cron health. */
function scheduleWarning(schedule: Schedule): string {
	if (!schedule.enabled) {
		return '';
	}

	const now = Date.now();

	if (!schedule.next_run) {
		return 'No run is queued — save the schedule again (or press Run now) to arm it.';
	}

	if (schedule.next_run < now) {
		return `Was due ${inWords((now - schedule.next_run) / 1000)} ago — cron fires when someone visits the site, so quiet sites run late.`;
	}

	if (
		schedule.last_run &&
		now / 1000 - schedule.last_run > intervalSeconds(schedule.interval) * 1.5 + 7200
	) {
		return `Last ran ${formatDate(schedule.last_run)} — that is more than one full ${friendlyInterval(schedule.interval).toLowerCase()} cycle ago; the schedule may not be firing.`;
	}

	return '';
}
</script>

<template>
	<section class="mk-card">
		<div class="mk-card-head">
			<div>
				<h2>Scheduled backups</h2>
				<p class="mk-muted">Set it up once — fresh backups are made automatically, and old ones are pruned for you.</p>
			</div>
			<button class="mk-btn" type="button" @click="createNew">New schedule</button>
		</div>

		<p v-if="error" class="mk-error">{{ error }}</p>
		<p v-if="notice" class="mk-success">{{ notice }}</p>

		<div v-if="activeRun" class="mk-progress" role="status" aria-live="polite">
			<div class="mk-bar"><span :style="{ width: (runJobState?.percent ?? 0) + '%' }"></span></div>
			<div class="mk-progress-meta">
				<span>{{ activeRun.name }} — {{ runJobState?.message || 'Starting…' }}</span>
				<span>{{ runJobState?.percent ?? 0 }}%</span>
			</div>
		</div>

		<p v-if="retrying" class="mk-warn" role="status">
			The server did not answer ({{ retrying.message }}). Retrying in {{ retrying.delaySeconds }}s — attempt
			{{ retrying.attempt }}. Nothing is lost: the backup continues from where it stopped.
		</p>

		<div v-if="editing" class="mk-editor">
			<h3>{{ editing.id ? 'Edit schedule' : 'New schedule' }}</h3>

			<div class="mk-grid">
				<label class="mk-field">
					<span>Name</span>
					<input v-model="editing.name" type="text" maxlength="100" placeholder="Daily backup" />
					<em>Just for you — call it anything, e.g. "Client site – weekly".</em>
				</label>
				<label class="mk-field">
					<span>Runs</span>
					<select v-model="editing.interval">
						<option v-for="interval in intervals" :key="interval" :value="interval">
							{{ friendlyInterval(interval) }}
						</option>
					</select>
					<em>How often a backup is taken.</em>
				</label>
				<label class="mk-field">
					<span>Compression</span>
					<select v-model="editing.options.compression">
						<option value="">None</option>
						<option value="gzip">gzip</option>
						<option value="bzip2">bzip2</option>
					</select>
					<em>None is fastest. gzip/bzip2 shrink the archive but take longer.</em>
				</label>
				<label v-if="config.isMultisite" class="mk-field">
					<span>Scope</span>
					<select v-model="editing.options.network">
						<option value="">{{ config.isSubsite ? 'Main site only' : 'This site only' }}</option>
						<option value="whole">Entire network ({{ config.siteCount }} sites)</option>
					</select>
				</label>
				<label class="mk-field">
					<span>Password <em>optional</em></span>
					<input v-model="editing.options.password" type="password" autocomplete="new-password" />
					<em>Encrypts the archive. You must type it again to restore — keep it somewhere safe.</em>
				</label>
				<label class="mk-field">
					<span>Keep newest <em>0 = keep all</em></span>
					<input v-model.number="editing.retention_count" type="number" min="0" step="1" />
					<em>After every run, backups beyond this count are removed automatically.</em>
				</label>
				<label class="mk-field">
					<span>Keep days <em>0 = ignore age</em></span>
					<input v-model.number="editing.retention_days" type="number" min="0" step="1" />
					<em>Backups older than this many days are removed after every run.</em>
				</label>
				<label class="mk-field">
					<span>Notify email <em>optional</em></span>
					<input v-model="editing.notify_email" type="email" :placeholder="adminEmail || 'admin@example.com'" />
					<em>Sends an email after every run, success or failure, so you always know it worked.</em>
				</label>
				<label class="mk-check mk-check-inline">
					<input v-model="editing.enabled" type="checkbox" />
					<span>Enabled</span>
				</label>
				<label class="mk-field">
					<span>Cloud copy <em>optional</em></span>
					<select v-model="editing.cloud_provider">
						<option value="">None — keep backups on this server</option>
						<option v-for="provider in cloudProviders" :key="provider.id" :value="provider.id">
							{{ provider.label }} — push after every backup
						</option>
					</select>
					<em>Every finished backup is also uploaded to this provider (connect one under Cloud).</em>
				</label>
			</div>

			<fieldset class="mk-checks">
				<legend>Exclude from the backup</legend>
				<label class="mk-check"><input v-model="editing.options.no_media" type="checkbox" /><span>Media library</span></label>
				<label class="mk-check"><input v-model="editing.options.no_plugins" type="checkbox" /><span>Plugins</span></label>
				<label class="mk-check"><input v-model="editing.options.no_themes" type="checkbox" /><span>Themes</span></label>
				<label class="mk-check"><input v-model="editing.options.no_database" type="checkbox" /><span>Database</span></label>
				<label class="mk-check"><input v-model="editing.options.no_post_revisions" type="checkbox" /><span>Post revisions</span></label>
				<label class="mk-check"><input v-model="editing.options.no_spam_comments" type="checkbox" /><span>Spam comments</span></label>
			</fieldset>

			<div class="mk-actions">
				<button class="mk-btn" type="button" @click="save">Save schedule</button>
				<button class="mk-btn mk-btn-ghost" type="button" @click="editing = null">Cancel</button>
			</div>
		</div>

		<div v-if="!loading && schedules.length === 0 && !editing" class="mk-empty">
			<strong>No schedules yet</strong>
			<span>Turn this on once and your site backs itself up every day — keeping the newest 14 and emailing you each run.</span>
			<button class="mk-btn" type="button" :disabled="starterBusy" @click="createStarter">
				{{ starterBusy ? 'Turning on…' : 'Turn on daily backups' }}
			</button>
			<button class="mk-link" type="button" @click="createNew">Or build a custom schedule</button>
		</div>

		<table v-else-if="schedules.length > 0" class="mk-table">
			<thead>
				<tr>
					<th>Name</th>
					<th>Runs</th>
					<th>Next run</th>
					<th>Cloud copy</th>
					<th>Last run</th>
					<th class="mk-right">Actions</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="schedule in schedules" :key="schedule.id">
					<td>
						{{ schedule.name }}
						<span class="mk-status">{{ schedule.enabled ? 'Enabled' : 'Paused' }}</span>
					</td>
					<td>{{ friendlyInterval(schedule.interval) }}</td>
					<td>
						{{ nextRunLabel(schedule) }}
						<p v-if="scheduleWarning(schedule)" class="mk-warn">{{ scheduleWarning(schedule) }}</p>
					</td>
					<td>
						{{
							cloudProviders.find((provider) => provider.id === schedule.cloud_provider)?.label ||
							(schedule.cloud_provider ? schedule.cloud_provider : '—')
						}}
					</td>
					<td>
						{{ schedule.last_run ? formatDate(schedule.last_run) : 'Never' }}
						<span v-if="schedule.last_status" class="mk-status">{{ schedule.last_status }}</span>
					</td>
					<td class="mk-right">
						<button class="mk-link" type="button" @click="edit(schedule)">Edit</button>
						<button class="mk-link" type="button" :disabled="activeRun !== null" @click="runNow(schedule)">Run now</button>
						<button class="mk-link mk-link-danger" type="button" @click="remove(schedule)">Delete</button>
					</td>
				</tr>
			</tbody>
		</table>
	</section>
</template>
