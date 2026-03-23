<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import AppShell from '../components/AppShell.vue';
import KpiCards from '../components/KpiCards.vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';

const { t } = useI18n();
const STATUS_COLORS = {
    under_review: '#f59e0b',
    approved: '#16a34a',
    rework_requested: '#f97316',
    rejected: '#dc2626',
};

const auth = useAuthStore();
const router = useRouter();

const loading = ref(false);
const error = ref('');
const municipalities = ref([]);
const overview = ref(null);
const selectedStatus = ref('');
const autoRefreshAt = ref('');
let refreshTimer = null;

const filters = reactive({
    municipality_id: '',
    search: '',
});

const canChooseMunicipality = computed(() => auth.hasPermission('dashboards.view.system'));

const statusRows = computed(() => {
    const breakdown = overview.value?.status_breakdown || {};

    return [
        { key: 'under_review', label: t('municipalOverview.underReview'), count: Number(breakdown.under_review || 0) },
        { key: 'approved', label: t('municipalOverview.approved'), count: Number(breakdown.approved || 0) },
        { key: 'rework_requested', label: t('dashboard.reworkRequested'), count: Number(breakdown.rework_requested || 0) },
        { key: 'rejected', label: t('municipalOverview.rejected'), count: Number(breakdown.rejected || 0) },
    ];
});

const statusSlices = computed(() => {
    const rows = statusRows.value.filter((row) => row.count > 0);
    const total = rows.reduce((sum, row) => sum + row.count, 0);
    let offset = 0;

    return rows.map((row) => {
        const ratio = total > 0 ? row.count / total : 0;
        const arc = ratio * 100;
        const slice = {
            ...row,
            dash: `${arc} ${100 - arc}`,
            offset: -offset,
            color: STATUS_COLORS[row.key] || '#0ea5e9',
        };
        offset += arc;
        return slice;
    });
});

const activeFilterChips = computed(() => {
    const chips = [];

    if (selectedStatus.value) {
        chips.push(`${t('common.status')}: ${selectedStatus.value}`);
    }

    if (filters.search) {
        chips.push(`${t('common.search')}: ${filters.search}`);
    }

    return chips;
});

const matchesSelectedStatus = (project) => {
    if (!selectedStatus.value) {
        return true;
    }

    if (selectedStatus.value === 'approved') {
        return Number(project.approved_submissions || 0) > 0;
    }

    if (selectedStatus.value === 'under_review') {
        return Number(project.under_review_submissions || 0) > 0;
    }

    if (selectedStatus.value === 'rework_requested') {
        return Number(project.rework_submissions || 0) > 0;
    }

    if (selectedStatus.value === 'rejected') {
        return Number(project.rejected_submissions || 0) > 0;
    }

    return true;
};

const filteredProjects = computed(() => {
    if (!overview.value?.projects?.length) {
        return [];
    }

    const search = filters.search.trim().toLowerCase();

    return overview.value.projects.filter((project) => {
        if (!matchesSelectedStatus(project)) {
            return false;
        }

        if (search) {
            const haystack = `${project.name || ''} ${project.id}`.toLowerCase();
            return haystack.includes(search);
        }

        return true;
    });
});

const municipalityName = computed(() => overview.value?.municipality?.name || t('municipalOverview.municipalityFallback'));

const loadMunicipalities = async () => {
    if (!canChooseMunicipality.value) {
        return;
    }

    try {
        const { data } = await api.get('/municipalities');
        municipalities.value = data.data || [];
    } catch {
        municipalities.value = [];
    }
};

const loadOverview = async (silent = false) => {
    if (!silent) {
        loading.value = true;
    }

    error.value = '';

    try {
        const params = canChooseMunicipality.value && filters.municipality_id
            ? { municipality_id: Number(filters.municipality_id) }
            : {};

        const { data } = await api.get('/dashboard/municipal-overview', { params });
        overview.value = data;
        autoRefreshAt.value = data.generated_at || new Date().toISOString();
        if (canChooseMunicipality.value && !filters.municipality_id && data.municipality?.id) {
            filters.municipality_id = String(data.municipality.id);
        }
    } catch (err) {
        error.value = err.response?.data?.message || t('municipalOverview.unableToLoad');
    } finally {
        if (!silent) {
            loading.value = false;
        }
    }
};

const applyFilters = async () => {
    await loadOverview();
};

const resetFilters = async () => {
    Object.assign(filters, {
        municipality_id: '',
        search: '',
    });
    selectedStatus.value = '';
    await loadOverview();
};

const toggleStatusFilter = (status) => {
    selectedStatus.value = selectedStatus.value === status ? '' : status;
};

const openProjectDetails = (project) => {
    router.push({
        name: 'project-submissions',
        params: {
            id: String(project.id),
        },
    });
};

onMounted(async () => {
    await loadMunicipalities();
    await loadOverview();

    refreshTimer = setInterval(() => {
        loadOverview(true);
    }, 30000);
});

onBeforeUnmount(() => {
    if (refreshTimer) {
        clearInterval(refreshTimer);
    }
});
</script>

<template>
    <AppShell>
        <section class="panel">
            <header class="panel__header">
                <h2>{{ t('municipalOverview.title') }}</h2>
                <p class="panel__hint">
                    {{ t('municipalOverview.dashboardHint', { name: municipalityName }) }}
                    <span v-if="autoRefreshAt">{{ t('municipalOverview.lastSync', { time: new Date(autoRefreshAt).toLocaleTimeString() }) }}</span>
                </p>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <section class="tracky-card tracky-projects__toolbar tracky-compact-toolbar">
                <div class="tracky-projects__filters">
                    <select v-if="canChooseMunicipality" v-model="filters.municipality_id">
                        <option value="">{{ t('municipalOverview.selectMunicipality') }}</option>
                        <option v-for="municipality in municipalities" :key="municipality.id" :value="municipality.id">
                            {{ municipality.name }}
                        </option>
                    </select>
                    <div class="tracky-projects__search-wrap">
                        <input v-model="filters.search" :placeholder="t('municipalOverview.searchPlaceholder')">
                    </div>
                    <button class="tracky-btn tracky-btn--primary" type="button" @click="applyFilters">{{ t('common.apply') }}</button>
                    <button class="tracky-btn tracky-btn--ghost" type="button" @click="resetFilters">{{ t('common.reset') }}</button>
                </div>
            </section>

            <div class="chips-row" v-if="activeFilterChips.length">
                <span class="filter-chip" v-for="chip in activeFilterChips" :key="chip">{{ chip }}</span>
            </div>

            <div v-if="loading">{{ t('common.loading') }}</div>

            <template v-else-if="overview">
                <KpiCards :kpis="overview.kpis || {}" />

                <div class="split-grid">
                    <div class="detail-block">
                        <h3>{{ t('municipalOverview.statusBreakdown') }}</h3>
                        <div class="status-chart-wrap">
                        <svg viewBox="0 0 42 42" class="status-donut" role="img" :aria-label="t('municipalOverview.statusBreakdown')">
                                <circle cx="21" cy="21" r="15.9155" fill="transparent" stroke="#e2e8f0" stroke-width="6" />
                                <circle
                                    v-for="slice in statusSlices"
                                    :key="slice.key"
                                    cx="21"
                                    cy="21"
                                    r="15.9155"
                                    fill="transparent"
                                    :stroke="slice.color"
                                    stroke-width="6"
                                    :stroke-dasharray="slice.dash"
                                    :stroke-dashoffset="slice.offset"
                                    stroke-linecap="round"
                                    @click="toggleStatusFilter(slice.key)"
                                />
                            </svg>
                            <ul class="status-legend">
                                <li v-for="row in statusRows" :key="row.key">
                                    <button class="status-legend__btn" type="button" @click="toggleStatusFilter(row.key)">
                                        <span class="status-legend__dot" :style="{ backgroundColor: STATUS_COLORS[row.key] }" />
                                        <span>{{ row.label }}</span>
                                        <strong>{{ row.count }}</strong>
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <p class="panel__hint">{{ t('municipalOverview.statusHint') }}</p>
                    </div>

                    <div class="detail-block">
                        <h3>{{ t('municipalOverview.projectList') }}</h3>
                        <div v-if="!filteredProjects.length" class="panel__hint">{{ t('municipalOverview.noProjects') }}</div>
                        <div v-for="project in filteredProjects" :key="project.id" class="project-card">
                            <div class="project-card__top">
                                <strong>{{ project.name }}</strong>
                                <span>{{ project.progress }}%</span>
                            </div>
                            <div class="project-progress">
                                <div class="project-progress__bar" :style="{ width: `${project.progress}%` }" />
                            </div>
                            <small>
                                {{ t('municipalOverview.total') }}: {{ project.total_submissions }} |
                                {{ t('municipalOverview.underReview') }}: {{ project.under_review_submissions }} |
                                {{ t('municipalOverview.approved') }}: {{ project.approved_submissions }} |
                                {{ t('municipalOverview.rework') }}: {{ project.rework_submissions }} |
                                {{ t('municipalOverview.rejected') }}: {{ project.rejected_submissions }}
                            </small>
                            <small>{{ t('municipalOverview.lastUpdate', { value: project.last_update_at ? new Date(project.last_update_at).toLocaleString() : '-' }) }}</small>
                            <button class="btn btn--ghost" type="button" @click="openProjectDetails(project)">
                                {{ t('municipalOverview.openProjectDetails') }}
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </section>
    </AppShell>
</template>
