import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './main/App.vue';
import router from './main/router';
import i18n from './main/i18n';
import './bootstrap';
import 'leaflet/dist/leaflet.css';

const app = createApp(App);

app.use(createPinia());
app.use(i18n);
app.use(router);

app.mount('#app');
