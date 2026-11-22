<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { config, driveJob, formatBytes, friendlyInterval, inWords, post, runJob, type JobState } from '../api';
import { saveStarterSchedule } from '../presets';

interface Backup {
	name: string;
	label: string;
	size: number;
	mtime: number;
}

interface Schedule {
	id: string;
	name: string;
	enabled: boolean;
	interval: string;
	next_run?: number;
}

interface Provider {
	id: string;
	label: string;
	ready: boolean;
}

const backups = ref<Backup[] | null>(null);
const schedules = ref<Schedule[] | null>(null);
const providers = ref<Provider[] | null>(null);
const adminEmail = ref('');
const starterBusy = ref(false);
const notice = ref('');
const error = ref('');

/** One-click "back up now" straight from the dashboard. */
const running = ref(false);
const job = ref<JobState | null>(null);
const done = ref(false);

/** Remembers the in-flight job across page refreshes, so it can resume. */
const ACTIVE_JOB_KEY = 'migratekit-dashboard-job';

/** Set when the user stops the run; the polling loop checks it between steps. */
const stopping = ref(false);

/** A backup that failed and would be orphaned by a retry. */
const failedJobId = ref('');

/** Screen readers hear stage transitions, not every poll tick. */
const announcement = ref('');
let announcedJobId = '';
let announcedStage = -1;

const enabledSchedules = computed<Schedule[]>(() => (schedules.value ?? []).filter((item) => item.enabled));

const nextSchedule = computed<Schedule | null>(() => {
	let best: Schedule | null = null;

	for (const schedule of enabledSchedules.value) {
		if (!schedule.next_run) {
			continue;
		}

		if (best === null || schedule.next_run < (best.next_run ?? Infinity)) {
			best = schedule;
		}
	}

	return best;
});

const backupTotalSize = computed(() =>
	(backups.value ?? []).reduce((sum, backup) => sum + (Number(backup.size) || 0), 0),
);

const latestBackup = computed<Backup | null>(() => {
	let best: Backup | null = null;

	for (const backup of backups.value ?? []) {
		if (best === null || backup.mtime > best.mtime) {
			best = backup;
		}
	}

	return best;
});

const readyProviders = computed<Provider[]>(() => (providers.value ?? []).filter((provider) => provider.ready));

/** The safety headline: how long since the site could survive a restore. */
const lastBackupText = computed<string>(() => {
	if (backups.value !== null && backups.value.length === 0) {
		return 'Never';
	}

	const latest = latestBackup.value;

	if (latest === null) {
		return '…';
	}

	const seconds = Date.now() / 1000 - latest.mtime;

	return seconds < 120 ? 'just now' : `${inWords(seconds)} ago`;
});

/** The run-now button starts the job immediately and drives it like the Export page. */
async function backUpNow(): Promise<void> {
	if (running.value) {
		return;
	}

	await discardFailedJob();

	running.value = true;
	stopping.value = false;
	error.value = '';
	done.value = false;
	job.value = null;

	let jobId = '';

	try {
		await runJob(
			post<JobState>('migratekit_export_start', { compression: 'gzip' }),
			'migratekit_export_step',
			(state) => {
				trackJob(state);
				jobId = state.id;
			},
			() => stopping.value,
		);

		if (stopping.value) {
			return;
		}

		finishRun();
	} catch (caught) {
		if (stopping.value) {
			return;
		}

		error.value = caught instanceof Error ? caught.message : 'The backup failed.';
		failedJobId.value = jobId;
	} finally {
		running.value = false;
	}
}

function trackJob(state: JobState): void {
	job.value = state;

	if (state.id) {
		window.localStorage.setItem(ACTIVE_JOB_KEY, JSON.stringify({ id: state.id }));
	}

	if (state.id !== announcedJobId || state.stage !== announcedStage || state.done) {
		announcedJobId = state.id;
		announcedStage = state.stage;
		announcement.value = state.done ? `Backup complete: ${state.archiveName}` : state.message;
	}
}

/** A finished run clears its resume marker and refreshes the stale tiles. */
function finishRun(): void {
	done.value = true;
	failedJobId.value = '';
	window.localStorage.removeItem(ACTIVE_JOB_KEY);
	void reloadAll();
}

/** Retry discards the failed job's server-side working directory first. */
async function discardFailedJob(): Promise<void> {
	const id = failedJobId.value;
	failedJobId.value = '';

	if (!id) {
		return;
	}

	window.localStorage.removeItem(ACTIVE_JOB_KEY);

	try {
		await post('migratekit_job_cancel', { job_id: id });
	} catch {
		// An uncleanable leftover is harmless; the retry proceeds regardless.
	}
}

/** Stop the running backup. Cancels the job server-side and clears the resume marker. */
async function stopBackup(): Promise<void> {
	if (!running.value || job.value === null) {
		return;
	}

	stopping.value = true;

	try {
		await post('migratekit_job_cancel', { job_id: job.value.id });
	} catch {
		// The polling loop stops on its own; cleanup can fail without harm.
	}

	window.localStorage.removeItem(ACTIVE_JOB_KEY);
	notice.value = 'Backup stopped — nothing on your site was changed.';
}

/** After a refresh, pick the in-flight backup back up where it paused. */
async function resumeActiveJob(): Promise<void> {
	let id = '';

	try {
		const raw = window.localStorage.getItem(ACTIVE_JOB_KEY);
		id = raw ? (((JSON.parse(raw) as { id?: string }).id) ?? '') : '';
	} catch {
		id = '';
	}

	if (!id) {
		return;
	}

	running.value = true;
	stopping.value = false;
	error.value = '';
	done.value = false;

	let state: JobState;

	try {
		state = await post<JobState>('migratekit_status', { job_id: id });
	} catch {
		// The job is gone (finished elsewhere or cancelled); drop the marker quietly.
		window.localStorage.removeItem(ACTIVE_JOB_KEY);
		running.value = false;
		return;
	}

	if (state.done) {
		window.localStorage.removeItem(ACTIVE_JOB_KEY);
		running.value = false;
		return;
	}

	try {
		await driveJob(state, 'migratekit_export_step', trackJob, () => stopping.value);

		if (stopping.value) {
			return;
		}

		finishRun();
	} catch (caught) {
		if (stopping.value) {
			return;
		}

		error.value = caught instanceof Error ? caught.message : 'The backup could not continue.';
		failedJobId.value = id;
	} finally {
		running.value = false;
	}
}

/** Reload every stat source; the retry links on failed tiles call this. */
async function reloadAll(): Promise<void> {
	const [backupResult, cloudResult] = await Promise.allSettled([
		post<{ backups: Backup[] }>('migratekit_backups_list'),
		post<{ providers: Provider[] }>('migratekit_cloud_status'),
	]);

	backups.value = backupResult.status === 'fulfilled' ? backupResult.value.backups : null;
	providers.value = cloudResult.status === 'fulfilled' ? cloudResult.value.providers : null;

	await loadSchedules();
}

/** Leaving mid-backup stalls the job; make the tab close deliberate. */
function confirmLeave(event: BeforeUnloadEvent): void {
	if (running.value) {
		event.preventDefault();
	}
}

/** The dashboard starter button also flips on automatic backups in one click. */
async function createStarter(): Promise<void> {
	if (starterBusy.value) {
		return;
	}

	starterBusy.value = true;
	error.value = '';

	try {
		await saveStarterSchedule(adminEmail.value);
		notice.value = 'Automatic backups are on — one runs every day, and you get an email after each one.';
		schedules.value = null;
		await loadSchedules();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to create the schedule.';
	} finally {
		starterBusy.value = false;
	}
}

async function loadSchedules(): Promise<void> {
	try {
		const data = await post<{ schedules: Schedule[]; admin_email?: string }>('migratekit_schedules_list');
		schedules.value = data.schedules;
		adminEmail.value = typeof data.admin_email === 'string' ? data.admin_email : '';
	} catch {
		schedules.value = null;
	}
}

onMounted(() => {
	window.addEventListener('beforeunload', confirmLeave);

	void reloadAll().then(() => resumeActiveJob());
});

onUnmounted(() => {
	window.removeEventListener('beforeunload', confirmLeave);
});

function nextRunText(schedule: Schedule): string {
	if (!schedule.next_run) {
		return 'not run yet';
	}

	const diff = schedule.next_run - Date.now();

	return diff <= 60_000 ? 'due now' : `next run in ${inWords(diff / 1000)}`;
}
</script>

<template>
	<section class="mk-card">
		<div class="mk-card-head">
			<div>
				<h2>Welcome to MigrateKit</h2>
				<p class="mk-muted">
					Everything your site needs in one place — back up, move it anywhere, and restore with one click.
				</p>
			</div>
			<span class="mk-badge">Back up · Move · Restore</span>
		</div>

		<div class="mk-tiles">
			<a v-if="backups" class="mk-tile mk-tile-lead" href="?page=migratekit-backups">
				<h3>Last backup</h3>
				<p class="mk-big">{{ lastBackupText }}</p>
				<p class="mk-sub">
					<template v-if="backups.length">
						{{ backups.length }} {{ backups.length === 1 ? 'backup' : 'backups' }} ·
						{{ formatBytes(backupTotalSize) }} stored
					</template>
					<template v-else>No backups yet — the button below fixes that in one click</template>
				</p>
			</a>
			<div v-else class="mk-tile mk-tile-lead">
				<h3>Last backup</h3>
				<p class="mk-big">…</p>
				<p class="mk-sub">
					<span class="mk-inline-error"
						>Could not load — <button class="mk-link" type="button" @click="reloadAll">Try again</button></span
					>
				</p>
			</div>
			<div class="mk-tile">
				<h3>Automatic backups</h3>
				<p class="mk-big">{{ schedules === null ? '…' : enabledSchedules.length }}</p>
				<p class="mk-sub">
					<template v-if="schedules && enabledSchedules.length > 0">
						{{ enabledSchedules.length === 1 ? 'schedule' : 'schedules' }} on ·
						<template v-if="nextSchedule">
							{{ friendlyInterval(nextSchedule.interval) }} · {{ nextRunText(nextSchedule) }}
						</template>
					</template>
					<template v-else-if="schedules">Not turned on yet</template>
					<template v-else>
						<span class="mk-inline-error"
							>Could not load — <button class="mk-link" type="button" @click="reloadAll">Try again</button></span
						>
					</template>
				</p>
			</div>
			<div class="mk-tile">
				<h3>Cloud storage</h3>
				<p class="mk-big">{{ providers === null ? '…' : readyProviders.length }}</p>
				<p class="mk-sub">
					<template v-if="providers && readyProviders.length">
						{{ readyProviders.map((provider) => provider.label).join(', ') }} ready
					</template>
					<template v-else-if="providers">Not connected — backups stay on this server</template>
					<template v-else>
						<span class="mk-inline-error"
							>Could not load — <button class="mk-link" type="button" @click="reloadAll">Try again</button></span
						>
					</template>
				</p>
			</div>
		</div>

		<div v-if="notice" class="mk-success">{{ notice }}</div>
		<p v-if="error" class="mk-error">
			{{ error }}
			<button v-if="failedJobId" class="mk-link" type="button" @click="backUpNow">Try the backup again</button>
		</p>

		<p v-if="backups && backups.length === 0" class="mk-warn">
			You have no backups yet — a single click on "Back up this site" fixes that.
		</p>

		<div class="mk-action-grid">
			<div class="mk-action-card">
				<h3>Back up this site</h3>
				<p>
					Packages the database, media, themes and plugins into one <span class="mk-mono">.wpress</span> file and keeps
					it under Backups for safekeeping.
				</p>
				<div class="mk-actions">
					<button class="mk-btn" type="button" :disabled="running" @click="backUpNow">
						{{ running ? 'Backing up…' : 'Back up now' }}
					</button>
					<a class="mk-link" href="?page=migratekit-export">More options — find &amp; replace, exclusions →</a>
				</div>

				<div v-if="job" class="mk-progress">
					<div
						class="mk-bar"
						role="progressbar"
						aria-label="Backup progress"
						aria-valuemin="0"
						aria-valuemax="100"
						:aria-valuenow="job.percent"
					>
						<span :style="{ width: job.percent + '%' }"></span>
					</div>
					<div class="mk-progress-meta">
						<span>{{ stopping ? 'Stopping…' : job.message || 'Working…' }}</span>
						<span class="mk-progress-side">
							<span>{{ job.percent }}%</span>
							<button v-if="running" class="mk-link" type="button" :disabled="stopping" @click="stopBackup">
								Stop
							</button>
						</span>
					</div>
					<p v-if="running" class="mk-status">
						Leaving this page is safe — the backup pauses and resumes when you return, and your live site is never touched.
					</p>
					<span class="mk-sr-only" aria-live="polite">{{ announcement }}</span>
				</div>
			</div>

			<div class="mk-action-card">
				<h3>Move this site</h3>
				<ol class="mk-steps">
					<li>Export an archive here (one click).</li>
					<li>Install MigrateKit on the destination site.</li>
					<li>Import the archive there — URLs are rewritten automatically.</li>
				</ol>
				<div class="mk-actions">
					<a class="mk-btn mk-btn-ghost" href="?page=migratekit-export">Export this site</a>
					<a class="mk-link" href="?page=migratekit-import">Already have the archive? Import it →</a>
				</div>
			</div>

			<div class="mk-action-card">
				<h3>Restore or reuse a backup</h3>
				<p>
					Any backup kept on this server can be restored in place with live progress — no uploading required.
				</p>
				<a class="mk-btn mk-btn-ghost" href="?page=migratekit-backups">Open Backups</a>
			</div>

			<div class="mk-action-card">
				<h3>Set it and forget it</h3>
				<p>
					Turn on automatic backups once: a fresh archive is taken on schedule, old ones are pruned and you get an
					email after every run.
				</p>
				<div class="mk-actions">
					<button
						v-if="schedules !== null && enabledSchedules.length === 0"
						class="mk-btn mk-btn-ghost"
						type="button"
						:disabled="starterBusy"
						@click="createStarter"
					>
						{{ starterBusy ? 'Turning on…' : 'Turn on daily backups' }}
					</button>
					<a class="mk-link" href="?page=migratekit-schedules">Manage schedules →</a>
				</div>
			</div>
		</div>

		<p v-if="done && job" class="mk-success">
			Backup created: <strong class="mk-mono">{{ job.archiveName }}</strong> — <a href="?page=migratekit-backups">view it under Backups</a>.
		</p>

		<p class="mk-dash-foot">
			{{ config.siteCount === 1 ? 'This site' : `This network (${config.siteCount} sites)` }} is served by MigrateKit
			{{ config.version }} · Reset erases your content for a clean slate — a safety backup is taken first. <a href="?page=migratekit-reset">Open Reset</a>
		</p>
	</section>
</template>
