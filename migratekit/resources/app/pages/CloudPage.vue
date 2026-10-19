<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { config, formatBytes, formatDate, post, runJob } from '../api';
import type { JobState } from '../api';

interface ProviderStatus {
	id: string;
	label: string;
	ready: boolean;
	auth: boolean;
	authUrl: string;
	settings: Record<string, unknown>;
}

interface RemoteObject {
	name: string;
	size: number;
	mtime: number;
}

interface Backup {
	name: string;
	size: number;
	mtime: number;
	downloadUrl: string;
}

interface FieldSpec {
	key: string;
	label: string;
	type?: string;
	hint?: string;
	placeholder?: string;
}

const FIELDS: Record<string, FieldSpec[]> = {
	s3: [
		{ key: 'endpoint', label: 'Endpoint', placeholder: 'https://s3.amazonaws.com' },
		{ key: 'bucket', label: 'Bucket', placeholder: 'my-site-backups' },
		{ key: 'prefix', label: 'Folder prefix', hint: 'optional', placeholder: 'backups' },
		{ key: 'region', label: 'Region', placeholder: 'us-east-1' },
		{ key: 'access_key', label: 'Access key' },
		{ key: 'secret_key', label: 'Secret key', type: 'password' },
	],
	dropbox: [
		{ key: 'client_id', label: 'App key' },
		{ key: 'client_secret', label: 'App secret', type: 'password' },
		{ key: 'folder', label: 'Folder', hint: 'optional', placeholder: 'backups' },
	],
	gdrive: [
		{ key: 'client_id', label: 'Client ID' },
		{ key: 'client_secret', label: 'Client secret', type: 'password' },
		{ key: 'folder', label: 'Drive folder ID', hint: 'optional' },
	],
};

const REDIRECT_ACTION: Record<string, string> = {
	dropbox: 'migratekit_cloud_dropbox_callback',
	gdrive: 'migratekit_cloud_gdrive_callback',
};

const providers = ref<ProviderStatus[]>([]);
const objects = ref<RemoteObject[]>([]);
const backups = ref<Backup[]>([]);
const selected = ref('s3');
const loading = ref(false);
const filesLoading = ref(false);
const saving = ref('');
const testing = ref('');
const notice = ref('');
const error = ref('');
const pushSource = ref('');
const state = ref<JobState | null>(null);
const transferring = ref(false);
const drafts = ref<Record<string, Record<string, string>>>({});

const current = computed(() => providers.value.find((p) => p.id === selected.value) || null);
const currentLabel = computed(() => current.value?.label || selected.value);
const readyProviders = computed(() => providers.value.filter((p) => p.ready));
const currentFields = computed(() => FIELDS[selected.value] || []);
const redirectUrl = computed(() =>
	REDIRECT_ACTION[selected.value] ? `${config.adminPost}?action=${REDIRECT_ACTION[selected.value]}` : ''
);
const transfersBusy = computed(() => transferring.value || filesLoading.value);

function labelFor(id: string): string {
	return providers.value.find((p) => p.id === id)?.label || id;
}

function field(provider: ProviderStatus, key: string): string {
	const value = provider.settings[key];
	return value === undefined || value === null ? '' : String(value);
}

function draft(provider: ProviderStatus): Record<string, string> {
	if (!drafts.value[provider.id]) {
		const values: Record<string, string> = {};
		for (const spec of FIELDS[provider.id] || []) {
			values[spec.key] = field(provider, spec.key);
		}
		drafts.value[provider.id] = values;
	}
	return drafts.value[provider.id];
}

function account(provider: ProviderStatus): string {
	const value = provider.settings['account'];
	return typeof value === 'string' ? value : '';
}

function connected(provider: ProviderStatus): boolean {
	const value = provider.settings['connected'];
	return value === true || value === 1 || value === '1';
}

async function loadProviders(): Promise<void> {
	loading.value = true;
	error.value = '';

	try {
		const data = await post<{ providers: ProviderStatus[] }>('migratekit_cloud_status');
		providers.value = data.providers;

		const anyReady = readyProviders.value.length > 0;
		if (!readyProviders.value.some((p) => p.id === selected.value)) {
			selected.value = anyReady
				? readyProviders.value[0].id
				: data.providers.length > 0
					? data.providers[0].id
					: 's3';
		}

		for (const provider of data.providers) {
			draft(provider);
		}

		await Promise.all([loadObjects(), loadBackups()]);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to load the cloud settings.';
	} finally {
		loading.value = false;
	}
}

async function loadObjects(): Promise<void> {
	if (!current.value) {
		objects.value = [];
		return;
	}

	filesLoading.value = true;

	try {
		const data = await post<{ objects: RemoteObject[]; ready: boolean }>('migratekit_cloud_files', {
			provider: selected.value,
		});
		objects.value = data.objects;
	} catch (caught) {
		objects.value = [];
		error.value = caught instanceof Error ? caught.message : 'Unable to list the remote backups.';
	} finally {
		filesLoading.value = false;
	}
}

async function loadBackups(): Promise<void> {
	try {
		const data = await post<{ backups: Backup[] }>('migratekit_backups_list');
		backups.value = data.backups;

		if (!backups.value.some((b) => b.name === pushSource.value)) {
			pushSource.value = backups.value.length > 0 ? backups.value[0].name : '';
		}
	} catch {
		backups.value = [];
	}
}

function select(id: string): void {
	if (transfersBusy.value) {
		return;
	}

	selected.value = id;
	notice.value = '';
	error.value = '';
	draft(
		providers.value.find((p) => p.id === id) || {
			id,
			label: id,
			ready: false,
			auth: false,
			authUrl: '',
			settings: {},
		}
	);
	loadObjects();
}

async function save(provider: ProviderStatus): Promise<void> {
	saving.value = provider.id;
	error.value = '';
	const values = draft(provider);

	try {
		const payload: Record<string, unknown> = { provider: provider.id };
		for (const spec of FIELDS[provider.id] || []) {
			payload[spec.key] = values[spec.key] ?? '';
		}

		const data = await post<{ providers: ProviderStatus[] }>('migratekit_cloud_save', payload);
		providers.value = data.providers;
		notice.value = `${labelFor(provider.id)} settings saved.`;
		await loadObjects();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to save the settings.';
	} finally {
		saving.value = '';
	}
}

async function test(provider: ProviderStatus): Promise<void> {
	testing.value = provider.id;
	error.value = '';

	try {
		const data = await post<{ providers: ProviderStatus[] }>('migratekit_cloud_test', { provider: provider.id });
		providers.value = data.providers;
		notice.value = `${provider.label} connection OK.`;
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The connection check failed.';
	} finally {
		testing.value = '';
	}
}

async function disconnect(provider: ProviderStatus): Promise<void> {
	if (!window.confirm(`Remove the stored ${provider.label} settings and connection details?`)) {
		return;
	}

	try {
		const data = await post<{ providers: ProviderStatus[] }>('migratekit_cloud_disconnect', {
			provider: provider.id,
		});
		providers.value = data.providers;
		drafts.value[provider.id] = {};
		draft(providers.value.find((p) => p.id === provider.id) || provider);
		notice.value = `${provider.label} disconnected.`;
		await loadObjects();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to disconnect the provider.';
	}
}

function connect(provider: ProviderStatus): void {
	if (!provider.authUrl) {
		error.value = `Save the ${provider.label} app credentials first, then connect.`;
		return;
	}

	window.location.href = provider.authUrl;
}

async function pull(remote: RemoteObject): Promise<void> {
	if (transfersBusy.value) {
		return;
	}

	notice.value = '';
	error.value = '';
	transferring.value = true;

	try {
		const finished = await runJob(
			post<JobState>('migratekit_cloud_pull', { provider: selected.value, remote: remote.name }),
			'migratekit_cloud_step',
			(s) => {
				state.value = s;
			}
		);

		notice.value = `Fetched ${remote.name} from ${currentLabel.value} — it is ready in Backups.`;
		void finished;
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The download could not be completed.';
	} finally {
		transferring.value = false;
		state.value = null;
	}
}

async function push(): Promise<void> {
	if (transfersBusy.value || !pushSource.value) {
		return;
	}

	notice.value = '';
	error.value = '';
	transferring.value = true;

	try {
		const finished = await runJob(
			post<JobState>('migratekit_cloud_push', { provider: selected.value, name: pushSource.value }),
			'migratekit_cloud_step',
			(s) => {
				state.value = s;
			}
		);

		notice.value = `Pushed ${pushSource.value} to ${currentLabel.value}.`;
		void finished;
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The upload could not be completed.';
	} finally {
		transferring.value = false;
		state.value = null;
	}
}

async function remove(remote: RemoteObject): Promise<void> {
	if (!window.confirm(`Delete ${remote.name} from ${currentLabel.value}? This cannot be undone.`)) {
		return;
	}

	error.value = '';

	try {
		await post('migratekit_cloud_delete', { provider: selected.value, name: remote.name });
		notice.value = `Deleted ${remote.name} from ${currentLabel.value}.`;
		await loadObjects();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The remote backup could not be deleted.';
	}
}

onMounted(() => {
	const params = new URLSearchParams(window.location.search);

	if (params.get('notice') === 'connected' && params.get('cloud')) {
		const id = params.get('cloud') || '';
		notice.value = `${labelFor(id)} connected successfully. Backups on that account are listed below.`;
	}

	loadProviders();
});
</script>

<template>
	<section class="mk-card">
		<div class="mk-card-head">
			<div>
				<h2>Cloud Storage</h2>
				<p class="mk-muted">
					Push finished backups to S3-compatible storage, Dropbox or Google Drive — and pull them back for a
					restore. Included, no extensions.
				</p>
			</div>
		</div>

		<p v-if="error" class="mk-error">{{ error }}</p>
		<p v-if="notice" class="mk-success">{{ notice }}</p>

		<div v-if="providers.length > 1" class="mk-tabs">
			<button
				v-for="provider in providers"
				:key="provider.id"
				type="button"
				:class="['mk-tab', { active: provider.id === selected }]"
				:disabled="transfersBusy"
				@click="select(provider.id)"
			>
				{{ provider.label }}
				<span v-if="provider.ready" class="mk-dot mk-dot-ok" aria-hidden="true"></span>
				<span v-else class="mk-dot" aria-hidden="true"></span>
			</button>
		</div>

		<template v-if="current">
			<div class="mk-editor">
				<h3>{{ currentLabel }} settings</h3>
				<p v-if="connected(current)" class="mk-muted">
					Connected<template v-if="account(current)"> as <strong>{{ account(current) }}</strong></template>.
				</p>

				<div v-for="spec in currentFields" :key="spec.key" class="mk-field">
					<span>
						{{ spec.label }}
						<em v-if="spec.hint">{{ spec.hint }}</em>
					</span>
					<input
						v-model="draft(current)[spec.key]"
						:type="spec.type || 'text'"
						:placeholder="spec.placeholder"
						:autocomplete="spec.type === 'password' ? 'new-password' : 'off'"
						:disabled="transfersBusy"
						spellcheck="false"
					/>
				</div>

				<p v-if="redirectUrl" class="mk-note">
					<strong>One-time setup:</strong> paste this authorisation redirect URL into the
					{{ currentLabel }} app settings: <code>{{ redirectUrl }}</code>
				</p>

				<div class="mk-actions">
					<button class="mk-btn" type="button" :disabled="saving === current.id" @click="save(current)">
						{{ saving === current.id ? 'Saving…' : 'Save settings' }}
					</button>
					<button
						class="mk-btn mk-btn-ghost"
						type="button"
						:disabled="testing === current.id || !current.ready"
						@click="test(current)"
					>
						{{ testing === current.id ? 'Testing…' : 'Test connection' }}
					</button>
					<button
						v-if="current.auth && connected(current)"
						class="mk-btn mk-btn-ghost"
						type="button"
						:disabled="transfersBusy"
						@click="disconnect(current)"
					>
						Disconnect
					</button>
					<button
						v-if="current.auth && !connected(current)"
						class="mk-btn"
						type="button"
						:disabled="transfersBusy"
						@click="connect(current)"
					>
						Connect {{ currentLabel }}
					</button>
				</div>
			</div>

			<div class="mk-editor">
				<h3>Transfer backups</h3>

				<div class="mk-grid">
					<label class="mk-field">
						<span>Push a local backup</span>
						<select v-model="pushSource" :disabled="transfersBusy || backups.length === 0">
							<option v-for="backup in backups" :key="backup.name" :value="backup.name">
								{{ backup.name }}
							</option>
						</select>
					</label>
					<div class="mk-field mk-field-action">
						<span>&nbsp;</span>
						<button
							class="mk-btn"
							type="button"
							:disabled="transfersBusy || backups.length === 0 || !current.ready"
							@click="push"
						>
							Push to {{ currentLabel }}
						</button>
					</div>
				</div>

				<table v-if="objects.length > 0" class="mk-table">
					<thead>
						<tr>
							<th>Remote backup</th>
							<th>Size</th>
							<th>Modified</th>
							<th class="mk-right">Actions</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="remote in objects" :key="remote.name">
							<td>{{ remote.name }}</td>
							<td>{{ formatBytes(remote.size) }}</td>
							<td>{{ remote.mtime ? formatDate(remote.mtime) : '—' }}</td>
							<td class="mk-right">
								<button
									class="mk-link"
									type="button"
									:disabled="transfersBusy"
									@click="pull(remote)"
								>
									Pull here
								</button>
								<button
									class="mk-link mk-link-danger"
									type="button"
									:disabled="transfersBusy"
									@click="remove(remote)"
								>
									Delete
								</button>
							</td>
						</tr>
					</tbody>
				</table>

				<div v-else-if="filesLoading" class="mk-empty">
					<span>Listing remote backups…</span>
				</div>
				<div v-else class="mk-empty">
					<strong>No remote backups</strong>
					<span>
						Everything pushed to {{ currentLabel }} shows up here. Push one from the selector above.
					</span>
				</div>
			</div>

			<div v-if="transferring" class="mk-progress">
				<div class="mk-bar"><span :style="{ width: `${state?.percent ?? 0}%` }"></span></div>
				<div class="mk-progress-meta">
					{{ state?.message || 'Transferring…' }}
					<span>{{ state?.percent ?? 0 }}%</span>
				</div>
			</div>
		</template>

		<div v-else class="mk-empty">
			<strong>No cloud providers</strong>
			<span>Something went wrong loading the provider registry.</span>
		</div>
	</section>
</template>
