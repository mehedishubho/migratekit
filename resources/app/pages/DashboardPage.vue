<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { config, formatBytes, formatDate, friendlyInterval, inWords, post, runJob, type JobState } from '../api';
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

/** The run-now button starts the job immediately and drives it like the Export page. */
async function backUpNow(): Promise<void> {
	if (running.value) {
		return;
	}

	running.value = true;
	error.value = '';
	done.value = false;
	job.value = null;

	try {
		await runJob(
			post<JobState>('migratekit_export_start', { compression: 'gzip' }),
			'migratekit_export_step',
			(state) => {
				job.value = state;
			},
		);

		done.value = true;
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The backup failed.';
	} finally {
		running.value = false;
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

onMounted(async () => {
	const [backupResult, cloudResult] = await Promise.allSettled([
		post<{ backups: Backup[] }>('migratekit_backups_list'),
		post<{ providers: Provider[] }>('migratekit_cloud_status'),
	]);

	backups.value = backupResult.status === 'fulfilled' ? backupResult.value.backups : null;
	providers.value = cloudResult.status === 'fulfilled' ? cloudResult.value.providers : null;

	await loadSchedules();
});

function nextRunText(schedule: Schedule): string {
	if (!schedule.next_run) {
		return 'waiting for its first tick';
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
			<span class="mk-badge">The basics in 3 clicks</span>
		</div>

		<div class="mk-tiles">
			<div class="mk-tile">
				<h3>Backups on this server</h3>
				<p class="mk-big">{{ backups === null ? '…' : backups.length }}</p>
				<p class="mk-sub">
					<template v-if="backups && backups.length">
						{{ formatBytes(backupTotalSize) }} stored<template v-if="latestBackup"> · newest {{ formatDate(latestBackup.mtime) }}</template>
					</template>
					<template v-else-if="backups">None made yet</template>
					<template v-else>Could not load</template>
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
					<template v-else>Could not load</template>
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
					<template v-else>Could not load</template>
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
			</div>

			<div class="mk-action-card">
				<h3>Move this site</h3>
				<ol class="mk-steps">
					<li>Export an archive here (one click).</li>
					<li>Install MigrateKit on the destination site.</li>
					<li>Import the archive there — URLs are rewritten automatically.</li>
				</ol>
				<div class="mk-actions">
					<a class="mk-btn" href="?page=migratekit-export">Start migrating</a>
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
						class="mk-btn"
						type="button"
						:disabled="starterBusy"
						@click="createStarter"
					>
						{{ starterBusy ? 'Turning on…' : 'Turn on daily backups' }}
					</button>
					<a class="mk-btn mk-btn-ghost" href="?page=migratekit-schedules">Manage schedules</a>
				</div>
			</div>
		</div>

		<div v-if="job" class="mk-progress" role="status" aria-live="polite">
			<div class="mk-bar"><span :style="{ width: job.percent + '%' }"></span></div>
			<div class="mk-progress-meta">
				<span>{{ job.message || 'Working…' }}</span>
				<span>{{ job.percent }}%</span>
			</div>
		</div>

		<p v-if="done && job" class="mk-success">
			Backup created: <strong>{{ job.archiveName }}</strong> — <a href="?page=migratekit-backups">view it under Backups</a>.
		</p>

		<p class="mk-dash-foot">
			{{ config.siteCount === 1 ? 'This site' : `This network (${config.siteCount} sites)` }} is served by MigrateKit
			{{ config.version }} · Need a clean slate? <a href="?page=migratekit-reset">Open Reset</a>
		</p>
	</section>
</template>
