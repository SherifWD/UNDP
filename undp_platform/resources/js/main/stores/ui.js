import { defineStore } from 'pinia';

let toastCounter = 0;

export const useUiStore = defineStore('ui', {
    state: () => ({
        toasts: [],
    }),

    actions: {
        pushToast(message, type = 'success', durationMs = 2600) {
            const id = ++toastCounter;

            this.toasts.push({
                id,
                message,
                type,
            });

            if (durationMs > 0) {
                setTimeout(() => {
                    this.removeToast(id);
                }, durationMs);
            }

            return id;
        },

        removeToast(id) {
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        },
    },
});
