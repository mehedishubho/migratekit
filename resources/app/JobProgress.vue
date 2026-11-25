<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { formatBytes } from './api';
import { canResume, canStop, dismiss, needsPassword, resume, stop, successOf, stoppedOf, titleOf, type TrackedJob } from './jobs';

const props = defineProps<{ job: TrackedJob }>();

const percent = computed<number>(() => {
	const progress = props.job.progress;

	if (props.job.intent === 'upload' && progress !== null && progress.total > 0) {
		return Math.min(100, Math.round((progress.received / progress.total) * 100));
	}

	return props.job.state === null ? 0 : props.job.state.percent;
});

/** The browser is still sending the file, so the bar follows the bytes sent rather than the job's stages. */
const sending = computed(() => props.job.intent === 'upload' && props.job.progress !== null);

const title = computed(() => (props.job.state === null ? 'Starting…' : titleOf(props.job)));

const atRest = computed(() => ['done', 'stopped', 'failed', 'paused'].includes(props.job.status));

/** A paused upload waits for a file, which only the user can choose again; anything else paused says why it waits. */
function waitingText(job: TrackedJob): string {
	if (job.intent === 'upload') {
		const state = job.state;
		const sent = state === null ? '' : ` with ${formatBytes(state.received)} of ${formatBytes(state.total ?? 0)} sent`;

		return `${job.error ? `${job.error} ` : ''}The upload stopped${sent}. Choose the same file on the Import page to send the rest.`;
	}

	return job.error || 'Waiting to be carried on.';
}

/** The sentence under the bar, and the tone it is drawn in. */
const line = computed<{ text: string; tone: string }>(() => {
	const job = props.job;

	if (sending.value && job.progress !== null) {
		return { text: `Uploading ${formatBytes(job.progress.received)} of ${formatBytes(job.progress.total)}`, tone: 'plain' };
	}

	switch (job.status) {
		case 'running':
			return { text: job.state === null ? 'Waiting for the server…' : job.state.message || 'Working…', tone: 'plain' };
		case 'stopping':
			return { text: job.state === null ? 'Stopping…' : 'Waiting for the step in flight to end…', tone: 'plain' };
		case 'done':
			return { text: successOf(job), tone: 'ok' };
		case 'stopped':
			return { text: job.error || stoppedOf(job), tone: job.error ? 'warn' : 'plain' };
		case 'failed':
			return { text: job.error, tone: 'bad' };
		default:
			return { text: waitingText(job), tone: 'warn' };
	}
});

/** Screen readers hear where the job stands at milestones, not every poll tick. */
const announcement = ref('');
let announced = '';

watch([() => props.job.status, () => props.job.phase, () => props.job.state?.stage, () => Math.floor(percent.value / 10)], () => {
	const spoken = `${title.value}: ${props.job.status === 'running' ? `${percent.value}%` : line.value.text}`;

	if (spoken !== announced) {
		announced = spoken;
		announcement.value = spoken;
	}
}, { immediate: true });
</script>

<template>
	<div class="mk-progress mk-job" :class="'is-' + job.status">
		<div
			class="mk-bar"
			role="progressbar"
			:aria-label="title"
			aria-valuemin="0"
			aria-valuemax="100"
			:aria-valuenow="percent"
		>
			<span :style="{ width: percent + '%' }"></span>
		</div>
		<div class="mk-progress-meta">
			<span>{{ title }}</span>
			<span class="mk-progress-side">
				<span>{{ percent }}%</span>
				<button
					v-if="canStop(job)"
					class="mk-link"
					type="button"
					:disabled="job.status === 'stopping'"
					@click="stop(job)"
				>
					Stop
				</button>
				<button v-if="canResume(job)" class="mk-link" type="button" @click="resume(job)">{{ needsPassword(job) ? 'Enter password' : 'Resume' }}</button>
				<button v-if="atRest" class="mk-link" type="button" @click="dismiss(job)">Dismiss</button>
			</span>
		</div>
		<p class="mk-status" :class="'is-' + line.tone">{{ line.text }}</p>
		<p v-if="job.status === 'running'" class="mk-status">
			Moving to another MigrateKit page keeps this going; reloading or leaving asks first.
		</p>
		<p v-if="job.retrying" class="mk-warn" role="status">
			The server did not answer ({{ job.retrying.message }}). Retrying in {{ job.retrying.delaySeconds }}s — attempt
			{{ job.retrying.attempt }}. Nothing is lost: it continues from where it stopped.
		</p>
		<slot></slot>
		<span class="mk-sr-only" aria-live="polite">{{ announcement }}</span>
	</div>
</template>
