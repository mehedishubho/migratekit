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

/** Error raised by post(). `transient` marks failures a retry can fix. */
export class ApiError extends Error {
	readonly status: number;
	readonly transient: boolean;

	constructor(message: string, status = 0, transient = false) {
		super(message);
		this.name = 'ApiError';
		this.status = status;
		this.transient = transient;
	}
}

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

	let response: Response;

	try {
		response = await fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: form,
		});
	} catch {
		throw new ApiError('The server could not be reached.', 0, true);
	}

	let json: { success?: boolean; data?: { message?: string } } | null = null;

	try {
		json = await response.json();
	} catch {
		json = null;
	}

	if (!json || !json.success) {
		const message = json?.data?.message;

		// A message means the plugin itself answered, so the failure is final: retrying cannot change it.
		if (message) {
			const expired = response.status === 403 ? ' Your session may have expired: log in again and reload this page to resume.' : '';

			throw new ApiError(message + expired, response.status);
		}

		if (response.status === 401 || response.status === 403) {
			throw new ApiError('Your login session expired. Log in again, then reload this page to resume.', response.status);
		}

		// Anything else is an error page or a dropped connection, which usually clears on its own.
		const reason =
			response.status >= 500
				? `The server ran into an error (HTTP ${response.status}) instead of answering.`
				: 'The server returned an invalid response.';

		throw new ApiError(reason, response.status, true);
	}

	return json.data as T;
}

/** First retry of a streak waits this long; each further one doubles the wait, up to RETRY_MAX_MS. */
const RETRY_BASE_MS = 2000;
const RETRY_MAX_MS = 30000;

/** How long the server may stay unreachable before the job is left for a later resume. */
const RETRY_WINDOW_MS = 15 * 60 * 1000;

export interface RetryNotice {
	/** 1 for the first retry after a successful step. */
	attempt: number;
	/** Seconds until that retry is sent. */
	delaySeconds: number;
	/** Why the last step failed. */
	message: string;
}

/** Wait up to `ms`, ending early once `shouldStop()` turns true. */
async function pause(ms: number, shouldStop?: () => boolean): Promise<void> {
	let remaining = ms;

	while (remaining > 0) {
		if (shouldStop?.()) {
			return;
		}

		const slice = Math.min(250, remaining);

		await new Promise((resolve) => window.setTimeout(resolve, slice));
		remaining -= slice;
	}
}

/**
 * Drive a job to completion by polling the step endpoint. The job is resumable
 * server-side, so a step that fails to reach the server is retried with
 * growing waits; a step the server rejects is reported at once.
 */
export async function runJob(
	startRequest: Promise<JobState>,
	stepAction: string,
	onUpdate: (state: JobState) => void,
	shouldStop?: () => boolean,
	onRetry?: (notice: RetryNotice) => void,
): Promise<JobState> {
	return driveJob(await startRequest, stepAction, onUpdate, shouldStop, onRetry);
}

/**
 * Drive an already-created job to completion by polling the step endpoint.
 * A failed step is retried with backoff for as long as the server stays
 * unreachable. `shouldStop` is polled between requests so a cancel from the
 * page takes effect without waiting out the retry backoff.
 */
export async function driveJob(
	state: JobState,
	stepAction: string,
	onUpdate: (state: JobState) => void,
	shouldStop?: () => boolean,
	onRetry?: (notice: RetryNotice) => void,
): Promise<JobState> {
	onUpdate(state);

	// When the server was last reached; reset by every successful step.
	let outageStartedAt: number | null = null;
	let attempt = 0;

	while (!state.done) {
		if (shouldStop?.()) {
			return state;
		}

		let next: JobState;

		try {
			next = await post<JobState>(stepAction, { job_id: state.id });
		} catch (caught) {
			if (shouldStop?.()) {
				return state;
			}

			const error = caught instanceof ApiError ? caught : null;
			const timedOut = outageStartedAt !== null && Date.now() - outageStartedAt > RETRY_WINDOW_MS;

			if (error === null || !error.transient) {
				throw caught;
			}

			if (timedOut) {
				throw new ApiError(
					`The server could not be reached for ${Math.round(RETRY_WINDOW_MS / 60000)} minutes. The job is paused and can be resumed once the site is back.`,
					error.status,
				);
			}

			if (outageStartedAt === null) {
				outageStartedAt = Date.now();
			}

			const delay = Math.min(RETRY_MAX_MS, RETRY_BASE_MS * 2 ** Math.min(attempt, 4));
			attempt += 1;

			onRetry?.({ attempt, delaySeconds: Math.round(delay / 1000), message: error.message });

			await pause(delay, shouldStop);
			continue;
		}

		outageStartedAt = null;
		attempt = 0;
		state = next;
		onUpdate(state);

		if (state.done) {
			break;
		}

		await pause(200, shouldStop);
	}

	return state;
}

/** localStorage key of the export job the browser last drove, so a later page load can resume it. */
export const ACTIVE_EXPORT_KEY = 'migratekit-dashboard-job';

export function rememberExport(jobId: string): void {
	window.localStorage.setItem(ACTIVE_EXPORT_KEY, JSON.stringify({ id: jobId }));
}

export function forgetExport(): void {
	window.localStorage.removeItem(ACTIVE_EXPORT_KEY);
}

/** Id of the export job a previous page load left unfinished, or ''. */
export function activeExportId(): string {
	try {
		const raw = window.localStorage.getItem(ACTIVE_EXPORT_KEY);

		return raw ? ((JSON.parse(raw) as { id?: string }).id ?? '') : '';
	} catch {
		return '';
	}
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

/** Seconds for each built-in cadence (matches PHP: MONTH_IN_SECONDS = 30 days). */
const INTERVAL_SECONDS: Record<string, number> = {
	hourly: 3600,
	twicedaily: 43200,
	daily: 86400,
	weekly: 604800,
	monthly: 2592000,
};

export function intervalSeconds(interval: string): number {
	return INTERVAL_SECONDS[interval] ?? 86400;
}

/** Human label for a cadence slug, e.g. "twicedaily" → "Twice a day". */
export function friendlyInterval(interval: string): string {
	switch (interval) {
		case 'hourly':
			return 'Every hour';
		case 'twicedaily':
			return 'Twice a day';
		case 'daily':
			return 'Daily';
		case 'weekly':
			return 'Weekly';
		case 'monthly':
			return 'Monthly';
		default:
			return interval;
	}
}

/** Render a duration as short words, e.g. "3h 20m", "2d 5h", "45s". */
export function inWords(seconds: number): string {
	const safe = Math.max(0, Math.round(seconds));

	if (safe < 60) {
		return `${safe}s`;
	}

	const minutes = Math.floor(safe / 60);
	const partMinutes = minutes % 60;
	const hours = Math.floor(minutes / 60);

	if (hours === 0) {
		return `${minutes}m`;
	}

	const days = Math.floor(hours / 24);

	if (days === 0) {
		return partMinutes > 0 ? `${hours}h ${partMinutes}m` : `${hours}h`;
	}

	const partHours = hours % 24;

	return partHours > 0 ? `${days}d ${partHours}h` : `${days}d`;
}
