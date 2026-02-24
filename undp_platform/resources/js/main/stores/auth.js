import { defineStore } from 'pinia';
import api from '../api';

const wildcardMatch = (pattern, permission) => {
    if (pattern === '*') return true;
    if (pattern === permission) return true;
    if (!pattern.includes('*')) return false;

    const regex = new RegExp(`^${pattern.replace(/[-/\\^$+?.()|[\]{}]/g, '\\$&').replace(/\*/g, '.*')}$`);
    return regex.test(permission);
};

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: localStorage.getItem('undp_token') || null,
        user: JSON.parse(localStorage.getItem('undp_user') || 'null'),
        otpContext: null,
        loading: false,
    }),

    getters: {
        isAuthenticated: (state) => Boolean(state.token),
        permissions: (state) => state.user?.permissions || [],
        role: (state) => state.user?.role || null,
    },

    actions: {
        hasPermission(permission) {
            return this.permissions.some((allowed) => wildcardMatch(allowed, permission));
        },

        async requestOtp(payload) {
            const { data } = await api.post('/auth/request-otp', payload);
            this.otpContext = {
                ...payload,
                masked_phone: data.masked_phone,
                resend_in: data.resend_in,
            };
            return data;
        },

        async verifyOtp(payload) {
            const { data } = await api.post('/auth/verify-otp', payload);

            this.token = data.token;
            this.user = data.user;

            localStorage.setItem('undp_token', data.token);
            localStorage.setItem('undp_user', JSON.stringify(data.user));

            return data;
        },

        async fetchMe() {
            if (!this.token) return null;

            try {
                const { data } = await api.get('/auth/me');
                this.user = data.user;
                localStorage.setItem('undp_user', JSON.stringify(this.user));
                return this.user;
            } catch {
                this.clearSession();
                return null;
            }
        },

        async logout() {
            try {
                await api.post('/auth/logout');
            } finally {
                this.clearSession();
            }
        },

        clearSession() {
            this.token = null;
            this.user = null;
            this.otpContext = null;
            localStorage.removeItem('undp_token');
            localStorage.removeItem('undp_user');
        },
    },
});
