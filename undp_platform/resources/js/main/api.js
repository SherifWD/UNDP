import axios from 'axios';
import i18n from './i18n';

const api = axios.create({
    baseURL: '/api',
    headers: {
        Accept: 'application/json',
    },
});

api.interceptors.request.use((config) => {
    const token = localStorage.getItem('undp_token');
    const locale = i18n.global.locale.value || localStorage.getItem('undp_locale') || 'en';

    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    config.headers['Preferred-Locale'] = locale;
    config.headers['preferred-locale'] = locale;
    config.headers['preferred_locale'] = locale;
    config.headers['Accept-Language'] = locale;

    return config;
});

api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            localStorage.removeItem('undp_token');
            localStorage.removeItem('undp_user');
        }

        return Promise.reject(error);
    },
);

export default api;
