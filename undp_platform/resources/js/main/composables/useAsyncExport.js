import { onBeforeUnmount, ref } from 'vue';
import api from '../api';
import { useUiStore } from '../stores/ui';

export function useAsyncExport() {
    const ui = useUiStore();
    const task = ref(null);
    const loading = ref(false);
    let pollTimer = null;

    const stopPolling = () => {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    };

    const refreshTask = async () => {
        if (!task.value?.id) {
            return null;
        }

        const { data } = await api.get(`/exports/tasks/${task.value.id}`);
        task.value = data.task;

        if (task.value.status === 'ready' || task.value.status === 'failed') {
            stopPolling();
            loading.value = false;

            if (task.value.status === 'ready') {
                ui.pushToast('Export is ready for download.');
            } else if (task.value.error_message) {
                ui.pushToast(task.value.error_message, 'error', 4500);
            }
        }

        return task.value;
    };

    const startPolling = () => {
        stopPolling();

        pollTimer = setInterval(() => {
            refreshTask().catch(() => {
                stopPolling();
                loading.value = false;
            });
        }, 2000);
    };

    const startExport = async (payload) => {
        loading.value = true;

        try {
            const { data } = await api.post('/exports/tasks', payload);
            task.value = data.task;
            ui.pushToast('Export started in background.');
            startPolling();
            return task.value;
        } catch (error) {
            loading.value = false;
            const message = error.response?.data?.message || 'Unable to start export.';
            ui.pushToast(message, 'error', 4500);
            throw error;
        }
    };

    const download = () => {
        if (!task.value?.download_url) {
            return;
        }

        const source = String(task.value.download_url);
        const normalizedUrl = source.startsWith('http')
            ? `${new URL(source).pathname}${new URL(source).search}`
            : source;

        api.get(normalizedUrl, { responseType: 'blob' })
            .then((response) => {
                const blobUrl = URL.createObjectURL(response.data);
                const link = document.createElement('a');
                const fallbackName = task.value?.file_name || 'export-file';

                link.href = blobUrl;
                link.download = fallbackName;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(blobUrl);
            })
            .catch((error) => {
                const message = error.response?.data?.message || 'Unable to download export file.';
                ui.pushToast(message, 'error', 4500);
            });
    };

    onBeforeUnmount(() => {
        stopPolling();
    });

    return {
        task,
        loading,
        startExport,
        refreshTask,
        download,
    };
}
