<script setup lang="ts">
import JobProgress from './JobProgress.vue';
import { loadError, panelJobs, resumeOpen } from './jobs';
</script>

<template>
	<section v-if="panelJobs.length > 0 || loadError" class="mk-card mk-jobs" aria-labelledby="mk-jobs-title">
		<div class="mk-card-head">
			<div>
				<h2 id="mk-jobs-title">Unfinished tasks</h2>
				<p class="mk-muted">Started from another MigrateKit page. They carry on from here and survive a reload.</p>
			</div>
		</div>

		<JobProgress v-for="job in panelJobs" :key="job.key" :job="job" />

		<p v-if="loadError" class="mk-error">
			Unfinished tasks could not be checked: {{ loadError }}
			<button class="mk-link" type="button" @click="resumeOpen">Try again</button>
		</p>
	</section>
</template>
