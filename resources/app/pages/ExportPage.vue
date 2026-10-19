<script setup lang="ts">
import { computed, ref } from 'vue';
import { config, post, runJob, type JobState } from '../api';

const compression = ref('');
const password = ref('');
const scope = ref(config.isSubsite ? 'subsite' : '');
const options = ref([
	{ key: 'no_media', label: 'Skip media library', value: false },
	{ key: 'no_plugins', label: 'Skip plugins', value: false },
	{ key: 'no_themes', label: 'Skip themes', value: false },
	{ key: 'no_muplugins', label: 'Skip must-use plugins', value: false },
	{ key: 'no_cache', label: 'Skip cache files', value: false },
	{ key: 'no_database', label: 'Skip database', value: false },
	{ key: 'no_post_revisions', label: 'Skip post revisions', value: false },
	{ key: 'no_spam_comments', label: 'Skip spam comments', value: false },
	{ key: 'no_email_replace', label: 'Keep email domains untouched', value: false },
	{ key: 'no_inactive_plugins', label: 'Only active plugin files', value: false },
	{ key: 'no_inactive_themes', label: 'Only the active theme', value: false },
]);

const rows = ref<Array<{ find: string; replace: string }>>([]);
const excludedTables = ref('');
const includedTables = ref('');

const running = ref(false);
const job = ref<JobState | null>(null);
const error = ref('');
const done = ref(false);
const showAdvanced = ref(false);

type Scope = { value: string; label: string };

const scopes = computed<Scope[]>(() => {
	if (!config.isMultisite) {
		return [];
	}

	if (config.isSubsite) {
		return [
			{ value: 'subsite', label: 'This site only' },
			{
				value: 'whole',
				label: config.siteCount > 1 ? `Entire network (${config.siteCount} sites)` : 'Entire network',
			},
		];
	}

	return [
		{ value: '', label: 'This site only' },
		{
			value: 'whole',
			label: config.siteCount > 1 ? `Entire network (${config.siteCount} sites)` : 'Entire network',
		},
	];
});

const headline = computed(() =>
	scope.value === 'whole' ? 'Export the whole network' : 'Export this site',
);

const subtitle = computed(() =>
	scope.value === 'whole'
		? `Package every site in the network with shared users, subsites and media into one archive.`
		: 'Package the database, media, themes and plugins into one archive you can restore anywhere.',
);

function addRow(): void {
	rows.value.push({ find: '', replace: '' });
}

function removeRow(index: number): void {
	rows.value.splice(index, 1);
}

function splitTables(input: string): string[] {
	return input
		.split(/[,\s]+/)
		.map((value) => value.trim())
		.filter((value) => /^[A-Za-z0-9._$-]+$/.test(value));
}

async function start(): Promise<void> {
	if (running.value) {
		return;
	}

	const pairs = rows.value.filter((row) => row.find.trim() !== '');
	const excluded = splitTables(excludedTables.value);
	const included = splitTables(includedTables.value);

	running.value = true;
	error.value = '';
	done.value = false;
	job.value = null;

	try {
		const payload: Record<string, unknown> = {
			compression: compression.value,
			password: password.value,
			network: scope.value,
			replace_old: pairs.map((row) => row.find),
			replace_new: pairs.map((row) => row.replace),
			excluded_tables: excluded,
			included_tables: included,
		};

		for (const option of options.value) {
			payload[option.key] = option.value;
		}

		await runJob(
			post<JobState>('migratekit_export_start', payload),
			'migratekit_export_step',
			(state) => {
				job.value = state;
			},
		);

		done.value = true;
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'The export failed.';
	} finally {
		running.value = false;
	}
}
</script>

<template>
	<section class="mk-card">
		<div class="mk-card-head">
			<div>
				<h2>{{ headline }}</h2>
				<p class="mk-muted">
					{{ subtitle }}
				</p>
			</div>
			<span class="mk-badge">Any size</span>
		</div>

		<div class="mk-grid">
			<template v-if="scopes.length > 1">
				<label class="mk-field">
					<span>Scope</span>
					<select v-model="scope" :disabled="running">
						<option v-for="choice in scopes" :key="choice.value" :value="choice.value">
							{{ choice.label }}
						</option>
					</select>
				</label>
			</template>
			<label class="mk-field">
				<span>Compression</span>
				<select v-model="compression" :disabled="running">
					<option value="">None (fastest)</option>
					<option value="gzip">gzip</option>
					<option value="bzip2">bzip2</option>
				</select>
			</label>
			<label class="mk-field">
				<span>Password <em>optional</em></span>
				<input
					v-model="password"
					type="password"
					autocomplete="new-password"
					placeholder="Encrypt this archive"
					:disabled="running"
				/>
			</label>
		</div>

		<fieldset class="mk-checks" :disabled="running">
			<legend>What to include</legend>
			<label v-for="option in options" :key="option.key" class="mk-check">
				<input v-model="option.value" type="checkbox" />
				<span>{{ option.label }}</span>
			</label>
		</fieldset>

		<div class="mk-actions">
			<button class="mk-btn mk-btn-ghost" type="button" :disabled="running" @click="showAdvanced = !showAdvanced">
				{{ showAdvanced ? 'Hide find and replace' : 'Find and replace, advanced options' }}
			</button>
		</div>

		<div v-if="showAdvanced" class="mk-editor">
			<h3>Database find and replace</h3>
			<p class="mk-muted">
				Runs against every table while the archive is written, before the backup is even created.
			</p>
			<table class="mk-table">
				<thead>
					<tr>
						<th>Search for</th>
						<th>Replace with</th>
						<th class="mk-right">Action</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, index) in rows" :key="index">
						<td><input v-model="row.find" type="text" :disabled="running" placeholder="https://old-site.example" /></td>
						<td><input v-model="row.replace" type="text" :disabled="running" placeholder="https://new-site.example" /></td>
						<td class="mk-right">
							<button
								class="mk-link mk-link-danger"
								type="button"
								:disabled="running"
								@click="removeRow(index)"
							>
								Remove
							</button>
						</td>
					</tr>
				</tbody>
			</table>
			<button class="mk-btn mk-btn-ghost" type="button" :disabled="running" @click="addRow">
				+ Add pair
			</button>

			<h3>Database tables</h3>
			<div class="mk-grid">
				<label class="mk-field">
					<span>Exclude tables <em>comma-separated, full names</em></span>
					<input
						v-model="excludedTables"
						type="text"
						:disabled="running"
						placeholder="wp_log_entries, wp_cache_objects"
					/>
				</label>
				<label class="mk-field">
					<span>Only include tables <em>overrides exclusions</em></span>
					<input
						v-model="includedTables"
						type="text"
						:disabled="running"
						placeholder="wp_posts, wp_postmeta"
					/>
				</label>
			</div>
		</div>

		<div class="mk-actions">
			<button class="mk-btn" type="button" :disabled="running" @click="start">
				{{ running ? 'Creating backup…' : 'Create backup' }}
			</button>
			<a v-if="done" class="mk-btn mk-btn-ghost" :href="config.backupsUrl">View backups</a>
		</div>

		<div v-if="job" class="mk-progress" role="status" aria-live="polite">
			<div class="mk-bar"><span :style="{ width: job.percent + '%' }"></span></div>
			<div class="mk-progress-meta">
				<span>{{ job.message || 'Working…' }}</span>
				<span>{{ job.percent }}%</span>
			</div>
		</div>

		<p v-if="done && job" class="mk-success">
			Backup created: <strong>{{ job.archiveName }}</strong>
		</p>
		<p v-if="error" class="mk-error">{{ error }}</p>
	</section>
</template>
