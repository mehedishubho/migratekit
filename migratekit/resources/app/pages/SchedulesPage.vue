<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { config, formatDate, post } from '../api';

interface ScheduleOptions {
	compression: string;
	password: string;
	network: string;
	no_media: boolean;
	no_plugins: boolean;
	no_themes: boolean;
	no_database: boolean;
	no_post_revisions: boolean;
	no_spam_comments: boolean;
}

interface Schedule {
	id: string;
	name: string;
	enabled: boolean;
	interval: string;
	options: ScheduleOptions;
	cloud_provider: string;
	retention_count: number;
	retention_days: number;
	notify_email: string;
	last_run: number;
	last_status: string;
}

const schedules = ref<Schedule[]>([]);
const intervals = ref<string[]>(['hourly', 'twicedaily', 'daily', 'weekly', 'monthly']);
const cloudProviders = ref<Array<{ id: string; label: string; ready: boolean }>>([]);
const editing = ref<Schedule | null>(null);
const loading = ref(false);
const error = ref('');
const notice = ref('');

function blank(): Schedule {
	return {
		id: '',
		name: '',
		enabled: true,
		interval: 'daily',
		options: {
			compression: '',
			password: '',
			network: '',
			no_media: false,
			no_plugins: false,
			no_themes: false,
			no_database: false,
			no_post_revisions: false,
			no_spam_comments: false,
		},
		cloud_provider: '',
		retention_count: 0,
		retention_days: 0,
		notify_email: '',
		last_run: 0,
		last_status: '',
	};
}

async function load(): Promise<void> {
	loading.value = true;
	error.value = '';

	try {
		const data = await post<{ schedules: Schedule[]; intervals: string[] }>('migratekit_schedules_list');
		schedules.value = data.schedules;
		intervals.value = data.intervals;

		const cloud = await post<{ providers: Array<{ id: string; label: string; ready: boolean }> }>(
			'migratekit_cloud_status'
		);
		cloudProviders.value = cloud.providers.filter((provider) => provider.ready);
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to load schedules.';
	} finally {
		loading.value = false;
	}
}

function createNew(): void {
	editing.value = blank();
	notice.value = '';
	error.value = '';
}

function edit(schedule: Schedule): void {
	editing.value = JSON.parse(JSON.stringify(schedule));
	notice.value = '';
	error.value = '';
}

function payload(schedule: Schedule): Record<string, unknown> {
	return {
		id: schedule.id,
		name: schedule.name,
		enabled: schedule.enabled,
		interval: schedule.interval,
		compression: schedule.options.compression,
		password: schedule.options.password,
		network: schedule.options.network,
		no_media: schedule.options.no_media,
		no_plugins: schedule.options.no_plugins,
		no_themes: schedule.options.no_themes,
		no_database: schedule.options.no_database,
		no_post_revisions: schedule.options.no_post_revisions,
		no_spam_comments: schedule.options.no_spam_comments,
		cloud_provider: schedule.cloud_provider,
		retention_count: schedule.retention_count,
		retention_days: schedule.retention_days,
		notify_email: schedule.notify_email,
	};
}

async function save(): Promise<void> {
	if (!editing.value) {
		return;
	}

	if (!editing.value.name.trim()) {
		error.value = 'Give this schedule a name.';
		return;
	}

	try {
		await post('migratekit_schedule_save', payload(editing.value));
		notice.value = 'Schedule saved.';
		editing.value = null;
		error.value = '';
		await load();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to save the schedule.';
	}
}

async function remove(schedule: Schedule): Promise<void> {
	if (!window.confirm(`Delete the schedule "${schedule.name}"? Existing backups are not deleted.`)) {
		return;
	}

	try {
		await post('migratekit_schedule_delete', { id: schedule.id });
		await load();
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to delete the schedule.';
	}
}

async function runNow(schedule: Schedule): Promise<void> {
	try {
		await post('migratekit_schedule_run', { id: schedule.id });
		notice.value = 'Backup queued. It runs on the next cron tick.';
		error.value = '';
	} catch (caught) {
		error.value = caught instanceof Error ? caught.message : 'Unable to queue the backup.';
	}
}

onMounted(load);
</script>

<template>
	<section class="mk-card">
		<div class="mk-card-head">
			<div>
				<h2>Scheduled backups</h2>
				<p class="mk-muted">Create automatic backups and optionally prune older ones.</p>
			</div>
			<button class="mk-btn" type="button" @click="createNew">New schedule</button>
		</div>

		<p v-if="error" class="mk-error">{{ error }}</p>
		<p v-if="notice" class="mk-success">{{ notice }}</p>

		<div v-if="editing" class="mk-editor">
			<h3>{{ editing.id ? 'Edit schedule' : 'New schedule' }}</h3>

			<div class="mk-grid">
				<label class="mk-field">
					<span>Name</span>
					<input v-model="editing.name" type="text" maxlength="100" placeholder="Daily backup" />
				</label>
				<label class="mk-field">
					<span>Frequency</span>
					<select v-model="editing.interval">
						<option v-for="interval in intervals" :key="interval" :value="interval">
							{{ interval }}
						</option>
					</select>
				</label>
				<label class="mk-field">
					<span>Compression</span>
					<select v-model="editing.options.compression">
						<option value="">None</option>
						<option value="gzip">gzip</option>
						<option value="bzip2">bzip2</option>
					</select>
				</label>
				<label v-if="config.isMultisite" class="mk-field">
					<span>Scope</span>
					<select v-model="editing.options.network">
						<option value="">{{ config.isSubsite ? 'Main site only' : 'This site only' }}</option>
						<option value="whole">Entire network ({{ config.siteCount }} sites)</option>
					</select>
				</label>
				<label class="mk-field">
					<span>Password <em>optional</em></span>
					<input v-model="editing.options.password" type="password" autocomplete="new-password" />
				</label>
				<label class="mk-field">
					<span>Keep newest <em>0 = keep all</em></span>
					<input v-model.number="editing.retention_count" type="number" min="0" step="1" />
				</label>
				<label class="mk-field">
					<span>Keep days <em>0 = ignore age</em></span>
					<input v-model.number="editing.retention_days" type="number" min="0" step="1" />
				</label>
				<label class="mk-field">
					<span>Notify email <em>optional</em></span>
					<input v-model="editing.notify_email" type="email" placeholder="admin@example.com" />
				</label>
				<label class="mk-check mk-check-inline">
					<input v-model="editing.enabled" type="checkbox" />
					<span>Enabled</span>
				</label>
				<label class="mk-field">
					<span>Cloud copy <em>optional</em></span>
					<select v-model="editing.cloud_provider">
						<option value="">None — keep backups on this server</option>
						<option v-for="provider in cloudProviders" :key="provider.id" :value="provider.id">
							{{ provider.label }} — push after every backup
						</option>
					</select>
				</label>
			</div>

			<fieldset class="mk-checks">
				<legend>Exclude from the backup</legend>
				<label class="mk-check"><input v-model="editing.options.no_media" type="checkbox" /><span>Media library</span></label>
				<label class="mk-check"><input v-model="editing.options.no_plugins" type="checkbox" /><span>Plugins</span></label>
				<label class="mk-check"><input v-model="editing.options.no_themes" type="checkbox" /><span>Themes</span></label>
				<label class="mk-check"><input v-model="editing.options.no_database" type="checkbox" /><span>Database</span></label>
				<label class="mk-check"><input v-model="editing.options.no_post_revisions" type="checkbox" /><span>Post revisions</span></label>
				<label class="mk-check"><input v-model="editing.options.no_spam_comments" type="checkbox" /><span>Spam comments</span></label>
			</fieldset>

			<div class="mk-actions">
				<button class="mk-btn" type="button" @click="save">Save schedule</button>
				<button class="mk-btn mk-btn-ghost" type="button" @click="editing = null">Cancel</button>
			</div>
		</div>

		<div v-if="!loading && schedules.length === 0 && !editing" class="mk-empty">
			<strong>No schedules yet</strong>
			<span>Create one to automate your backups.</span>
		</div>

		<table v-else-if="schedules.length > 0" class="mk-table">
			<thead>
				<tr>
					<th>Name</th>
					<th>Frequency</th>
					<th>Cloud copy</th>
					<th>Status</th>
					<th>Last run</th>
					<th class="mk-right">Actions</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="schedule in schedules" :key="schedule.id">
					<td>{{ schedule.name }}</td>
					<td>{{ schedule.interval }}</td>
					<td>
						{{
							cloudProviders.find((provider) => provider.id === schedule.cloud_provider)?.label ||
							(schedule.cloud_provider ? schedule.cloud_provider : '—')
						}}
					</td>
					<td>{{ schedule.enabled ? 'Enabled' : 'Paused' }}</td>
					<td>
						{{ schedule.last_run ? formatDate(schedule.last_run) : 'Never' }}
						<span v-if="schedule.last_status" class="mk-status">{{ schedule.last_status }}</span>
					</td>
					<td class="mk-right">
						<button class="mk-link" type="button" @click="edit(schedule)">Edit</button>
						<button class="mk-link" type="button" @click="runNow(schedule)">Run now</button>
						<button class="mk-link mk-link-danger" type="button" @click="remove(schedule)">Delete</button>
					</td>
				</tr>
			</tbody>
		</table>
	</section>
</template>
