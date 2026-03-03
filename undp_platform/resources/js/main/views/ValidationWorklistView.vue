<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppShell from '../components/AppShell.vue';
import api from '../api';

const route = useRoute();
const loading = ref(false);
const error = ref('');
const submissions = ref([]);
const projects = ref([]);
const scope = ref(null);
const projectsLoading = ref(false);
const projectOptionsLoaded = ref(false);
let searchTimer = null;

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
    per_page: 15,
    total: 0,
});

const pendingCount = computed(() => pagination.total || submissions.value.length);
const activeFilterCount = computed(() => [filters.search, filters.status, filters.project_id, filters.date_from, filters.date_to].filter(Boolean).length);

const activeFilterChips = computed(() => {
    const chips = [];

    if (filters.search) chips.push(`Search: ${filters.search}`);
    if (filters.status) chips.push(`Status: ${filters.status}`);
    if (filters.project_id) {
        const project = projects.value.find((item) => Number(item.id) === Number(filters.project_id));
        chips.push(`Project: ${project?.name || `#${filters.project_id}`}`);
    }
    if (filters.date_from) chips.push(`From: ${filters.date_from}`);
    if (filters.date_to) chips.push(`To: ${filters.date_to}`);

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

const loadPending = async (page = pagination.current_page) => {
    loading.value = true;
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
        submissions.value = [];
        pagination.current_page = 1;
        pagination.last_page = 1;
        pagination.total = 0;
    } finally {
        loading.value = false;
    }
};

const loadProjects = async () => {
    projectsLoading.value = true;

    try {
        const { data } = await api.get('/projects', {
            params: {
                per_page: 50,
            },
        });

        projects.value = data.data || [];
        projectOptionsLoaded.value = true;
    } catch {
        projects.value = [];
    } finally {
        projectsLoading.value = false;
    }
};

const ensureProjectsLoaded = async () => {
    if (projectOptionsLoaded.value || projectsLoading.value) {
        return;
    }

    await loadProjects();
};

const setSort = async (sortBy, sortDir) => {
    filters.sort_by = sortBy;
    filters.sort_dir = sortDir;
    await loadPending(1);
};

const applyFilters = async () => {
    await loadPending(1);
};

const resetFilters = async () => {
    filters.search = '';
    filters.status = '';
    filters.project_id = '';
    filters.date_from = '';
    filters.date_to = '';
    filters.sort_by = 'created_at';
    filters.sort_dir = 'desc';
    await loadPending(1);
};

const initializeFiltersFromQuery = async () => {
    if (!route.query?.project_id) {
        return;
    }

    filters.project_id = String(route.query.project_id);
    await ensureProjectsLoaded();
};

const goToPage = async (page) => {
    if (page < 1 || page > pagination.last_page || page === pagination.current_page) {
        return;
    }

    await loadPending(page);
};

onMounted(async () => {
    await initializeFiltersFromQuery();
    await loadPending(1);
});

watch(() => route.query.project_id, async (value, previousValue) => {
    if (value === previousValue) {
        return;
    }

    filters.project_id = value ? String(value) : '';

    if (value) {
        await ensureProjectsLoaded();
    }

    await loadPending(1);
});

watch(() => filters.search, () => {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(() => {
        loadPending(1);
    }, 300);
});

onBeforeUnmount(() => {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }
});
</script>

<template>
    <AppShell>
        <section class="tracky-project-flow">
            <header class="tracky-project-flow__head">
                <div class="tracky-project-flow__title">
                    <div>
                        <h2>Submission Review</h2>
                        <p v-if="scope?.municipality_name">Scoped to {{ scope.municipality_name }}</p>
                        <p v-else>Pending submissions available in your current role scope.</p>
                    </div>
                </div>

                <div class="tracky-project-flow__actions">
                    <button class="tracky-btn tracky-btn--ghost" type="button" @click="setSort('created_at', 'desc')">Newest</button>
                    <button class="tracky-btn tracky-btn--ghost" type="button" @click="setSort('created_at', 'asc')">Oldest</button>
                    <button class="tracky-btn tracky-btn--ghost" type="button" @click="setSort('project_id', 'asc')">By Project</button>
                </div>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <section class="tracky-project-kpis">
                <article class="tracky-card tracky-kpi-panel">
                    <h3>Pending Queue</h3>
                    <p class="tracky-kpi-panel__value">{{ pendingCount }}</p>
                    <p class="tracky-subtle">Items currently waiting for validation.</p>
                </article>

                <article class="tracky-card tracky-kpi-panel">
                    <h3>Active Filters</h3>
                    <p class="tracky-kpi-panel__value">{{ activeFilterCount }}</p>
                    <p class="tracky-subtle">Use filters only when needed to keep the page responsive.</p>
                </article>

                <article class="tracky-card tracky-kpi-panel">
                    <h3>Project Filter</h3>
                    <p class="tracky-kpi-panel__value">{{ filters.project_id ? '1' : '0' }}</p>
                    <p class="tracky-subtle">Project options load only when the selector is opened.</p>
                </article>
            </section>

            <section class="tracky-card tracky-projects__toolbar">
                <div class="tracky-projects__filters">
                    <div class="tracky-projects__search-wrap">
                        <input v-model="filters.search" placeholder="Search submission title or ID">
                    </div>

                    <select v-model="filters.status" @change="applyFilters">
                        <option value="">All pending statuses</option>
                        <option value="under_review">Under Review</option>
                        <option value="rework_requested">Rework Requested</option>
                        <option value="submitted">Submitted</option>
                    </select>

                    <select v-model="filters.project_id" @focus="ensureProjectsLoaded" @change="applyFilters">
                        <option value="">{{ projectsLoading ? 'Loading projects...' : 'All projects' }}</option>
                        <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
                    </select>

                    <input v-model="filters.date_from" type="date" @change="applyFilters">
                    <input v-model="filters.date_to" type="date" @change="applyFilters">
                </div>

                <div class="tracky-projects__head-actions">
                    <button class="tracky-btn tracky-btn--ghost" type="button" @click="resetFilters">Reset</button>
                    <button class="tracky-btn tracky-btn--primary" type="button" @click="applyFilters">Apply</button>
                </div>
            </section>

            <section class="tracky-card tracky-project-flow__table-wrap">
                <div class="chips-row" v-if="activeFilterChips.length">
                    <span class="filter-chip" v-for="chip in activeFilterChips" :key="chip">{{ chip }}</span>
                </div>

                <div class="tracky-projects__empty" v-if="loading">Loading pending submissions...</div>

                <table class="tracky-projects-table" v-else-if="submissions.length">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Reporter</th>
                        <th>Project</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="submission in submissions" :key="submission.id" :class="{ 'row-unread': isUnread(submission) }">
                        <td>#{{ submission.id }}</td>
                        <td>{{ submission.title }}</td>
                        <td>{{ submission.reporter?.name || '-' }}</td>
                        <td>{{ submission.project?.name || '-' }}</td>
                        <td>
                            <span class="badge" :class="submission.status === 'under_review' ? 'badge--medium' : 'badge--high'">
                                {{ submission.status_label }}
                            </span>
                        </td>
                        <td>{{ submission.created_at ? new Date(submission.created_at).toLocaleString() : '-' }}</td>
                        <td>
                            <router-link class="tracky-btn tracky-btn--ghost" :to="{ name: 'submission-detail', params: { id: submission.id } }">
                                Review
                            </router-link>
                        </td>
                    </tr>
                    </tbody>
                </table>

                <div class="tracky-projects__empty" v-else>
                    <h3>No pending submissions.</h3>
                    <p>There are no submissions matching the current validation scope.</p>
                </div>
            </section>

            <footer class="tracky-projects__pagination" v-if="!loading && pagination.last_page > 1">
                <p>Page {{ pagination.current_page }} of {{ pagination.last_page }}</p>
                <div class="tracky-page-buttons">
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.current_page <= 1" @click="goToPage(pagination.current_page - 1)">Prev</button>
                    <button
                        v-for="page in visiblePages"
                        :key="page"
                        class="tracky-btn"
                        :class="page === pagination.current_page ? 'tracky-btn--primary' : 'tracky-btn--ghost'"
                        @click="goToPage(page)"
                    >
                        {{ page }}
                    </button>
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.current_page >= pagination.last_page" @click="goToPage(pagination.current_page + 1)">Next</button>
                </div>
            </footer>
        </section>
    </AppShell>
</template>
