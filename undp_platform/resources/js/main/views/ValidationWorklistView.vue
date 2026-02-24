<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import AppShell from '../components/AppShell.vue';
import api from '../api';

const loading = ref(false);
const error = ref('');
const submissions = ref([]);
const projects = ref([]);
const scope = ref(null);
let pollTimer = null;

const filters = reactive({
    search: '',
    status: '',
    project_id: '',
    date_from: '',
    date_to: '',
    sort_by: 'created_at',
    sort_dir: 'desc',
});

const pagination = reactive({
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: 0,
});

const pendingCount = computed(() => pagination.total || submissions.value.length);

const activeFilterChips = computed(() => {
    const chips = [];

    if (filters.status) {
        chips.push(`Status: ${filters.status}`);
    }

    if (filters.project_id) {
        const project = projects.value.find((item) => Number(item.id) === Number(filters.project_id));
        chips.push(`Project: ${project?.name || filters.project_id}`);
    }

    if (filters.date_from) {
        chips.push(`From: ${filters.date_from}`);
    }

    if (filters.date_to) {
        chips.push(`To: ${filters.date_to}`);
    }

    return chips;
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

const isUnread = (submission) => {
    if (!submission.created_at) {
        return false;
    }

    const createdAt = new Date(submission.created_at).getTime();
    return Date.now() - createdAt < 1000 * 60 * 60 * 24;
};

const setSort = async (sortBy, sortDir) => {
    filters.sort_by = sortBy;
    filters.sort_dir = sortDir;
    await loadPending(1);
};

const loadPending = async (page = pagination.current_page, silent = false) => {
    if (!silent) {
        loading.value = true;
    }

    error.value = '';

    try {
        const { data } = await api.get('/submissions/pending', {
            params: {
                ...filters,
                page,
                per_page: pagination.per_page,
            },
        });

        submissions.value = data.data || [];
        pagination.current_page = data.current_page || 1;
        pagination.last_page = data.last_page || 1;
        pagination.total = data.total || submissions.value.length;
        scope.value = data.scope || null;
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load pending submissions.';
    } finally {
        if (!silent) {
            loading.value = false;
        }
    }
};

const loadProjects = async () => {
    try {
        const { data } = await api.get('/projects');
        projects.value = data.data || [];
    } catch {
        projects.value = [];
    }
};

const applyFilters = async () => {
    await loadPending(1);
};

const goToPage = async (page) => {
    if (page < 1 || page > pagination.last_page || page === pagination.current_page) {
        return;
    }

    await loadPending(page);
};

onMounted(async () => {
    await Promise.all([
        loadProjects(),
        loadPending(1),
    ]);

    pollTimer = setInterval(() => {
        loadPending(pagination.current_page, true);
    }, 120000);
});

onBeforeUnmount(() => {
    if (pollTimer) {
        clearInterval(pollTimer);
    }
});
</script>

<template>
    <AppShell>
        <section class="panel">
            <header class="panel__header">
                <h2>Validator Worklist</h2>
                <p class="panel__hint">Pending submissions scoped to your municipality and role.</p>
                <p class="panel__hint" v-if="scope?.municipality_name">
                    Scope: {{ scope.municipality_name }}
                </p>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <div class="toolbar">
                <input v-model="filters.search" placeholder="Search submission title or ID">
                <select v-model="filters.status">
                    <option value="">All pending statuses</option>
                    <option value="under_review">Under Review</option>
                    <option value="rework_requested">Rework Requested</option>
                    <option value="submitted">Submitted</option>
                </select>
                <select v-model="filters.project_id">
                    <option value="">All projects</option>
                    <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
                </select>
                <input v-model="filters.date_from" type="date">
                <input v-model="filters.date_to" type="date">
                <button class="btn btn--primary" @click="applyFilters">Apply</button>
                <button class="btn btn--ghost" @click="setSort('created_at', 'desc')">Newest</button>
                <button class="btn btn--ghost" @click="setSort('created_at', 'asc')">Oldest</button>
                <button class="btn btn--ghost" @click="setSort('project_id', 'asc')">By Project</button>
                <span class="status-pill">Pending: {{ pendingCount }}</span>
            </div>

            <div class="chips-row" v-if="activeFilterChips.length">
                <span class="filter-chip" v-for="chip in activeFilterChips" :key="chip">{{ chip }}</span>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Reporter</th>
                        <th>Project</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-if="loading">
                        <td colspan="7">Loading...</td>
                    </tr>
                    <tr v-else-if="!submissions.length">
                        <td colspan="7">No pending submissions.</td>
                    </tr>
                    <tr v-for="submission in submissions" :key="submission.id" :class="{ 'row-unread': isUnread(submission) }">
                        <td>#{{ submission.id }}</td>
                        <td>{{ submission.title }}</td>
                        <td>{{ submission.reporter?.name }}</td>
                        <td>{{ submission.project?.name }}</td>
                        <td><span class="status-pill">{{ submission.status_label }}</span></td>
                        <td>{{ new Date(submission.created_at).toLocaleString() }}</td>
                        <td>
                            <router-link class="btn btn--ghost" :to="{ name: 'submission-detail', params: { id: submission.id } }">
                                Review
                            </router-link>
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
        </section>
    </AppShell>
</template>
