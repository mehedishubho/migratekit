<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { config, formatBytes, formatDate, post, type JobState } from '../api';
import JobProgress from '../JobProgress.vue';
import { hideInline, isActive, launch, showInline, withIntents, type TrackedJob } from '../jobs';

interface Backup {
	name: string;
	label: string;
	size: number;
	mtime: number;
	downloadUrl: string;
}

interface ArchiveEntry {
	path: string;
	size: number;
}

const backups = ref<Backup[]>([]);
const loading = ref(false);
const error = ref('');
const notice = ref('');
const busy = ref<string | null>(null);
const selected = ref<string[]>([]);

/** Restores this page shows: the ones started here, or the ones an earlier visit left unfinished. */
const restores = withIntents('restore');
const restoring = computed(() => restores.value.some(isActive));

const explorer = ref<Backup | null>(null);
const explorePassword = ref('');
const exploreFiles = ref<ArchiveEntry[]>([]);
const exploreEncrypted = ref(false);
const exploreLoading = ref(false);
const exploring = ref(false);
const exploreError = ref('');
const exploreFilter = ref('');
const exploredValid = ref(false);

const filteredFiles = computed<ArchiveEntry[]>(() => {
	const needle = exploreFilter.value.trim().toLowerCase();

	if (needle === '') {
		return exploreFiles.value;
	}

	return exploreFiles.value.filter((entry) => entry.path.toLowerCase().includes(needle));
});

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';

	try {
		const data = await post<{ backups: Backup[] }>('migratekit_backups_list');
		backups.value = data.backups;
		selected.value = [];
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to load backups.';
	} finally {
		loading.value = false;
	}
}

function toggle(name: string): void {
	const index = selected.value.indexOf(name);

	if (index >= 0) {
		selected.value.splice(index, 1);
		return;
	}

	selected.value.push(name);
}

function allSelected(): boolean {
	return backups.value.length > 0 && selected.value.length === backups.value.length;
}

function toggleAll(): void {
	selected.value = allSelected() ? [] : backups.value.map((backup) => backup.name);
}

async function remove(name: string): Promise<void> {
	if (busy.value || !window.confirm(`Delete ${name}? This cannot be undone.`)) {
		return;
	}

	busy.value = name;

	try {
		await post('migratekit_backup_delete', { name });
		await load();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to delete the backup.';
	} finally {
		busy.value = null;
	}
}

async function removeSelected(): Promise<void> {
	if (busy.value || selected.value.length === 0) {
		return;
	}

	if (!window.confirm(`Delete ${selected.value.length} selected backup(s)? This cannot be undone.`)) {
		return;
	}

	busy.value = '__bulk__';

	try {
		await post('migratekit_backup_delete', { names: selected.value });
		await load();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to delete the backups.';
	} finally {
		busy.value = null;
	}
}

async function rename(backup: Backup): Promise<void> {
	const label = window.prompt('Label for this backup', backup.label || '');

	if (label === null) {
		return;
	}

	try {
		await post('migratekit_backup_label', { name: backup.name, label });
		backup.label = label.trim().slice(0, 255);
		notice.value = 'Backup renamed.';
		error.value = '';
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to rename the backup.';
	}
}

/** The password of an encrypted archive is asked for by the job itself, once the restore is under way. */
async function restore(name: string): Promise<void> {
	if (restoring.value) {
		return;
	}

	if (!window.confirm(`Restore ${name} on this site? Content, database and files are replaced.`)) {
		return;
	}

	error.value = '';

	try {
		await launch('restore', post<JobState>('migratekit_backup_restore', { name, password: '' }));
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The restore could not be started.';
	}
}

async function explore(backup: Backup): Promise<void> {
	explorer.value = backup;
	exploring.value = true;
	exploreFiles.value = [];
	exploreEncrypted.value = false;
	exploreError.value = '';
	exploreFilter.value = '';

	await loadListing(backup.name, '');
}

async function loadListing(name: string, password: string): Promise<void> {
	exploreLoading.value = true;
	exploreError.value = '';

	try {
		const data = await post<{ encrypted: boolean; files: ArchiveEntry[] }>(
			'migratekit_backup_files',
			{ name, password },
		);

		exploreFiles.value = data.files;
		exploreEncrypted.value = data.encrypted;
		explorePassword.value = data.encrypted ? password : '';
		exploredValid.value = true;
	} catch (caught) {
		exploreError.value = caught instanceof Error ? caught.message : 'The archive could not be listed.';
		exploredValid.value = false;
	} finally {
		exploreLoading.value = false;
	}
}

function entryUrl(entry: ArchiveEntry): string {
	const params = new URLSearchParams({
		action: 'migratekit_backup_file',
		file: explorer.value ? explorer.value.name : '',
		entry: entry.path,
		_wpnonce: config.downloadNonce,
	});

	if (exploreEncrypted.value && explorePassword.value !== '') {
		params.set('password', explorePassword.value);
	}

	return `${config.adminPost}?${params.toString()}`;
}

function closeExplorer(): void {
	explorer.value = null;
	exploreFiles.value = [];
	exploredValid.value = false;
}

// The page draws its restores' progress where it always did; the panel above leaves them to the page.
onMounted(() => {
	showInline('restore');

	void load();
});

onUnmounted(() => hideInline('restore'));
</script>

<template>
	<section class="mk-card">
		<div class="mk-card-head">
			<div>
				<h2>Backups</h2>
				<p class="mk-muted">Restore, download, rename or remove archives stored on this server.</p>
			</div>
			<button class="mk-btn mk-btn-ghost" type="button" :disabled="loading" @click="load">
				{{ loading ? 'Refreshing…' : 'Refresh' }}
			</button>
		</div>

		<p v-if="error" class="mk-error">{{ error }}</p>
		<p v-if="notice" class="mk-success">{{ notice }}</p>

		<div v-if="selected.length > 0" class="mk-actions">
			<button class="mk-btn mk-btn-danger" type="button" :disabled="busy !== null" @click="removeSelected">
				{{ busy === '__bulk__' ? 'Deleting…' : `Delete selected (${selected.length})` }}
			</button>
		</div>

		<div v-if="!loading && backups.length === 0" class="mk-empty">
			<strong>No backups yet</strong>
			<span>Create one from the Export screen.</span>
		</div>

		<table v-else class="mk-table">
			<thead>
				<tr>
					<th class="mk-col-check"><input type="checkbox" :checked="allSelected()" @change="toggleAll" /></th>
					<th>Label</th>
					<th>Archive</th>
					<th>Created</th>
					<th>Size</th>
					<th class="mk-right">Actions</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="backup in backups" :key="backup.name">
					<td class="mk-col-check">
						<input
							type="checkbox"
							:checked="selected.includes(backup.name)"
							@change="toggle(backup.name)"
						/>
					</td>
					<td>
						{{ backup.label || '—' }}
						<button class="mk-link" type="button" @click="rename(backup)">Rename</button>
					</td>
					<td class="mk-mono">{{ backup.name }}</td>
					<td>{{ formatDate(backup.mtime) }}</td>
					<td>{{ formatBytes(backup.size) }}</td>
					<td class="mk-right">
						<button
							class="mk-link"
							type="button"
							:disabled="restoring"
							@click="restore(backup.name)"
						>
							Restore
						</button>
						<button class="mk-link" type="button" @click="explore(backup)">Browse</button>
						<a class="mk-link" :href="backup.downloadUrl" :download="backup.name">Download</a>
						<button
							class="mk-link mk-link-danger"
							type="button"
							:disabled="busy === backup.name"
							@click="remove(backup.name)"
						>
							{{ busy === backup.name ? 'Deleting…' : 'Delete' }}
						</button>
					</td>
				</tr>
			</tbody>
		</table>

		<JobProgress v-for="job in restores" :key="job.key" :job="job" />

		<div v-if="explorer" class="mk-modal" role="dialog" aria-modal="true">
			<div class="mk-modal-head">
				<div>
					<h3>Inside {{ explorer.label || explorer.name }}</h3>
					<p class="mk-muted">
						{{ exploreEncrypted ? 'Encrypted archive' : 'Archive contents' }} —
						{{ exploreFiles.length }} file{{ exploreFiles.length === 1 ? '' : 's' }}
					</p>
				</div>
				<button class="mk-btn mk-btn-ghost" type="button" @click="closeExplorer">Close</button>
			</div>

			<div v-if="exploreError && !exploredValid" class="mk-password-row">
				<p class="mk-note">{{ exploreError }}</p>
				<div class="mk-grid">
					<label class="mk-field">
						<span>Decryption password</span>
						<input
							v-model="explorePassword"
							type="password"
							:placeholder="`${explorer.name} unlock password`"
						/>
					</label>
					<button
						class="mk-btn"
						type="button"
						:disabled="exploreLoading"
						@click="loadListing(explorer.name, explorePassword)"
					>
						{{ exploreLoading ? 'Unlocking…' : 'Unlock' }}
					</button>
				</div>
			</div>
			<p v-else-if="exploreError" class="mk-error">{{ exploreError }}</p>

			<div v-else class="mk-modal-body">
				<input
					v-model="exploreFilter"
					type="search"
					class="mk-filter"
					placeholder="Filter files"
					:disabled="exploreLoading"
				/>

				<div v-if="exploreLoading" class="mk-empty"><strong>Loading contents…</strong></div>

				<table v-else class="mk-table">
					<thead>
						<tr>
							<th>Path</th>
							<th class="mk-right">Size</th>
							<th class="mk-right">Download</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="entry in filteredFiles" :key="entry.path">
							<td class="mk-mono">{{ entry.path }}</td>
							<td class="mk-right">{{ formatBytes(entry.size) }}</td>
							<td class="mk-right"><a class="mk-link" :href="entryUrl(entry)">Save</a></td>
						</tr>
						<tr v-if="filteredFiles.length === 0">
							<td colspan="3">Nothing matches this filter.</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</section>
</template>
