import { computed, reactive, ref, type ComputedRef } from 'vue';
import {
	ApiError,
	driveJob,
	post,
	reserveUpload,
	sendUpload,
	type JobState,
	type RetryNotice,
	type UploadProgress,
} from './api';

/**
 * Every task the admin pages run — backups, restores, transfers, uploads — is
 * tracked here rather than in the page that started it.
 *
 * A job only advances while a page sends its step requests. A page that loads
 * therefore asks the server which jobs are still open and carries them on, so
 * the row with the progress is still there after a reload, whichever
 * MigrateKit page the user lands on.
 */

export type JobIntent = 'backup' | 'restore' | 'import' | 'push' | 'pull' | 'cloud-restore' | 'upload';

/**
 * `running` while a page drives the job; `stopping` from the Stop click until
 * the step in flight has ended; `paused` while it waits for something only
 * the user can give.
 */
export type JobStatus = 'running' | 'stopping' | 'failed' | 'paused' | 'done' | 'stopped';

export interface TrackedJob {
	/** Unique within this page load: the server's id exists only once the job has started. */
	key: number;
	intent: JobIntent;
	status: JobStatus;
	/** Server state of the job being driven. */
	state: JobState | null;
	/** Why the job is not running, in the server's words where it has them. */
	error: string;
	/** Set while a step is waited out because the server did not answer. */
	retrying: RetryNotice | null;
	/** Bytes sent while the browser is still uploading an archive. */
	progress: UploadProgress | null;
	/** A restore from the cloud fetches the backup before it restores it. */
	phase: 'fetch' | 'restore';
	/** It stopped here, but the server could not be told; it has to be told again. */
	unstoppable: boolean;
}

export const jobs = ref<TrackedJob[]>([]);

/** False until the server has said which unfinished jobs this page is to carry on. */
export const ready = ref(false);

export const loadError = ref('');

/** Moves whenever any job comes to rest, so pages can refresh what it changed. */
export const settled = ref(0);

/** Intents whose progress the current page draws where it always did; the panel above leaves those to it. */
const inline = reactive(new Set<JobIntent>());

/** Jobs still being driven. */
export const active = computed(() => jobs.value.filter(isActive));

/** Jobs the current page does not draw in place, for the panel above it. */
export const panelJobs = computed(() => jobs.value.filter((job) => !inline.has(job.intent)));

export function isActive(job: TrackedJob | null): boolean {
	return job !== null && (job.status === 'running' || job.status === 'stopping');
}

export function showInline(...intents: JobIntent[]): void {
	for (const intent of intents) {
		inline.add(intent);
	}
}

export function hideInline(...intents: JobIntent[]): void {
	for (const intent of intents) {
		inline.delete(intent);
	}
}

export function withIntents(...intents: JobIntent[]): ComputedRef<TrackedJob[]> {
	return computed(() => jobs.value.filter((job) => intents.includes(job.intent)));
}

/** The most recent job of these kinds, the one the page shows in place. */
export function latestJob(...intents: JobIntent[]): ComputedRef<TrackedJob | null> {
	return computed(() => {
		const found = jobs.value.filter((job) => intents.includes(job.intent));

		return found.length > 0 ? found[found.length - 1] : null;
	});
}

let sequence = 0;

function blank(intent: JobIntent): TrackedJob {
	sequence += 1;

	return {
		key: sequence,
		intent,
		status: 'running',
		state: null,
		error: '',
		retrying: null,
		progress: null,
		phase: 'fetch',
		unstoppable: false,
	};
}

function remove(job: TrackedJob): void {
	jobs.value = jobs.value.filter((other) => other !== job);
}

function reason(caught: unknown, fallback: string): string {
	return caught instanceof Error ? caught.message : fallback;
}

function restoresSite(job: TrackedJob): boolean {
	return job.intent === 'restore' || job.intent === 'import' || (job.intent === 'cloud-restore' && job.phase === 'restore');
}

/** Send step requests until the job ends, is stopped, or fails. */
async function run(job: TrackedJob, state: JobState): Promise<JobState> {
	return driveJob(
		state,
		job.intent === 'backup' ? 'migratekit_export_step' : restoresSite(job) ? 'migratekit_import_step' : 'migratekit_cloud_step',
		(next) => {
			job.state = next;
		},
		() => job.status === 'stopping',
		(notice) => {
			job.retrying = notice;
		},
	);
}

/** Ask the server to drop a job, a few times: a job left open there would be continued by the next page load. */
async function cancel(jobId: string): Promise<void> {
	for (let attempt = 1; ; attempt++) {
		try {
			await post('migratekit_job_cancel', { job_id: jobId });

			return;
		} catch (caught) {
			if (attempt >= 3) {
				throw caught;
			}

			await new Promise((resolve) => window.setTimeout(resolve, attempt * 700));
		}
	}
}

/** Stop was asked for: the step in flight is still running, or the job has just been left by it. */
function isStopping(job: TrackedJob): boolean {
	return job.status === 'stopping';
}

async function drive(job: TrackedJob): Promise<void> {
	if (job.state === null) {
		return;
	}

	job.status = 'running';
	job.error = '';

	try {
		let state = await run(job, job.state);

		if (job.intent === 'cloud-restore' && state.done && state.restoreJob) {
			// The backup is on this server; the server has already made its restore a job in its own right.
			const restoreId = state.restoreJob;

			if (isStopping(job)) {
				// Stopped just as the backup arrived: the restore waiting on the server must not outlive that choice.
				// From here on the row stands for that restore, so dismissing it tells the server again.
				job.state = { ...state, id: restoreId };
				await drop(job);
			} else {
				job.phase = 'restore';
				state = await post<JobState>('migratekit_status', { job_id: restoreId });
				job.state = state;
				state = await run(job, state);
			}
		}

		job.status = isStopping(job) ? 'stopped' : 'done';
	} catch (caught) {
		if (caught instanceof ApiError && caught.status === 404) {
			// Stopped from another page, or cleaned up on the server.
			job.status = 'stopped';
			job.error = 'It is no longer on this server: it was stopped or cleaned up elsewhere.';
		} else {
			job.status = isStopping(job) ? 'stopped' : 'failed';
			job.error = reason(caught, 'It could not continue.');
		}
	} finally {
		job.retrying = null;
		settled.value += 1;
	}

	if (needsPassword(job)) {
		await unlock(job);
	}
}

/**
 * A restore that stopped because its archive turned out to be locked. It cannot
 * go on without the password, so Resume asks for it instead of trying again.
 */
export function needsPassword(job: TrackedJob): boolean {
	return job.status === 'failed' && (job.intent === 'restore' || job.intent === 'cloud-restore') && /encrypt|password/i.test(job.error);
}

/** Ask for the password, then start the restore over with it in the same row. */
async function unlock(job: TrackedJob): Promise<void> {
	const again = /incorrect|wrong/i.test(job.error);
	const password = window.prompt(again ? 'That password did not open this backup. Type the decryption password:' : 'This backup is encrypted. Type the decryption password:');
	const failed = job.state;

	if (!password || failed === null) {
		return;
	}

	// The row is back at work from the moment the password is given: nothing can come of the attempt made without it.
	job.status = 'running';
	job.error = '';

	await cancel(failed.id).catch(() => undefined);

	try {
		job.state = await post<JobState>('migratekit_backup_restore', { name: failed.backupName, password, cloud: failed.cloud });
	} catch (caught) {
		job.status = 'failed';
		job.error = reason(caught, 'The restore could not be started again.');
		settled.value += 1;

		return;
	}

	await drive(job);
}

/**
 * Start a job and drive it to its end. Resolves once the job has come to rest
 * — finished, stopped or failed; read `status` to tell which — and rejects
 * only when the server never started the job at all.
 */
export async function launch(intent: JobIntent, start: JobState | Promise<JobState>): Promise<TrackedJob> {
	const job = reactive(blank(intent));

	jobs.value.push(job);

	try {
		job.state = await start;
	} catch (caught) {
		// Nothing reached the server, so there is no job to keep: the page reports it as its own.
		remove(job);

		throw caught;
	}

	if (isStopping(job)) {
		// Stopped before the server had answered: the job it has just made must not outlive that.
		await drop(job);
		job.status = 'stopped';
		settled.value += 1;

		return job;
	}

	await drive(job);

	return job;
}

/** The upload a reloaded page is waiting for, if there is one. */
export function pausedUpload(): TrackedJob | null {
	const found = jobs.value.filter((job) => job.intent === 'upload' && job.status === 'paused');

	return found.length > 0 ? found[found.length - 1] : null;
}

/** Whether choosing `file` sends the rest of a waiting upload rather than starting another. */
export function continuesUpload(job: TrackedJob | null, file: File | null): boolean {
	if (job === null || file === null || job.state === null) {
		return false;
	}

	const state = job.state;
	const total = state.total ?? 0;

	return total === file.size || (total === 0 && (state.backupName || '') === file.name);
}

/**
 * Upload an archive and import it. Given the job of an upload that was
 * interrupted, the same file is sent from where the server stopped taking it.
 */
export async function upload(file: File, password: string, waiting: TrackedJob | null): Promise<TrackedJob> {
	const job = waiting === null ? reactive(blank('upload')) : waiting;

	if (waiting === null) {
		jobs.value.push(job);

		try {
			job.state = await reserveUpload(file, password);
		} catch (caught) {
			remove(job);

			throw caught;
		}
	}

	if (job.state === null) {
		remove(job);

		throw new Error('The upload could not be reserved on the server.');
	}

	const state = job.state;

	job.status = 'running';
	job.error = '';

	try {
		const imported = await sendUpload(file, state, (progress) => {
			job.progress = progress;
		});

		job.intent = 'import';
		job.state = imported;
		job.progress = null;
	} catch (caught) {
		// What arrived stays on the server: choosing the same file again sends only the rest.
		job.progress = null;
		job.status = 'paused';
		job.error = reason(caught, 'The upload could not be completed.');
		settled.value += 1;

		return job;
	}

	await drive(job);

	return job;
}

/** Only work that takes nothing with it when stopped can be stopped halfway; a half-restored site cannot be undone. */
export function canStop(job: TrackedJob): boolean {
	if (job.status !== 'running') {
		return false;
	}

	return job.intent === 'backup' || job.intent === 'push' || job.intent === 'pull' || (job.intent === 'cloud-restore' && job.phase === 'fetch');
}

export function canResume(job: TrackedJob): boolean {
	// The rest of an upload exists only in the browser, which does not keep files across a reload.
	return job.intent !== 'upload' && (job.status === 'failed' || job.status === 'paused');
}

/** Tell the server to drop the job the row stands for, so that no later page carries it on. */
async function drop(job: TrackedJob): Promise<void> {
	const jobId = job.state === null ? '' : job.state.id;

	if (jobId === '') {
		return;
	}

	try {
		await cancel(jobId);
	} catch (caught) {
		// The row stays and says so, and dismissing it tells the server again.
		job.unstoppable = true;
		job.error = `It stopped here, but the server could not be told: ${reason(caught, 'it could not be reached.')} It may still be running there and will be listed by the next page load.`;
	}
}

export async function stop(job: TrackedJob): Promise<void> {
	if (!canStop(job)) {
		return;
	}

	job.status = 'stopping';

	// While the server is still making the job there is nothing to tell yet; launch() does once it has answered.
	if (job.state !== null) {
		await drop(job);
	}
}

/** Carry a job on from where it stopped. */
export function resume(job: TrackedJob): void {
	if (isActive(job)) {
		return;
	}

	void drive(job);
}

/** Take the job out of the list, telling the server to drop it unless it came to a proper end. */
export async function dismiss(job: TrackedJob): Promise<void> {
	const open = job.status === 'failed' || job.status === 'paused' || job.unstoppable;

	remove(job);

	if (open && job.state !== null) {
		try {
			await cancel(job.state.id);
		} catch {
			// Its working files stay until the server's own cleanup, and it is listed again by the next page load.
		}
	}
}

/** Clear earlier attempts of these kinds from the list, dropping the ones that gave up on the server too. */
export async function discardSettled(...intents: JobIntent[]): Promise<void> {
	const earlier = jobs.value.filter((job) => intents.includes(job.intent) && !isActive(job));

	await Promise.all(earlier.map(dismiss));
}

function intentOf(state: JobState): JobIntent {
	if (state.type === 'export') {
		return 'backup';
	}

	if (state.type === 'push') {
		return 'push';
	}

	if (state.type === 'pull') {
		return state.restore ? 'cloud-restore' : 'pull';
	}

	if (!state.uploaded) {
		return 'upload';
	}

	return state.source === 'cloud' || state.source === 'backup' ? 'restore' : 'import';
}

/** Take responsibility for a job the server listed as unfinished. */
function adopt(state: JobState): TrackedJob {
	const intent = intentOf(state);
	const job = reactive({ ...blank(intent), state });

	jobs.value.push(job);

	if (intent === 'upload') {
		// Only a browser holds the rest of the file.
		job.status = 'paused';
	} else if (state.stale) {
		job.status = 'paused';
		job.error = 'It was left waiting for too long to be carried on by itself.';
	} else if (state.error) {
		// Its last step failed: running it again is the user's decision, not a page load's.
		job.status = 'failed';
		job.error = state.error;
	}

	return job;
}

/**
 * Ask the server which unfinished jobs were started from the admin pages,
 * and carry on the ones that were merely waiting for a page.
 */
export async function resumeOpen(): Promise<void> {
	try {
		const data = await post<{ jobs: JobState[] }>('migratekit_jobs_active');

		loadError.value = '';

		const waiting = data.jobs.map(adopt);

		for (const job of waiting) {
			if (job.status === 'running') {
				void drive(job);
			}
		}
	} catch (caught) {
		loadError.value = reason(caught, 'the server did not answer.');
	} finally {
		ready.value = true;
	}
}

function subject(job: TrackedJob): string {
	const state = job.state;

	return state === null ? '' : state.backupName || state.remote || state.archiveName;
}

function cloudOf(job: TrackedJob): string {
	const state = job.state;

	return (state !== null && (state.cloudLabel || state.cloud)) || 'the cloud';
}

function fromCloud(job: TrackedJob): string {
	return job.state !== null && job.state.source === 'cloud' ? ` from ${cloudOf(job)}` : '';
}

/** What the job is doing, in a few words: the label of its bar. */
export function titleOf(job: TrackedJob): string {
	const name = subject(job);

	switch (job.intent) {
		case 'backup':
			return 'Backing up this site';
		case 'restore':
			return `Restoring ${name}${fromCloud(job)}`;
		case 'import':
			return `Importing ${name}`;
		case 'push':
			return `Pushing ${name} to ${cloudOf(job)}`;
		case 'pull':
			return `Fetching ${name} from ${cloudOf(job)}`;
		case 'cloud-restore':
			return job.phase === 'restore' ? `Restoring ${name} from ${cloudOf(job)}` : `Fetching ${name} from ${cloudOf(job)}`;
		default:
			return `Uploading ${name || 'the archive'}`;
	}
}

/** How it ended, once it has. */
export function successOf(job: TrackedJob): string {
	const name = subject(job);

	switch (job.intent) {
		case 'backup':
			return `Backup created: ${job.state !== null && job.state.archiveName !== '' ? job.state.archiveName : name}`;
		case 'restore':
			return `Restored ${name}${fromCloud(job)}. Sign in again if your session was replaced.`;
		case 'import':
			return 'Import complete. Re-save your permalinks if anything looks off.';
		case 'push':
			return `Pushed ${name} to ${cloudOf(job)}.`;
		case 'pull':
			return `Fetched ${name} from ${cloudOf(job)} — it is ready in Backups.`;
		case 'cloud-restore':
			return `Restored ${name} from ${cloudOf(job)}. Sign in again if your session was replaced.`;
		default:
			return `Uploaded ${name}.`;
	}
}

export function stoppedOf(job: TrackedJob): string {
	return job.intent === 'cloud-restore' ? 'Stopped — the backup was not restored.' : 'Stopped — nothing on your site was changed.';
}
