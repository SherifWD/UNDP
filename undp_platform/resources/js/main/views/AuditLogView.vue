<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AppShell from '../components/AppShell.vue';
import api from '../api';

const logs = ref([]);
const loading = ref(false);
const error = ref('');
const selected = ref(null);

const filters = reactive({
    action: '',
    user_id: '',
    date_from: '',
    date_to: '',
});

const pagination = reactive({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 10,
});

const visiblePages = computed(() => {
    const pages = [];
    const current = pagination.current_page;
    const last = pagination.last_page;

    if (last <= 7) {
        for (let page = 1; page <= last; page += 1) {
            pages.push(page);
        }
        return pages;
    }

    pages.push(1);

    if (current > 3) {
        pages.push('ellipsis-left');
    }

    const start = Math.max(2, current - 1);
    const end = Math.min(last - 1, current + 1);

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    if (current < last - 2) {
        pages.push('ellipsis-right');
    }

    pages.push(last);

    return pages;
});

const totalActivities = computed(() => Number(pagination.total || logs.value.length));
const onlineUsers = computed(() => new Set(logs.value.map((log) => log.actor?.id).filter(Boolean)).size);

const loadLogs = async (page = pagination.current_page) => {
    loading.value = true;
    error.value = '';

    try {
        const { data } = await api.get('/audit-logs', {
            params: {
                action: filters.action || undefined,
                user_id: filters.user_id || undefined,
                date_from: filters.date_from || undefined,
                date_to: filters.date_to || undefined,
                page,
                per_page: pagination.per_page,
            },
        });

        logs.value = data.data || [];
        pagination.current_page = data.current_page || 1;
        pagination.last_page = data.last_page || 1;
        pagination.total = data.total || logs.value.length;
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load audit logs.';
        logs.value = [];
        pagination.current_page = 1;
        pagination.last_page = 1;
        pagination.total = 0;
    } finally {
        loading.value = false;
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

const resetFilters = async () => {
    filters.action = '';
    filters.user_id = '';
    filters.date_from = '';
    filters.date_to = '';
    await loadLogs(1);
};

const goToPage = async (page) => {
    if (typeof page !== 'number' || page < 1 || page > pagination.last_page || page === pagination.current_page) {
        return;
    }

    await loadLogs(page);
};

const moduleLabel = (log) => {
    const value = String(log.entity_type || '').replace(/_/g, ' ');
    return value ? value.charAt(0).toUpperCase() + value.slice(1) : 'System';
};

const referenceLabel = (log) => {
    const projectId = Number(log.metadata?.project_id || 0);

    if (projectId) {
        return `PRJ-${String(projectId).padStart(3, '0')}`;
    }

    if (log.entity_type === 'submissions') {
        return `SUB-${String(log.entity_id).padStart(3, '0')}`;
    }

    if (log.entity_type === 'users') {
        return `USR-${String(log.entity_id).padStart(3, '0')}`;
    }

    return `REF-${String(log.id).padStart(3, '0')}`;
};

const detailLabel = (log) => {
    if (log.metadata?.status) {
        return `Status ${String(log.metadata.status).replace(/_/g, ' ')}`;
    }

    const afterKeys = Object.keys(log.after || {});
    if (afterKeys.length) {
        return `Updated ${afterKeys.slice(0, 2).join(', ')}`;
    }

    return 'Action captured';
};

const userLabel = (log) => log.actor?.name || 'System';

const deviceLabel = (log) => {
    const agent = String(log.user_agent || 'Unknown device');
    const browser = ['Chrome', 'Firefox', 'Safari', 'Edge', 'Opera', 'Brave'].find((item) => agent.includes(item)) || 'Browser';
    const platform = ['Windows', 'macOS', 'Linux', 'Android', 'iPhone', 'iPad'].find((item) => agent.includes(item)) || 'Device';
    return `${browser} - ${platform}`;
};

onMounted(async () => {
    await loadLogs(1);
});
</script>

<template>
    <AppShell>
        <section class="tracky-audit-page">
            <header class="tracky-projects__head">
                <div>
                    <h2>Audit Log</h2>
                </div>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <section class="tracky-card tracky-audit-summary">
                <div>
                    <h3>Total Activities</h3>
                    <p class="tracky-figure">{{ totalActivities }}</p>
                    <span class="tracky-subtle">Actions</span>
                </div>
                <div>
                    <h3>Online Users</h3>
                    <p class="tracky-figure">{{ onlineUsers }}</p>
                    <span class="tracky-subtle">Active Users</span>
                </div>
            </section>

            <section class="tracky-card tracky-projects__toolbar">
                <div class="tracky-projects__filters">
                    <div class="tracky-projects__search-wrap">
                        <input v-model="filters.action" placeholder="Action type">
                    </div>
                    <input v-model="filters.user_id" type="text" inputmode="numeric" placeholder="User ID">
                    <input v-model="filters.date_from" type="date">
                    <input v-model="filters.date_to" type="date">
                </div>

                <div class="tracky-projects__head-actions">
                    <button class="tracky-btn tracky-btn--ghost" type="button" @click="setQuickFilter('today')">Today</button>
                    <button class="tracky-btn tracky-btn--ghost" type="button" @click="setQuickFilter('last7')">Last 7 Days</button>
                    <button class="tracky-btn tracky-btn--ghost" type="button" @click="resetFilters">Reset</button>
                    <button class="tracky-btn tracky-btn--primary" type="button" @click="applyFilters">Apply</button>
                </div>
            </section>

            <section class="tracky-card tracky-users-table-card">
                <div class="tracky-projects__empty" v-if="loading">Loading audit log...</div>

                <div class="tracky-projects-table-wrap" v-else-if="logs.length">
                    <table class="tracky-projects-table">
                        <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Module</th>
                            <th>Reference</th>
                            <th>Details</th>
                            <th>IP Address</th>
                            <th>Device / Platform</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="log in logs" :key="log.id" @click="selected = log">
                            <td>{{ log.timestamp ? new Date(log.timestamp).toLocaleString() : '-' }}</td>
                            <td>{{ userLabel(log) }}</td>
                            <td>{{ String(log.action || '').replace(/\./g, ' ') }}</td>
                            <td>{{ moduleLabel(log) }}</td>
                            <td>
                                <button class="tracky-btn tracky-btn--ghost tracky-btn--link" type="button" @click.stop="selected = log">
                                    {{ referenceLabel(log) }}
                                </button>
                            </td>
                            <td>{{ detailLabel(log) }}</td>
                            <td>{{ log.ip_address || '-' }}</td>
                            <td>{{ deviceLabel(log) }}</td>
                        </tr>
                        </tbody>
                    </table>
                </div>

                <div class="tracky-projects__empty" v-else>
                    <h3>No audit activity found.</h3>
                    <p>There are no records in the selected filter range.</p>
                </div>
            </section>

            <footer class="tracky-projects__pagination" v-if="!loading && pagination.last_page > 1">
                <p>Page {{ pagination.current_page }} of {{ pagination.last_page }}</p>
                <div class="tracky-page-buttons">
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.current_page <= 1" @click="goToPage(pagination.current_page - 1)">Prev</button>
                    <button
                        v-for="page in visiblePages"
                        :key="String(page)"
                        class="tracky-btn"
                        :class="typeof page === 'number' && page === pagination.current_page ? 'tracky-btn--primary' : 'tracky-btn--ghost'"
                        :disabled="typeof page !== 'number'"
                        @click="goToPage(page)"
                    >
                        {{ typeof page === 'number' ? page : '...' }}
                    </button>
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.current_page >= pagination.last_page" @click="goToPage(pagination.current_page + 1)">Next</button>
                </div>
            </footer>

            <div class="tracky-project-modal-backdrop" v-if="selected" @click.self="selected = null">
                <article class="tracky-audit-detail-modal">
                    <header class="tracky-project-modal__head">
                        <div>
                            <h3>Audit Entry #{{ selected.id }}</h3>
                            <p>{{ referenceLabel(selected) }}</p>
                        </div>
                        <button class="tracky-btn tracky-btn--ghost" type="button" @click="selected = null">Close</button>
                    </header>

                    <div class="tracky-audit-detail-grid">
                        <div class="tracky-project-section">
                            <h4>Before</h4>
                            <pre>{{ JSON.stringify(selected.before || {}, null, 2) }}</pre>
                        </div>
                        <div class="tracky-project-section">
                            <h4>After</h4>
                            <pre>{{ JSON.stringify(selected.after || {}, null, 2) }}</pre>
                        </div>
                        <div class="tracky-project-section tracky-audit-detail-grid__full">
                            <h4>Metadata</h4>
                            <pre>{{ JSON.stringify(selected.metadata || {}, null, 2) }}</pre>
                        </div>
                    </div>
                </article>
            </div>
        </section>
    </AppShell>
</template>
