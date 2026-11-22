<script setup lang="ts">
import { computed } from 'vue';
import DashboardPage from './pages/DashboardPage.vue';
import ExportPage from './pages/ExportPage.vue';
import ImportPage from './pages/ImportPage.vue';
import BackupsPage from './pages/BackupsPage.vue';
import SchedulesPage from './pages/SchedulesPage.vue';
import ResetPage from './pages/ResetPage.vue';
import CloudPage from './pages/CloudPage.vue';

const props = defineProps<{ page: string }>();

const page = computed(() => props.page);
</script>

<template>
	<div class="mk-app">
		<header class="mk-header">
			<div class="mk-brand">
				<h1>MigrateKit</h1>
				<p>Migration, backup and restore. Every feature included.</p>
			</div>
			<nav class="mk-nav" aria-label="MigrateKit">
				<a :class="{ active: page === 'home' }" href="?page=migratekit-home">Dashboard</a>
				<a :class="{ active: page === 'export' }" href="?page=migratekit-export">Export</a>
				<a :class="{ active: page === 'import' }" href="?page=migratekit-import">Import</a>
				<a :class="{ active: page === 'backups' }" href="?page=migratekit-backups">Backups</a>
				<a :class="{ active: page === 'cloud' }" href="?page=migratekit-cloud">Cloud</a>
				<a :class="{ active: page === 'schedules' }" href="?page=migratekit-schedules">Schedules</a>
				<a :class="{ active: page === 'reset' }" href="?page=migratekit-reset">Reset</a>
			</nav>
		</header>

		<main class="mk-main">
			<DashboardPage v-if="page === 'home'" />
			<ExportPage v-else-if="page === 'export'" />
			<ImportPage v-else-if="page === 'import'" />
			<BackupsPage v-else-if="page === 'backups'" />
			<CloudPage v-else-if="page === 'cloud'" />
			<SchedulesPage v-else-if="page === 'schedules'" />
			<ResetPage v-else-if="page === 'reset'" />
		</main>
	</div>
</template>
