import axios from 'axios';
import { createApp } from 'vue';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const token = document.head.querySelector('meta[name="csrf-token"]');

if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

const app = createApp({});

window.app = app;

app.config.globalProperties.$axios = window.axios;

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('app')) {
        app.mount('#app');
    }
});
