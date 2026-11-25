<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { config, formatBytes } from '../api';
import JobProgress from '../JobProgress.vue';
import {
	continuesUpload,
	discardSettled,
	hideInline,
	isActive,
	latestJob,
	pausedUpload,
	showInline,
	upload,
	type TrackedJob,
} from '../jobs';

const file = ref<File | null>(null);
const password = ref('');
const replacing = ref(false);
const error = ref('');

/** Set from the click until the first request has been answered, so a second click cannot start another. */
const starting = ref(false);

/** The upload or import this page shows: the one started here, or the one an earlier visit left unfinished. */
const job = latestJob('upload', 'import');
const running = computed(() => starting.value || isActive(job.value));
const done = computed(() => job.value !== null && job.value.status === 'done');

/** An upload no page can finish by itself: only the browser has the rest of the file. */
const waiting = computed<TrackedJob | null>(() => pausedUpload());

/** Whether the chosen file is the one the waiting upload was sending. */
const resumes = computed(() => continuesUpload(waiting.value, file.value));

function choose(event: Event): void {
	const input = event.target as HTMLInputElement;

	file.value = input.files && input.files.length > 0 ? input.files[0] : null;
	error.value = '';
}

async function start(): Promise<void> {
	if (running.value) {
		return;
	}

	const chosen = file.value;

	if (!chosen) {
		error.value = 'Choose a .wpress archive first.';
		return;
	}

	if (!replacing.value && !window.confirm('Importing replaces this site’s content and database. Continue?')) {
		return;
	}

	error.value = '';
	starting.value = true;

	try {
		if (!resumes.value) {
			// Another file, or a fresh start: whatever an earlier attempt left is of no use.
			await discardSettled('upload', 'import');
		}

		await upload(chosen, password.value, resumes.value ? waiting.value : null);
	} catch (caught) {
		// The server never took the upload: nothing is left waiting, so the page says why.
		error.value = caught instanceof Error ? caught.message : 'The import failed.';
	} finally {
		starting.value = false;
	}
}

// The page draws its upload's progress where it always did; the panel above leaves it to the page.
onMounted(() => showInline('upload', 'import'));

onUnmounted(() => hideInline('upload', 'import'));
</script>

<template>
	<section class="mk-card">
		<div class="mk-card-head">
			<div>
				<h2>Import a backup</h2>
				<p class="mk-muted">
					Restore a MigrateKit or All-in-One WP Migration archive.
				</p>
			</div>
			<span class="mk-badge">Chunked upload</span>
		</div>

		<label class="mk-drop" :class="{ 'mk-drop-active': !!file }">
			<input type="file" accept=".wpress" :disabled="running" @change="choose" />
			<strong>{{ file ? file.name : 'Choose an archive' }}</strong>
			<span class="mk-muted">
				{{ file ? formatBytes(file.size) : 'Drop a .wpress file or click to browse' }}
			</span>
		</label>

		<div class="mk-grid">
			<label class="mk-field">
				<span>Decryption password <em>if encrypted</em></span>
				<input
					v-model="password"
					type="password"
					autocomplete="off"
					placeholder="Leave empty if not encrypted"
					:disabled="running"
				/>
			</label>
			<label class="mk-check mk-check-inline">
				<input v-model="replacing" type="checkbox" :disabled="running" />
				<span>I understand this site will be replaced</span>
			</label>
		</div>

		<p class="mk-note">
			Archives of any size upload in {{ formatBytes(Math.max(384 * 1024, Math.min(config.maxUpload - 48 * 1024, 5 * 1024 * 1024))) }}
			chunks; each send retries on its own if a piece fails.
		</p>

		<div class="mk-actions">
			<button class="mk-btn" type="button" :disabled="running || !file" @click="start">
				{{ running ? 'Importing…' : resumes ? 'Continue the upload and import' : 'Import and replace' }}
			</button>
			<a v-if="done" class="mk-btn mk-btn-ghost" :href="config.siteUrl" target="_blank" rel="noopener">
				Visit site
			</a>
		</div>

		<p v-if="resumes" class="mk-status">
			This is the file of the interrupted upload — only the part that is missing is sent.
		</p>

		<JobProgress v-if="job" :job="job" />

		<p v-if="error" class="mk-error">{{ error }}</p>
	</section>
</template>
