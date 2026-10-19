<script setup lang="ts">
import { ref } from 'vue';
import { config, formatBytes, runJob, uploadImport, type JobState, type UploadProgress } from '../api';

const file = ref<File | null>(null);
const password = ref('');
const replacing = ref(false);
const running = ref(false);
const job = ref<JobState | null>(null);
const upload = ref<UploadProgress | null>(null);
const error = ref('');
const done = ref(false);

function choose(event: Event): void {
	const input = event.target as HTMLInputElement;
	file.value = input.files && input.files.length > 0 ? input.files[0] : null;
	done.value = false;
	job.value = null;
	upload.value = null;
	error.value = '';
}

async function start(): Promise<void> {
	if (running.value) {
		return;
	}

	if (!file.value) {
		error.value = 'Choose a .wpress archive first.';
		return;
	}

	if (!replacing.value && !window.confirm('Importing replaces this site’s content and database. Continue?')) {
		return;
	}

	running.value = true;
	error.value = '';
	done.value = false;
	job.value = null;
	upload.value = null;

	try {
		const first = await uploadImport(file.value, password.value, (progress) => {
			upload.value = progress;
		});

		job.value = first;

		await runJob(
			Promise.resolve(first),
			'migratekit_import_step',
			(state) => {
				job.value = state;
			},
		);

		done.value = true;
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The import failed.';
	} finally {
		running.value = false;
	}
}
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
				{{ running ? 'Importing…' : 'Import and replace' }}
			</button>
			<a v-if="done" class="mk-btn mk-btn-ghost" :href="config.siteUrl" target="_blank" rel="noopener">
				Visit site
			</a>
		</div>

		<div v-if="upload && upload.total > 0" class="mk-progress" role="status" aria-live="polite">
			<div class="mk-bar">
				<span :style="{ width: Math.min(100, Math.round((upload.received / upload.total) * 100)) + '%' }"></span>
			</div>
			<div class="mk-progress-meta">
				<span>Uploading {{ formatBytes(upload.received) }} of {{ formatBytes(upload.total) }}</span>
				<span>{{ Math.min(100, Math.round((upload.received / upload.total) * 100)) }}%</span>
			</div>
		</div>

		<div v-if="job" class="mk-progress" role="status" aria-live="polite">
			<div class="mk-bar"><span :style="{ width: job.percent + '%' }"></span></div>
			<div class="mk-progress-meta">
				<span>{{ job.message || 'Working…' }}</span>
				<span>{{ job.percent }}%</span>
			</div>
		</div>

		<p v-if="done" class="mk-success">Import complete. Re-save your permalinks if anything looks off.</p>
		<p v-if="error" class="mk-error">{{ error }}</p>
	</section>
</template>
