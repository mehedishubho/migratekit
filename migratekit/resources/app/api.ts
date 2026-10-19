export interface MigrateKitConfig {
	ajaxUrl: string;
	adminPost: string;
	nonce: string;
	downloadNonce: string;
	secret: string;
	siteUrl: string;
	maxUpload: number;
	version: string;
	backupsUrl: string;
	isMultisite: boolean;
	siteCount: number;
	isSubsite: boolean;
}

declare global {
	interface Window {
		MigrateKit: MigrateKitConfig;
	}
}

export const config: MigrateKitConfig = window.MigrateKit;

export interface JobState {
	id: string;
	type: string;
	stage: number;
	stages: number;
	percent: number;
	message: string;
	done: boolean;
	uploaded: boolean;
	/** Bytes accepted by the server during a chunked upload. */
	received: number;
	archiveName: string;
	cloud?: string;
	remote?: string;
}

type Payload = Record<string, unknown>;

export async function post<T = unknown>(action: string, payload: Payload = {}, file?: File): Promise<T> {
	const form = new FormData();
	form.append('action', action);
	form.append('nonce', config.nonce);
	form.append('secret', config.secret || '');

	if (file) {
		form.append('file', file);
	}

	for (const [key, value] of Object.entries(payload)) {
		if (value === undefined || value === null) {
			continue;
		}

		if (Array.isArray(value)) {
			for (const item of value) {
				if (item === undefined || item === null) {
					continue;
				}

				form.append(`${key}[]`, String(item));
			}

			continue;
		}

		form.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
	}

	const response = await fetch(config.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body: form,
	});

	let json: { success?: boolean; data?: { message?: string } } | null = null;

	try {
		json = await response.json();
	} catch {
		throw new Error('The server returned an invalid response.');
	}

	if (!json || !json.success) {
		throw new Error(json?.data?.message || 'The request failed.');
	}

	return json.data as T;
}

/**
 * Drive a job to completion by polling the step endpoint.
 */
export async function runJob(
	startRequest: Promise<JobState>,
	stepAction: string,
	onUpdate: (state: JobState) => void,
): Promise<JobState> {
	let state = await startRequest;
	onUpdate(state);

	while (!state.done) {
		state = await post<JobState>(stepAction, { job_id: state.id });
		onUpdate(state);

		if (state.done) {
			break;
		}

		await new Promise((resolve) => window.setTimeout(resolve, 200));
	}

	return state;
}

export interface UploadProgress {
	/** Bytes accepted by the server so far. */
	received: number;
	/** Declared archive size. */
	total: number;
}

/**
 * Upload an archive in server-bypassing chunks, resumable on chunk retries.
 * The server tracks accepted bytes; each chunk carries its byte offset so a
 * retried (or repeated) send is idempotent.
 */
export async function uploadImport(
	file: File,
	password: string,
	onProgress: (progress: UploadProgress) => void,
): Promise<JobState> {
	const start = await post<JobState>('migratekit_import_chunk', { phase: 'start', password });
	const chunkSize = Math.max(384 * 1024, Math.min(config.maxUpload - 48 * 1024, 5 * 1024 * 1024));
	const total = file.size;
	const attempts = 3;

	let received = 0;

	while (received < total) {
		const offset = received;
		const blob = file.slice(offset, Math.min(offset + chunkSize, total));
		const part = new File([blob], 'chunk.wpress', { type: 'application/octet-stream' });

		let failure: unknown = null;

		for (let attempt = 1; attempt <= attempts; attempt++) {
			try {
				const state = await post<JobState>(
					'migratekit_import_chunk',
					{ phase: 'append', job_id: start.id, offset },
					part,
				);

				received = typeof state.received === 'number' && state.received > received
					? state.received
					: offset + blob.size;
				failure = null;
				break;
			} catch (caught) {
				failure = caught;

				if (attempt < attempts) {
					await new Promise((resolve) => window.setTimeout(resolve, attempt * 600));
				}
			}
		}

		if (failure !== null) {
			throw failure instanceof Error ? failure : new Error('The archive upload failed.');
		}

		onProgress({ received, total });
	}

	const finish = await post<JobState>('migratekit_import_chunk', {
		phase: 'finish',
		job_id: start.id,
		total,
		name: file.name,
	});

	onProgress({ received: total, total });
	return finish;
}

export function formatBytes(bytes: number): string {
	if (!Number.isFinite(bytes) || bytes <= 0) {
		return '0 B';
	}

	const units = ['B', 'KB', 'MB', 'GB', 'TB'];
	const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
	const value = bytes / Math.pow(1024, exponent);

	return `${value.toFixed(exponent === 0 ? 0 : 1)} ${units[exponent]}`;
}

export function formatDate(timestamp: number): string {
	return new Date(timestamp * 1000).toLocaleString();
}
