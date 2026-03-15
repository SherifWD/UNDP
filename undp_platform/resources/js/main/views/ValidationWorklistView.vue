<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import AppShell from '../components/AppShell.vue';
import api from '../api';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
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

    if (filters.search) chips.push(`${t('common.search')}: ${filters.search}`);
    if (filters.status) chips.push(`${t('common.status')}: ${filters.status}`);
    if (filters.project_id) {
        const project = projects.value.find((item) => Number(item.id) === Number(filters.project_id));
        chips.push(`${t('common.project')}: ${project?.name || `#${filters.project_id}`}`);
    }
    if (filters.date_from) chips.push(`${t('common.dateFrom')}: ${filters.date_from}`);
    if (filters.date_to) chips.push(`${t('common.dateTo')}: ${filters.date_to}`);

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
        error.value = err.response?.data?.message || t('validation.unableToLoad');
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

const openSubmission = (submission) => {
    router.push({
        name: 'submission-detail',
        params: {
            id: submission.id,
        },
    });
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
                        <h2>{{ t('validation.title') }}</h2>
                        <p v-if="scope?.municipality_name">{{ t('validation.scopedTo', { name: scope.municipality_name }) }}</p>
                    </div>
                </div>

                <div class="tracky-project-flow__actions">
                    <button class="tracky-btn tracky-btn--ghost" type="button" @click="setSort('created_at', 'desc')">{{ t('validation.newest') }}</button>
                    <button class="tracky-btn tracky-btn--ghost" type="button" @click="setSort('created_at', 'asc')">{{ t('validation.oldest') }}</button>
                    <button class="tracky-btn tracky-btn--ghost" type="button" @click="setSort('project_id', 'asc')">{{ t('validation.byProject') }}</button>
                </div>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <section class="tracky-project-kpis">
                <article class="tracky-card tracky-kpi-panel">
                    <h3>{{ t('validation.pendingQueue') }}</h3>
                    <p class="tracky-kpi-panel__value">{{ pendingCount }}</p>
                </article>

                <article class="tracky-card tracky-kpi-panel">
                    <h3>{{ t('validation.activeFilters') }}</h3>
                    <p class="tracky-kpi-panel__value">{{ activeFilterCount }}</p>
                </article>

                <article class="tracky-card tracky-kpi-panel">
                    <h3>{{ t('validation.projectFilter') }}</h3>
                    <p class="tracky-kpi-panel__value">{{ filters.project_id ? '1' : '0' }}</p>
                </article>
            </section>

            <section class="tracky-card tracky-projects__toolbar">
                <div class="tracky-projects__filters">
                    <div class="tracky-projects__search-wrap">
                        <input v-model="filters.search" :placeholder="t('validation.searchPlaceholder')">
                    </div>

                    <select v-model="filters.status" @change="applyFilters">
                        <option value="">{{ t('validation.allPendingStatuses') }}</option>
                        <option value="under_review">{{ t('statusLabels.under_review') }}</option>
                        <option value="rework_requested">{{ t('statusLabels.rework_requested') }}</option>
                        <option value="submitted">{{ t('statusLabels.submitted') }}</option>
                    </select>

                    <select v-model="filters.project_id" @focus="ensureProjectsLoaded" @change="applyFilters">
                        <option value="">{{ projectsLoading ? t('common.loadingProjects') : t('validation.allProjects') }}</option>
                        <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
                    </select>

                    <input v-model="filters.date_from" type="date" @change="applyFilters">
                    <input v-model="filters.date_to" type="date" @change="applyFilters">
                </div>

                <div class="tracky-projects__head-actions">
                    <button class="tracky-btn tracky-btn--ghost" type="button" @click="resetFilters">{{ t('common.reset') }}</button>
                    <button class="tracky-btn tracky-btn--primary" type="button" @click="applyFilters">{{ t('common.apply') }}</button>
                </div>
            </section>

            <section class="tracky-card tracky-project-flow__table-wrap">
                <div class="chips-row" v-if="activeFilterChips.length">
                    <span class="filter-chip" v-for="chip in activeFilterChips" :key="chip">{{ chip }}</span>
                </div>

                <div class="tracky-projects__empty" v-if="loading">{{ t('validation.loadingPending') }}</div>

                <table class="tracky-projects-table" v-else-if="submissions.length">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>{{ t('common.title') }}</th>
                        <th>{{ t('validation.reporter') }}</th>
                        <th>{{ t('common.project') }}</th>
                        <th>{{ t('common.status') }}</th>
                        <th>{{ t('validation.created') }}</th>
                        <th>{{ t('common.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr
                        v-for="submission in submissions"
                        :key="submission.id"
                        :class="{ 'row-unread': isUnread(submission) }"
                        tabindex="0"
                        @click="openSubmission(submission)"
                        @keydown.enter.prevent="openSubmission(submission)"
                        @keydown.space.prevent="openSubmission(submission)"
                    >
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
                            <router-link class="tracky-btn tracky-btn--ghost" :to="{ name: 'submission-detail', params: { id: submission.id } }" @click.stop>
                                {{ t('validation.review') }}
                            </router-link>
                        </td>
                    </tr>
                    </tbody>
                </table>

                <div class="tracky-projects__empty" v-else>
                    <h3>{{ t('validation.noPendingTitle') }}</h3>
                    <p>{{ t('validation.noPendingBody') }}</p>
                </div>
            </section>

            <footer class="tracky-projects__pagination" v-if="!loading && pagination.last_page > 1">
                <p>{{ t('common.page', { page: pagination.current_page, total: pagination.last_page }) }}</p>
                <div class="tracky-page-buttons">
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.current_page <= 1" @click="goToPage(pagination.current_page - 1)">{{ t('common.previous') }}</button>
                    <button
                        v-for="page in visiblePages"
                        :key="page"
                        class="tracky-btn"
                        :class="page === pagination.current_page ? 'tracky-btn--primary' : 'tracky-btn--ghost'"
                        @click="goToPage(page)"
                    >
                        {{ page }}
                    </button>
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.current_page >= pagination.last_page" @click="goToPage(pagination.current_page + 1)">{{ t('common.next') }}</button>
                </div>
            </footer>
        </section>
    </AppShell>
</template>
