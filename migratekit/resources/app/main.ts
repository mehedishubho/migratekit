import { createApp } from 'vue';
import App from './App.vue';
import './styles.css';

const element = document.getElementById('migratekit-app');

if (element) {
	createApp(App, { page: element.dataset.page || 'export' }).mount(element);
}
