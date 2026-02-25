<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import AppShell from '../components/AppShell.vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import { useAsyncExport } from '../composables/useAsyncExport';

const auth = useAuthStore();
const asyncExport = useAsyncExport();
const logs = ref([]);
const loading = ref(false);
const error = ref('');
const selected = ref(null);
const lastRefreshedAt = ref(null);
let pollTimer = null;
let eventSource = null;

const filters = reactive({
    action: '',
    role: '',
    user_id: '',
    status: '',
    municipality_id: '',
    project_id: '',
    date_from: '',
    date_to: '',
});

const pagination = reactive({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 25,
});

const visiblePages = computed(() => {
    const pages = [];
    const start = Math.max(1, pagination.current_page - 2);
    const end = Math.min(pagination.last_page, pagination.current_page + 2);

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    return pages;
});

const exportStatusLabel = computed(() => {
    if (!asyncExport.task.value) {
        return '';
    }

    const task = asyncExport.task.value;
    return `Export ${task.status} (${task.progress}%)`;
});

const loadLogs = async (page = pagination.current_page, silent = false) => {
    if (!silent) {
        loading.value = true;
    }

    error.value = '';

    try {
        const { data } = await api.get('/audit-logs', {
            params: {
                ...filters,
                page,
                per_page: pagination.per_page,
            },
        });

        logs.value = data.data || [];
        pagination.current_page = data.current_page || 1;
        pagination.last_page = data.last_page || 1;
        pagination.total = data.total || logs.value.length;
        lastRefreshedAt.value = new Date();
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load audit logs.';
    } finally {
        if (!silent) {
            loading.value = false;
        }
    }
};

const applyFilters = async () => {
    await loadLogs(1);
};

const setQuickFilter = async (preset) => {
    const now = new Date();
    const today = now.toISOString().slice(0, 10);

    if (preset === 'today') {
        filters.date_from = today;
        filters.date_to = today;
    }

    if (preset === 'last7') {
        const from = new Date(now);
        from.setDate(now.getDate() - 6);
        filters.date_from = from.toISOString().slice(0, 10);
        filters.date_to = today;
    }

    await applyFilters();
};

const goToPage = async (page) => {
    if (page < 1 || page > pagination.last_page || page === pagination.current_page) {
        return;
    }

    await loadLogs(page);
};

const startAuditCsvExport = async () => {
    await asyncExport.startExport({
        format: 'csv',
        type: 'audit_logs',
        action: filters.action || null,
        role: filters.role || null,
        user_id: filters.user_id || null,
        status: filters.status || null,
        municipality_id: filters.municipality_id || null,
        project_id: filters.project_id || null,
        date_from: filters.date_from || null,
        date_to: filters.date_to || null,
    });
};

const startSummaryPdfExport = async () => {
    await asyncExport.startExport({
        format: 'pdf',
        type: 'summary',
        status: filters.status || null,
        municipality_id: filters.municipality_id || null,
        project_id: filters.project_id || null,
        date_from: filters.date_from || null,
        date_to: filters.date_to || null,
    });
};

const connectRealtime = () => {
    if (!window.EventSource || !auth.token) {
        return false;
    }

    const token = encodeURIComponent(auth.token);
    eventSource = new EventSource(`/api/live/events?channel=audit&token=${token}`);

    eventSource.addEventListener('update', () => {
        loadLogs(pagination.current_page, true);
    });

    eventSource.addEventListener('end', () => {
        eventSource?.close();
        eventSource = null;
    });

    eventSource.onerror = () => {
        eventSource?.close();
        eventSource = null;
    };

    return true;
};

onMounted(async () => {
    await loadLogs(1);

    connectRealtime();

    pollTimer = setInterval(() => {
        loadLogs(pagination.current_page, true);
    }, 60000);
});

onBeforeUnmount(() => {
    if (pollTimer) {
        clearInterval(pollTimer);
    }

    if (eventSource) {
        eventSource.close();
        eventSource = null;
    }
});
</script>

<template>
    <AppShell>
        <section class="panel">
            <header class="panel__header">
                <h2>Audit Log</h2>
                <p class="panel__hint">Immutable action records across authentication, users, and submissions.</p>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <div class="toolbar">
                <input v-model="filters.action" placeholder="Action type">
                <input v-model="filters.role" placeholder="Role">
                <input v-model="filters.user_id" placeholder="User ID" inputmode="numeric">
                <input v-model="filters.status" placeholder="Status">
                <input v-model="filters.municipality_id" placeholder="Municipality ID" inputmode="numeric">
                <input v-model="filters.project_id" placeholder="Project ID" inputmode="numeric">
                <input v-model="filters.date_from" type="date">
                <input v-model="filters.date_to" type="date">
                <button class="btn btn--ghost" @click="setQuickFilter('today')">Today</button>
                <button class="btn btn--ghost" @click="setQuickFilter('last7')">Last 7 Days</button>
                <button class="btn btn--primary" @click="applyFilters">Apply</button>
                <button class="btn btn--ghost" :disabled="asyncExport.loading.value" @click="startAuditCsvExport">Export CSV</button>
                <button class="btn btn--ghost" :disabled="asyncExport.loading.value" @click="startSummaryPdfExport">Export PDF</button>
                <button
                    v-if="asyncExport.task.value?.status === 'ready'"
                    class="btn btn--primary"
                    @click="asyncExport.download"
                >
                    Download
                </button>
            </div>

            <p class="panel__hint" v-if="lastRefreshedAt">
                Last refreshed: {{ lastRefreshedAt.toLocaleTimeString() }}
            </p>
            <p class="panel__hint" v-if="exportStatusLabel">
                {{ exportStatusLabel }}
            </p>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Actor</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-if="loading">
                        <td colspan="6">Loading...</td>
                    </tr>
                    <tr v-else-if="!logs.length">
                        <td colspan="6">No audit logs found.</td>
                    </tr>
                    <tr v-for="log in logs" :key="log.id">
                        <td>{{ new Date(log.timestamp).toLocaleString() }}</td>
                        <td>{{ log.actor?.name || 'System' }}</td>
                        <td>{{ log.actor?.role || '-' }}</td>
                        <td>{{ log.action }}</td>
                        <td>{{ log.entity_type }} #{{ log.entity_id }}</td>
                        <td>
                            <button class="btn btn--ghost" @click="selected = log">Details</button>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar">
                <button class="btn btn--ghost" :disabled="pagination.current_page <= 1" @click="goToPage(pagination.current_page - 1)">Prev</button>
                <button
                    v-for="page in visiblePages"
                    :key="page"
                    class="btn"
                    :class="page === pagination.current_page ? 'btn--primary' : 'btn--ghost'"
                    @click="goToPage(page)"
                >
                    {{ page }}
                </button>
                <button class="btn btn--ghost" :disabled="pagination.current_page >= pagination.last_page" @click="goToPage(pagination.current_page + 1)">Next</button>
                <span class="pagination-meta">Total: {{ pagination.total }}</span>
            </div>

            <div class="detail-block" v-if="selected">
                <h3>Audit Entry #{{ selected.id }}</h3>
                <p><strong>IP:</strong> {{ selected.ip_address || '-' }}</p>
                <p><strong>User Agent:</strong> {{ selected.user_agent || '-' }}</p>
                <p><strong>Before:</strong></p>
                <pre>{{ JSON.stringify(selected.before, null, 2) }}</pre>
                <p><strong>After:</strong></p>
                <pre>{{ JSON.stringify(selected.after, null, 2) }}</pre>
                <p><strong>Metadata:</strong></p>
                <pre>{{ JSON.stringify(selected.metadata, null, 2) }}</pre>
            </div>
        </section>
    </AppShell>
</template>
