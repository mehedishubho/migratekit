<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { config, formatBytes, formatDate, post, type JobState } from '../api';
import JobProgress from '../JobProgress.vue';
import {
	hideInline,
	isActive,
	launch,
	settled,
	showInline,
	withIntents,
	type JobIntent,
	type TrackedJob,
} from '../jobs';

interface ProviderStatus {
	id: string;
	label: string;
	ready: boolean;
	auth: boolean;
	authUrl: string;
	easy?: boolean;
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
	options?: Array<{ value: string; label: string }>;
}

const S3_FIELDS: FieldSpec[] = [
	{ key: 'endpoint', label: 'Endpoint', placeholder: 'https://s3.amazonaws.com' },
	{ key: 'bucket', label: 'Bucket', placeholder: 'my-site-backups' },
	{ key: 'prefix', label: 'Folder prefix', hint: 'optional', placeholder: 'backups' },
	{ key: 'region', label: 'Region', placeholder: 'us-east-1' },
	{ key: 'access_key', label: 'Access key' },
	{ key: 'secret_key', label: 'Secret key', type: 'password' },
];

const DROPBOX_FIELDS: FieldSpec[] = [
	{ key: 'client_id', label: 'App key', hint: 'your own Dropbox app' },
	{ key: 'client_secret', label: 'App secret', type: 'password' },
	{ key: 'folder', label: 'Folder inside the app', hint: 'optional', placeholder: 'backups' },
];

const DRIVE_FOLDER_FIELDS: FieldSpec[] = [
	{ key: 'folder', label: 'Drive folder ID', hint: 'optional — defaults to My Drive', placeholder: '1AbC...' },
];

const OAUTH_FIELDS: FieldSpec[] = [
	{ key: 'client_id', label: 'Client ID' },
	{ key: 'client_secret', label: 'Client secret', type: 'password' },
];

const providers = ref<ProviderStatus[]>([]);
const objects = ref<RemoteObject[]>([]);
const backups = ref<Backup[]>([]);
const selected = ref('s3');
const loading = ref(false);
const filesLoading = ref(false);
const saving = ref('');
const testing = ref('');
const connecting = ref('');
const finishing = ref('');
const notice = ref('');
const error = ref('');
const pushSource = ref('');
const drafts = ref<Record<string, Record<string, string>>>({});

// One-click connect: the server returns an authorisation URL plus a state
// token, Dropbox displays a code in the new tab, and the pasted code closes
// the loop. Null while no easy connection is in flight.
const pasteConnect = ref<string | null>(null);
const pasteCode = ref('');
const driveMode = ref<'service' | 'oauth'>('service');
const dropboxAdvanced = ref(false);

const current = computed(() => providers.value.find((p) => p.id === selected.value) || null);
const currentLabel = computed(() => current.value?.label || selected.value);
const readyProviders = computed(() => providers.value.filter((p) => p.ready));

/** The transfers this page shows: the ones started here, or the ones an earlier visit left unfinished. */
const transfers = withIntents('push', 'pull', 'cloud-restore', 'restore');
const transferring = computed(() => transfers.value.some(isActive));
const transfersBusy = computed(() => transferring.value || filesLoading.value);
const isS3 = computed(() => selected.value === 's3');
const isDropbox = computed(() => selected.value === 'dropbox');
const isDrive = computed(() => selected.value === 'gdrive');
const easyDropbox = computed(
	() => isDropbox.value && current.value?.easy === true && field(current.value, 'client_id') === ''
);
const dropboxConnected = computed(() => isDropbox.value && connected(current.value));
const driveServiceMode = computed(() => driveMode.value === 'service');
const driveConnected = computed(() => isDrive.value && connected(current.value));
const dropboxLegacyReady = computed(
	() => field(current.value, 'client_id') !== '' && field(current.value, 'client_secret') !== ''
);
const driveOauthReady = computed(
	() => field(current.value, 'client_id') !== '' && field(current.value, 'client_secret') !== ''
);

function labelFor(id: string): string {
	return providers.value.find((p) => p.id === id)?.label || id;
}

function field(provider: ProviderStatus | null, key: string): string {
	const value = provider?.settings[key];
	return value === undefined || value === null ? '' : String(value);
}

function draft(provider: ProviderStatus): Record<string, string> {
	if (!drafts.value[provider.id]) {
		drafts.value[provider.id] = {
			endpoint: field(provider, 'endpoint'),
			bucket: field(provider, 'bucket'),
			prefix: field(provider, 'prefix'),
			region: field(provider, 'region'),
			access_key: field(provider, 'access_key'),
			secret_key: field(provider, 'secret_key'),
			client_id: field(provider, 'client_id'),
			client_secret: field(provider, 'client_secret'),
			folder: field(provider, 'folder'),
			service_account: maskedServiceAccount(provider),
			auth_mode: field(provider, 'auth_mode') || 'oauth',
		};
	}

	return drafts.value[provider.id];
}

function maskedServiceAccount(provider: ProviderStatus): string {
	if (field(provider, 'service_account') !== '') {
		return field(provider, 'service_account');
	}

	const email = field(provider, 'client_email');

	return email !== '' ? `********  (${email})` : '';
}

function account(provider: ProviderStatus | null): string {
	const value = provider?.settings['account'];
	return typeof value === 'string' ? value : '';
}

function connected(provider: ProviderStatus | null): boolean {
	const value = provider?.settings['connected'];
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

		const drive = providers.value.find((p) => p.id === 'gdrive');

		if (drive) {
			driveMode.value = field(drive, 'auth_mode') === 'oauth' ? 'oauth' : 'service';
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
	pasteConnect.value = null;
	pasteCode.value = '';
	dropboxAdvanced.value = false;

	const drive = providers.value.find((p) => p.id === 'gdrive');

	driveMode.value = id === 'gdrive' && field(drive || null, 'auth_mode') !== 'oauth' ? 'service' : 'oauth';

	draft(
		providers.value.find((p) => p.id === id) || {
			id,
			label: id,
			ready: false,
			auth: false,
			authUrl: '',
			easy: false,
			settings: {},
		}
	);
	loadObjects();
}

function payload(provider: ProviderStatus): Record<string, unknown> {
	const values = draft(provider);
	const body: Record<string, unknown> = { provider: provider.id };

	if (provider.id === 's3') {
		for (const spec of S3_FIELDS) {
			body[spec.key] = values[spec.key] ?? '';
		}

		return body;
	}

	if (provider.id === 'dropbox') {
		if (easyDropbox.value && !dropboxAdvanced.value) {
			// Easy mode: the folder is the only field on file.
			body['folder'] = values.folder ?? '';

			return body;
		}

		body['client_id'] = values.client_id ?? '';
		body['client_secret'] = values.client_secret ?? '';
		body['folder'] = values.folder ?? '';

		return body;
	}

	if (driveServiceMode.value) {
		const raw = (values.service_account || '').trim();
		const masked = raw === '' || raw === '********' || raw.startsWith('********');

		body['service_account'] = masked ? '********' : raw;
		body['folder'] = values.folder ?? '';

		return body;
	}

	if (field(provider, 'service_account') !== '' || field(provider, 'client_email') !== '') {
		// Switching to OAuth must not erase a stored service-account key.
		body['service_account'] = '********';
	}

	body['client_id'] = values.client_id ?? '';
	body['client_secret'] = values.client_secret ?? '';
	body['folder'] = values.folder ?? '';

	return body;
}

async function save(provider: ProviderStatus): Promise<void> {
	saving.value = provider.id;
	error.value = '';

	try {
		const data = await post<{ providers: ProviderStatus[] }>('migratekit_cloud_save', payload(provider));
		providers.value = data.providers;
		drafts.value[provider.id] = {};
		draft(providers.value.find((p) => p.id === provider.id) || provider);
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
		drafts.value[provider.id] = {};
		draft(providers.value.find((p) => p.id === provider.id) || provider);
		notice.value = `${provider.label} connection OK.`;
		await loadObjects();
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
		dropboxAdvanced.value = false;
		draft(providers.value.find((p) => p.id === provider.id) || provider);
		notice.value = `${provider.label} disconnected.`;
		await loadObjects();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to disconnect the provider.';
	}
}

async function connect(provider: ProviderStatus): Promise<void> {
	connecting.value = provider.id;
	error.value = '';

	// Open the tab inside the click gesture so browsers do not treat the
	// consent window as a popup; easy flows need it, legacy ones navigate.
	const willPaste = provider.id === 'dropbox' && easyDropbox.value;
	const consentTab = willPaste ? window.open('', '_blank') : null;

	try {
		const data = await post<{ url: string; state: string; paste: boolean }>('migratekit_cloud_connect', {
			provider: provider.id,
		});

		if (data.paste) {
			pasteConnect.value = data.state;

			if (consentTab) {
				consentTab.location.href = data.url;
			} else {
				window.open(data.url, '_blank', 'noopener');
			}

			notice.value = `${provider.label} opened in a new tab. Copy the authorisation code shown there and paste it below.`;
			return;
		}

		window.location.href = data.url;
	} catch (caught) {
		consentTab?.close();
		error.value = caught instanceof Error ? caught.message : `Unable to start the ${provider.label} connection.`;
	} finally {
		connecting.value = '';
	}
}

async function finishConnect(): Promise<void> {
	const token = pasteConnect.value;

	if (!token || pasteCode.value.trim() === '') {
		return;
	}

	finishing.value = 'dropbox';
	error.value = '';

	try {
		const data = await post<{ providers: ProviderStatus[] }>('migratekit_cloud_callback', {
			provider: selected.value,
			code: pasteCode.value.trim(),
			state: token,
		});
		providers.value = data.providers;
		pasteConnect.value = null;
		pasteCode.value = '';
		notice.value = `${currentLabel.value} connected. Backups on that account are listed below.`;
		await loadObjects();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The authorisation code was not accepted.';
	} finally {
		finishing.value = '';
	}
}

function cancelConnect(): void {
	pasteConnect.value = null;
	pasteCode.value = '';
	notice.value = '';
}

/** Start a transfer and show it in the list; resolves once it has come to rest. */
async function startTransfer(intent: JobIntent, start: Promise<JobState>): Promise<TrackedJob | null> {
	try {
		return await launch(intent, start);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The transfer could not be started.';

		return null;
	}
}

async function pull(remote: RemoteObject): Promise<void> {
	if (transfersBusy.value) {
		return;
	}

	notice.value = '';
	error.value = '';

	await startTransfer(
		'pull',
		post<JobState>('migratekit_cloud_pull', { provider: selected.value, remote: remote.name }),
	);
}

async function push(): Promise<void> {
	if (transfersBusy.value || !pushSource.value) {
		return;
	}

	notice.value = '';
	error.value = '';

	await startTransfer(
		'push',
		post<JobState>('migratekit_cloud_push', { provider: selected.value, name: pushSource.value }),
	);
}

/** Fetch a remote backup and restore it on this site, all in one go. */
async function restoreRemote(remote: RemoteObject): Promise<void> {
	if (transfersBusy.value) {
		return;
	}

	if (
		!window.confirm(
			`Restore ${remote.name} on this site? It is fetched from ${currentLabel.value} first, then this site's content, database and files are replaced.`,
		)
	) {
		return;
	}

	notice.value = '';
	error.value = '';

	await startTransfer(
		'cloud-restore',
		post<JobState>('migratekit_cloud_pull', { provider: selected.value, remote: remote.name, restore: true }),
	);
}

// A transfer that came to rest changed what is here and what is on the cloud.
watch(settled, () => {
	void loadObjects();
	void loadBackups();
});

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
	// The page draws its transfers' progress where it always did; the panel above leaves them to the page.
	showInline('push', 'pull', 'cloud-restore', 'restore');

	const params = new URLSearchParams(window.location.search);

	if (params.get('notice') === 'connected' && params.get('cloud')) {
		const id = params.get('cloud') || '';
		notice.value = `${labelFor(id)} connected successfully. Backups on that account are listed below.`;
	}

	void loadProviders();
});

onUnmounted(() => hideInline('push', 'pull', 'cloud-restore', 'restore'));
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

		<JobProgress v-for="job in transfers" :key="job.key" :job="job" />

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
				<h3>{{ currentLabel }}</h3>
				<p v-if="connected(current)" class="mk-muted">
					Connected<template v-if="account(current)"> as <strong>{{ account(current) }}</strong></template>.
				</p>

				<!-- S3-compatible: access keys with a helping hand -->
				<template v-if="isS3">
					<div v-for="spec in S3_FIELDS" :key="spec.key" class="mk-field">
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

					<p class="mk-note">
						<strong>Where to find these:</strong> create a bucket and an access key in the
						<a href="https://console.aws.amazon.com/iam/" target="_blank" rel="noreferrer">AWS console</a>.
						Any S3-compatible storage works here — DigitalOcean Spaces, Wasabi, Backblaze B2, Cloudflare R2, MinIO.
						The key must be allowed to read <em>and</em> write: on Cloudflare R2, create the API token with
						"Object Read and Write".
					</p>
				</template>

				<!-- Dropbox: one-click with the shipped app key -->
				<template v-if="isDropbox && current">
					<template v-if="dropboxConnected && !dropboxAdvanced">
						<label class="mk-field">
							<span>Folder inside the app</span>
							<input
								v-model="draft(current).folder"
								type="text"
								placeholder="backups"
								:disabled="transfersBusy"
								spellcheck="false"
							/>
						</label>
						<button class="mk-link" type="button" :disabled="transfersBusy" @click="dropboxAdvanced = true">
							Use my own Dropbox app instead
						</button>
						<p class="mk-note">
							<strong>Folder note:</strong> archives land inside your Dropbox <code>Apps</code> area under
							the connected app's folder {{ draft(current).folder && draft(current).folder !== '' ? ' > ' + draft(current).folder : '' }}.
						</p>
					</template>

					<template v-else-if="easyDropbox && !dropboxConnected && pasteConnect === null">
						<ol class="mk-steps">
							<li>Click <strong>Connect Dropbox</strong> — an authorisation window opens.</li>
							<li>Allow access. Dropbox shows a short authorisation code.</li>
							<li>Paste the code below — done. No app setup needed.</li>
						</ol>
						<button class="mk-link" type="button" :disabled="transfersBusy" @click="dropboxAdvanced = true">
							Use my own Dropbox app instead
						</button>
					</template>

					<template v-else-if="pasteConnect !== null">
						<label class="mk-field">
							<span>Authorisation code from Dropbox</span>
							<input
								v-model="pasteCode"
								type="text"
								placeholder="e.g. ABr4xz9pQ…"
								autocomplete="off"
								spellcheck="false"
							/>
						</label>
						<div class="mk-actions">
							<button
								class="mk-btn"
								type="button"
								:disabled="finishing === 'dropbox' || pasteCode.trim() === ''"
								@click="finishConnect()"
							>
								{{ finishing === 'dropbox' ? 'Connecting…' : 'Finish connecting' }}
							</button>
							<button class="mk-btn mk-btn-ghost" type="button" @click="cancelConnect()">Cancel</button>
						</div>
					</template>

					<!-- No built-in app key available (or the user opted out):
					     the own-app form is the only path, so always show it. -->
					<template v-else-if="dropboxAdvanced || !easyDropbox">
						<div v-for="spec in DROPBOX_FIELDS" :key="'d-' + spec.key" class="mk-field">
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
						<p class="mk-note">
							<strong>One-time setup:</strong> create an app on the
							<a href="https://www.dropbox.com/developers/apps" target="_blank" rel="noreferrer">Dropbox app console</a>,
							then add this authorisation redirect URL to it:
							<code>{{ config.adminPost }}?action=migratekit_cloud_dropbox_callback</code>
						</p>
					</template>
				</template>

				<!-- Google Drive: service account (easy) or OAuth client -->
				<template v-if="isDrive && current">
					<div class="mk-field">
						<span>Connection type</span>
						<div class="mk-seg">
							<button
								type="button"
								:class="['mk-seg-btn', { active: driveServiceMode }]"
								:disabled="transfersBusy"
								@click="driveMode = 'service'"
							>
								Service account — recommended
							</button>
							<button
								type="button"
								:class="['mk-seg-btn', { active: !driveServiceMode }]"
								:disabled="transfersBusy"
								@click="driveMode = 'oauth'"
							>
								OAuth client
							</button>
						</div>
					</div>

					<template v-if="driveServiceMode">
						<label class="mk-field">
							<span>Service account JSON key</span>
							<textarea
								v-model="draft(current).service_account"
								class="mk-textarea"
								rows="7"
								placeholder='{"type":"service_account","project_id":… }'
								:disabled="transfersBusy"
								spellcheck="false"
							></textarea>
						</label>
						<label v-for="spec in DRIVE_FOLDER_FIELDS" :key="spec.key" class="mk-field">
							<span>
								{{ spec.label }}
								<em>{{ spec.hint }}</em>
							</span>
							<input
								v-model="draft(current)[spec.key]"
								type="text"
								:placeholder="spec.placeholder"
								:disabled="transfersBusy"
								spellcheck="false"
							/>
						</label>
						<ol class="mk-steps">
							<li>Open the <a href="https://console.cloud.google.com/" target="_blank" rel="noreferrer">Google Cloud console</a>, then enable the <strong>Google Drive API</strong> for a project.</li>
							<li>Create a <strong>service account</strong> (IAM) and add a <strong>JSON key</strong> to it.</li>
							<li>Paste the JSON contents above and save.</li>
							<li>In Drive, create a folder, <strong>share it with the service account address</strong> shown after testing, then push.</li>
						</ol>
					</template>

					<template v-else>
						<div v-for="spec in [...OAUTH_FIELDS, ...DRIVE_FOLDER_FIELDS]" :key="'g-' + spec.key" class="mk-field">
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
						<p class="mk-note">
							<strong>One-time setup:</strong> add this authorisation redirect URL to your Google Cloud OAuth client:
							<code>{{ config.adminPost }}?action=migratekit_cloud_gdrive_callback</code>
						</p>
					</template>
				</template>

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
						v-if="connected(current) && !isS3"
						class="mk-btn mk-btn-ghost"
						type="button"
						:disabled="transfersBusy"
						@click="disconnect(current)"
					>
						Disconnect
					</button>
				</div>

				<div class="mk-actions">
					<button
						v-if="easyDropbox && !dropboxConnected && pasteConnect === null"
						class="mk-btn"
						type="button"
						:disabled="connecting === current.id"
						@click="connect(current)"
					>
						{{ connecting === current.id ? 'Opening Dropbox…' : 'Connect Dropbox' }}
					</button>
					<button
						v-else-if="isDropbox && (dropboxAdvanced || !easyDropbox) && dropboxLegacyReady && !dropboxConnected"
						class="mk-btn"
						type="button"
						:disabled="transfersBusy"
						@click="connect(current)"
					>
						Connect Dropbox
					</button>
					<button
						v-if="isDrive && !driveServiceMode && !driveConnected && driveOauthReady"
						class="mk-btn"
						type="button"
						:disabled="transfersBusy"
						@click="connect(current)"
					>
						Connect Google Drive
					</button>
				</div>
			</div>

			<div v-if="current.ready" class="mk-editor">
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
									@click="restoreRemote(remote)"
								>
									Restore
								</button>
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

			<div v-else class="mk-editor">
				<div class="mk-empty">
					<strong>Not connected yet</strong>
					<span>
						Save the settings above, then {{ isDrive ? 'run Test connection' : 'connect account' }} — transfers
						appear here.
					</span>
				</div>
			</div>
		</template>

		<div v-else class="mk-empty">
			<strong>No cloud providers</strong>
			<span>Something went wrong loading the provider registry.</span>
		</div>
	</section>
</template>
