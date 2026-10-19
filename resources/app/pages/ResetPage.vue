<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { runJob, post } from '../api';
import type { JobState } from '../api';

type Phase = 'form' | 'backing-up' | 'resetting' | 'done';

const phase = ref<Phase>('form');
const confirmText = ref('');
const understood = ref(false);
const safetyBackup = ref(true);
const deleteMediaFiles = ref(false);
const deleteUsers = ref(false);
const purgeThirdParty = ref(false);
const error = ref('');
const state = ref<JobState | null>(null);
const backupName = ref('');
const summary = ref('');

const ready = computed(() => understood.value && confirmText.value.trim().toUpperCase() === 'RESET');
const running = computed(() => phase.value === 'backing-up' || phase.value === 'resetting');

const STAGE_NAMES = [
	'Survey',
	'Content tables',
	'Third-party tables',
	'Transients',
	'Users',
	'Media files',
	'Caches',
	'Summary',
];

function stageLabel(state: JobState): string {
	const at = Math.min(Math.max(state.stage, 0), STAGE_NAMES.length - 1);
	const stage = STAGE_NAMES[at];
	return state.message ? `${state.message}` : stage;
}

async function start(): Promise<void> {
	if (!ready.value || running.value) {
		return;
	}

	error.value = '';
	summary.value = '';

	const payload = {
		confirm_text: confirmText.value.trim(),
		delete_media_files: deleteMediaFiles.value,
		delete_users: deleteUsers.value,
		purge_third_party: purgeThirdParty.value,
	};

	const backupOptions = {
		compression: '',
		password: '',
		no_media: !deleteMediaFiles.value,
		no_plugins: true,
		no_themes: true,
	};

	try {
		if (safetyBackup.value) {
			phase.value = 'backing-up';
			const finished = await runJob(
				post<JobState>('migratekit_export_start', backupOptions),
				'migratekit_export_step',
				(s) => { state.value = s; },
			);
			backupName.value = finished.archiveName;
		}

		phase.value = 'resetting';
		const reset = await runJob(
			post<JobState>('migratekit_reset_start', payload),
			'migratekit_reset_step',
			(s) => { state.value = s; },
		);

		summary.value = reset.message || 'Site reset complete.';
		phase.value = 'done';
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The reset could not be completed.';
		phase.value = 'form';
	} finally {
		state.value = null;
	}
}

onMounted(() => {
	window.addEventListener('beforeunload', guardBeforeUnload);
});

onBeforeUnmount(() => {
	window.removeEventListener('beforeunload', guardBeforeUnload);
});

function guardBeforeUnload(event: BeforeUnloadEvent): string | boolean | undefined {
	if (!running.value) {
		return undefined;
	}

	event.preventDefault();
	event.returnValue = 'A reset is running. Staying on the page keeps it moving.';
	return event.returnValue;
}
</script>

<template>
	<section class="mk-card">
		<div class="mk-card-head">
			<div>
				<h2>Reset</h2>
				<p class="mk-muted">Wipe site content back to a fresh install. Theme and plugin settings stay.</p>
			</div>
		</div>

		<template v-if="phase !== 'done'">
			<p v-if="error" class="mk-error">{{ error }}</p>

			<div class="mk-note">
				<strong>What a reset removes:</strong> posts, pages, attachments, comments, terms and links —
				everything in the content tables. <strong>What stays:</strong> users, active theme and plugins,
				their settings, and the WordPress configuration.
			</div>

			<template v-if="phase === 'form'">
				<fieldset class="mk-checks">
					<legend>Reset scope</legend>
					<label class="mk-check">
						<input v-model="deleteMediaFiles" type="checkbox" />
						<span>Also remove media files from the uploads folder</span>
					</label>
					<label class="mk-check">
						<input v-model="deleteUsers" type="checkbox" />
						<span>Also remove users outside the administrator role</span>
					</label>
					<label class="mk-check">
						<input v-model="purgeThirdParty" type="checkbox" />
						<span>Also empty plugin and third-party database tables</span>
					</label>
					<label class="mk-check">
						<input v-model="safetyBackup" type="checkbox" />
						<span>Create a safety backup first <em>(recommended; stored in Backups)</em></span>
					</label>
				</fieldset>

				<div class="mk-confirm">
					<label class="mk-field">
						<span>Type <strong>RESET</strong> to confirm</span>
						<input v-model="confirmText" type="text" spellcheck="false" autocomplete="off" placeholder="RESET" />
					</label>
					<label class="mk-check">
						<input v-model="understood" type="checkbox" />
						<span>I understand that deleted content cannot be recovered by this plugin</span>
					</label>
				</div>

				<div class="mk-actions">
					<button class="mk-btn mk-btn-danger" type="button" :disabled="!ready || running" @click="start">
						Reset this site now
					</button>
				</div>
			</template>

			<template v-else>
				<div class="mk-progress">
					<div class="mk-bar"><span :style="{ width: `${state?.percent ?? 0}%` }"></span></div>
					<div class="mk-progress-meta">
						{{ phase === 'backing-up' ? 'Creating the safety backup…' : 'Resetting the site…' }}
						<span>{{ state?.percent ?? 0 }}%</span>
					</div>
					<p v-if="state" class="mk-muted mk-status">{{ stageLabel(state) }}</p>
				</div>
			</template>
		</template>

		<template v-else>
			<p class="mk-success">{{ summary }}</p>
			<p v-if="backupName" class="mk-muted">
				Safety backup saved: <strong>{{ backupName }}</strong> — restore it any time from Backups.
			</p>
			<div class="mk-actions">
				<button class="mk-btn mk-btn-ghost" type="button" @click="phase = 'form'">Back</button>
			</div>
		</template>
	</section>
</template>
