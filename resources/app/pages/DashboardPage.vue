<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { config, formatBytes, friendlyInterval, inWords, post, type JobState } from '../api';
import JobProgress from '../JobProgress.vue';
import { discardSettled, hideInline, isActive, launch, latestJob, settled, showInline } from '../jobs';
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

/** Set from the click until the server has started the job, so a second click cannot start another. */
const starting = ref(false);

/** The backup this card shows: the one started here, or the one an earlier visit left unfinished. */
const job = latestJob('backup');
const running = computed(() => starting.value || isActive(job.value));
const done = computed(() => job.value !== null && job.value.status === 'done');

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

/** The run-now button starts the job immediately and shows it like the Export page. */
async function backUpNow(): Promise<void> {
	if (running.value) {
		return;
	}

	error.value = '';
	starting.value = true;

	try {
		// A backup that gave up is dropped first, so its half-built archive does not stay on the disk.
		await discardSettled('backup');

		// Uncompressed, like the Export page's default: gzip spends minutes of CPU on a large site's already-compressed media.
		await launch('backup', post<JobState>('migratekit_export_start'));
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The backup failed.';
	} finally {
		starting.value = false;
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

/** A backup that came to rest changes what the tiles say. */
watch(settled, () => {
	void reloadAll();
});

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

// The card draws its backup's progress where it always did; the panel above leaves it to the page.
onMounted(() => {
	showInline('backup');

	void reloadAll();
});

onUnmounted(() => hideInline('backup'));

function nextRunText(schedule: Schedule): string {
	if (!schedule.next_run) {
		return 'not run yet';
	}

	// The server tells the moment in seconds.
	const diff = schedule.next_run - Date.now() / 1000;

	return diff <= 60 ? 'due now' : `next run in ${inWords(diff)}`;
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
		<p v-if="error" class="mk-error">{{ error }}</p>

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

				<JobProgress v-if="job" :job="job">
					<p v-if="done" class="mk-status">
						<a class="mk-link" href="?page=migratekit-backups">View it under Backups</a>
					</p>
				</JobProgress>
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

		<p class="mk-dash-foot">
			{{ config.siteCount === 1 ? 'This site' : `This network (${config.siteCount} sites)` }} is served by MigrateKit
			{{ config.version }} · Reset erases your content for a clean slate — a safety backup is taken first. <a href="?page=migratekit-reset">Open Reset</a>
		</p>
	</section>
</template>
