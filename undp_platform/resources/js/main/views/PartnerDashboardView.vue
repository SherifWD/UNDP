<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import AppShell from '../components/AppShell.vue';
import KpiCards from '../components/KpiCards.vue';
import api from '../api';
import { useAsyncExport } from '../composables/useAsyncExport';

const STATUS_COLORS = {
    approved: '#16a34a',
    under_review: '#f59e0b',
    rework_requested: '#f97316',
    rejected: '#dc2626',
};

const asyncExport = useAsyncExport();
const router = useRouter();

const kpis = ref({});
const trend = ref([]);
const statusBreakdown = ref({});
const municipalityBreakdown = ref([]);
const projectBreakdown = ref([]);
const projectCards = ref([]);
const submissions = ref([]);
const municipalities = ref([]);
const projects = ref([]);
const loading = ref(false);
const tableLoading = ref(false);
const projectCardsLoading = ref(false);
const error = ref('');
const selectedStatus = ref('approved');

const filters = reactive({
    date_from: '',
    date_to: '',
    municipality_id: '',
    project_id: '',
    search: '',
});

const pagination = reactive({
    current_page: 1,
    last_page: 1,
    per_page: 12,
    total: 0,
});

const exportStatusLabel = computed(() => {
    if (!asyncExport.task.value) {
        return '';
    }

    const task = asyncExport.task.value;
    return `Export ${task.status} (${task.progress}%)`;
});

const activeFilterChips = computed(() => {
    const chips = [`Status: ${selectedStatus.value || 'approved'}`];

    if (filters.date_from) {
        chips.push(`From: ${filters.date_from}`);
    }

    if (filters.date_to) {
        chips.push(`To: ${filters.date_to}`);
    }

    if (filters.municipality_id) {
        const municipality = municipalities.value.find((item) => Number(item.id) === Number(filters.municipality_id));
        chips.push(`Municipality: ${municipality?.name || filters.municipality_id}`);
    }

    if (filters.project_id) {
        const project = projects.value.find((item) => Number(item.id) === Number(filters.project_id));
        chips.push(`Project: ${project?.name || filters.project_id}`);
    }

    if (filters.search) {
        chips.push(`Search: ${filters.search}`);
    }

    return chips;
});

const statusSlices = computed(() => {
    const rows = Object.entries(statusBreakdown.value || {})
        .map(([status, count]) => ({
            key: status,
            count: Number(count || 0),
            color: STATUS_COLORS[status] || '#0ea5e9',
        }))
        .filter((row) => row.count > 0);

    const total = rows.reduce((sum, row) => sum + row.count, 0);
    let offset = 0;

    return rows.map((row) => {
        const ratio = total > 0 ? row.count / total : 0;
        const arc = ratio * 100;
        const slice = {
            ...row,
            dash: `${arc} ${100 - arc}`,
            offset: -offset,
        };
        offset += arc;
        return slice;
    });
});

const municipalityMax = computed(() => {
    const values = municipalityBreakdown.value.map((item) => Number(item.count || 0));
    return values.length ? Math.max(...values, 1) : 1;
});

const projectMax = computed(() => {
    const values = projectBreakdown.value.map((item) => Number(item.count || 0));
    return values.length ? Math.max(...values, 1) : 1;
});

const trendPath = computed(() => {
    if (!trend.value.length) {
        return '';
    }

    const width = 360;
    const height = 150;
    const pad = 16;
    const maxCount = Math.max(...trend.value.map((item) => Number(item.count || 0)), 1);
    const xStep = trend.value.length > 1
        ? (width - pad * 2) / (trend.value.length - 1)
        : 0;

    return trend.value.map((item, index) => {
        const x = pad + xStep * index;
        const y = height - pad - ((Number(item.count || 0) / maxCount) * (height - pad * 2));
        return `${x},${y}`;
    }).join(' ');
});

const trendAreaPath = computed(() => {
    if (!trendPath.value) {
        return '';
    }

    const points = trendPath.value.split(' ');
    const first = points[0]?.split(',') || ['16', '134'];
    const last = points[points.length - 1]?.split(',') || ['344', '134'];
    const baseline = 150 - 16;

    return `M ${first[0]} ${baseline} L ${trendPath.value.replace(/,/g, ' ')} L ${last[0]} ${baseline} Z`;
});

const formatDateTime = (value) => {
    if (!value) {
        return '-';
    }

    return new Date(value).toLocaleString();
};

const rowStatusLabel = (value) => String(value || '-').replaceAll('_', ' ');

const filteredProjectCards = computed(() => {
    if (!projectCards.value.length) {
        return [];
    }

    const search = filters.search.trim().toLowerCase();

    return projectCards.value.filter((project) => {
        if (search) {
            const haystack = `${project.name || ''} ${project.id}`.toLowerCase();
            if (!haystack.includes(search)) {
                return false;
            }
        }

        if (selectedStatus.value === 'approved') {
            return Number(project.stats?.approved_submissions || 0) > 0;
        }

        if (selectedStatus.value === 'under_review') {
            return Number(project.stats?.pending_submissions || 0) > 0;
        }

        if (selectedStatus.value === 'rejected') {
            return Number(project.stats?.rejected_submissions || 0) > 0;
        }

        if (selectedStatus.value === 'rework_requested') {
            return Number(project.stats?.pending_submissions || 0) > 0;
        }

        return true;
    });
});

const loadMunicipalities = async () => {
    try {
        const { data } = await api.get('/municipalities');
        municipalities.value = data.data || [];
    } catch {
        municipalities.value = [];
    }
};

const loadProjectOptions = async () => {
    try {
        const { data } = await api.get('/projects', {
            params: {
                municipality_id: filters.municipality_id || undefined,
                per_page: 200,
            },
        });

        projects.value = data.data || [];
    } catch {
        projects.value = [];
    }
};

const loadProjectCards = async () => {
    projectCardsLoading.value = true;

    try {
        const { data } = await api.get('/projects', {
            params: {
                with_stats: 1,
                per_page: 200,
                municipality_id: filters.municipality_id || undefined,
            },
        });

        projectCards.value = data.data || [];
    } catch {
        projectCards.value = [];
    } finally {
        projectCardsLoading.value = false;
    }
};

const loadPartnerDashboard = async () => {
    loading.value = true;
    error.value = '';

    try {
        const { data } = await api.get('/dashboard/partner', {
            params: {
                date_from: filters.date_from || undefined,
                date_to: filters.date_to || undefined,
                municipality_id: filters.municipality_id || undefined,
                project_id: filters.project_id || undefined,
            },
        });

        kpis.value = data.kpis || {};
        trend.value = data.trend || [];
        statusBreakdown.value = data.status_breakdown || {};
        municipalityBreakdown.value = data.municipality_breakdown || [];
        projectBreakdown.value = data.project_breakdown || [];
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load partner dashboard.';
        kpis.value = {};
        trend.value = [];
        statusBreakdown.value = {};
        municipalityBreakdown.value = [];
        projectBreakdown.value = [];
    } finally {
        loading.value = false;
    }
};

const loadApprovedSubmissions = async (page = 1) => {
    tableLoading.value = true;

    try {
        const { data } = await api.get('/submissions', {
            params: {
                page,
                per_page: pagination.per_page,
                status: selectedStatus.value || 'approved',
                search: filters.search || undefined,
                date_from: filters.date_from || undefined,
                date_to: filters.date_to || undefined,
                municipality_id: filters.municipality_id || undefined,
                project_id: filters.project_id || undefined,
                sort_by: 'updated_at',
                sort_dir: 'desc',
            },
        });

        submissions.value = data.data || [];
        pagination.current_page = Number(data.current_page || 1);
        pagination.last_page = Number(data.last_page || 1);
        pagination.total = Number(data.total || submissions.value.length || 0);
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load approved submissions.';
        submissions.value = [];
        pagination.current_page = 1;
        pagination.last_page = 1;
        pagination.total = 0;
    } finally {
        tableLoading.value = false;
    }
};

const applyFilters = async () => {
    await Promise.all([
        loadPartnerDashboard(),
        loadProjectCards(),
        loadApprovedSubmissions(1),
    ]);
};

const resetFilters = async () => {
    Object.assign(filters, {
        date_from: '',
        date_to: '',
        municipality_id: '',
        project_id: '',
        search: '',
    });
    selectedStatus.value = 'approved';

    await loadProjectOptions();
    await applyFilters();
};

const onMunicipalityChange = async () => {
    filters.project_id = '';
    await loadProjectOptions();
};

const toggleStatusFilter = async (status) => {
    selectedStatus.value = selectedStatus.value === status ? 'approved' : status;
    await loadApprovedSubmissions(1);
};

const openProjectDetails = (project) => {
    router.push({
        name: 'project-submissions',
        params: {
            id: String(project.id),
        },
    });
};

const goToPage = async (page) => {
    if (page < 1 || page > pagination.last_page || page === pagination.current_page) {
        return;
    }

    await loadApprovedSubmissions(page);
};

const startApprovedCsvExport = async () => {
    await asyncExport.startExport({
        format: 'csv',
        type: 'submissions',
        status: 'approved',
        municipality_id: filters.municipality_id || null,
        project_id: filters.project_id || null,
        date_from: filters.date_from || null,
        date_to: filters.date_to || null,
        search: filters.search || null,
    });
};

const startSummaryPdfExport = async () => {
    await asyncExport.startExport({
        format: 'pdf',
        type: 'summary',
        status: 'approved',
        municipality_id: filters.municipality_id || null,
        project_id: filters.project_id || null,
        date_from: filters.date_from || null,
        date_to: filters.date_to || null,
    });
};

onMounted(async () => {
    await loadMunicipalities();
    await loadProjectOptions();
    await applyFilters();
});
</script>

<template>
    <AppShell>
        <section class="panel">
            <header class="panel__header">
                <h2>Partner / Donor Read-Only Dashboard</h2>
                <p class="panel__hint">Approved aggregated data only. No edit, validation, or configuration actions are available.</p>
            </header>

            <div class="view-only-banner">View-Only</div>
            <div class="read-only-lock">RBAC enforced: this view is restricted to approved aggregated records and export actions.</div>

            <p class="field-error" v-if="error">{{ error }}</p>

            <div class="toolbar">
                <input v-model="filters.date_from" type="date">
                <input v-model="filters.date_to" type="date">
                <select v-model="filters.municipality_id" @change="onMunicipalityChange">
                    <option value="">All municipalities</option>
                    <option v-for="municipality in municipalities" :key="municipality.id" :value="municipality.id">
                        {{ municipality.name }}
                    </option>
                </select>
                <select v-model="filters.project_id">
                    <option value="">All projects</option>
                    <option v-for="project in projects" :key="project.id" :value="project.id">
                        {{ project.name }}
                    </option>
                </select>
                <input v-model="filters.search" placeholder="Search approved submissions">
                <button class="btn btn--primary" @click="applyFilters">Apply</button>
                <button class="btn btn--ghost" @click="resetFilters">Reset</button>
                <button class="btn btn--ghost" :disabled="asyncExport.loading.value" @click="startApprovedCsvExport">Export CSV</button>
                <button class="btn btn--ghost" :disabled="asyncExport.loading.value" @click="startSummaryPdfExport">Export PDF</button>
                <button
                    v-if="asyncExport.task.value?.status === 'ready'"
                    class="btn btn--primary"
                    @click="asyncExport.download"
                >
                    Download
                </button>
            </div>
            <p class="panel__hint" v-if="exportStatusLabel">{{ exportStatusLabel }}</p>

            <div class="chips-row" v-if="activeFilterChips.length">
                <span class="filter-chip" v-for="chip in activeFilterChips" :key="chip">{{ chip }}</span>
            </div>

            <KpiCards :kpis="kpis" />

            <div class="split-grid">
                <div class="detail-block">
                    <h3>Submission Status Breakdown</h3>
                    <div class="status-chart-wrap">
                        <svg viewBox="0 0 42 42" class="status-donut" role="img" aria-label="Donor status breakdown chart">
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
                            <li v-for="slice in statusSlices" :key="slice.key">
                                <button class="status-legend__btn" type="button" @click="toggleStatusFilter(slice.key)">
                                    <span class="status-legend__dot" :style="{ backgroundColor: slice.color }" />
                                    <span>{{ rowStatusLabel(slice.key) }}</span>
                                    <strong>{{ slice.count }}</strong>
                                </button>
                            </li>
                        </ul>
                    </div>
                    <p class="panel__hint">Click a status segment to filter submission rows and project cards.</p>
                </div>

                <div class="detail-block">
                    <h3>Project List</h3>
                    <p class="panel__hint">Card grid with progress and latest updates.</p>
                    <div v-if="projectCardsLoading" class="panel__hint">Loading projects...</div>
                    <div v-else-if="!filteredProjectCards.length" class="panel__hint">No projects match the selected filters.</div>
                    <div v-else v-for="project in filteredProjectCards" :key="project.id" class="project-card">
                        <div class="project-card__top">
                            <strong>{{ project.name }}</strong>
                            <span>{{ Number(project.stats?.progress_percent || 0) }}%</span>
                        </div>
                        <div class="project-progress">
                            <div class="project-progress__bar" :style="{ width: `${Number(project.stats?.progress_percent || 0)}%` }" />
                        </div>
                        <small>
                            Total: {{ Number(project.stats?.total_submissions || 0) }} |
                            Approved: {{ Number(project.stats?.approved_submissions || 0) }} |
                            Rejected: {{ Number(project.stats?.rejected_submissions || 0) }}
                        </small>
                        <small>Last update: {{ formatDateTime(project.last_update_at) }}</small>
                        <button class="btn btn--ghost" type="button" @click="openProjectDetails(project)">
                            View Project Details
                        </button>
                    </div>
                </div>
            </div>

            <div class="split-grid">
                <div class="detail-block">
                    <h3>Municipality Breakdown (Approved)</h3>
                    <ul class="bar-list">
                        <li v-for="row in municipalityBreakdown" :key="row.municipality_id">
                            <span>{{ row.municipality_name || '-' }}</span>
                            <div class="bar-list__track">
                                <div
                                    class="bar-list__fill"
                                    :style="{ width: `${(Number(row.count || 0) / municipalityMax) * 100}%` }"
                                />
                            </div>
                            <strong>{{ row.count }}</strong>
                        </li>
                    </ul>
                </div>

                <div class="detail-block">
                    <h3>Project Breakdown (Approved)</h3>
                    <ul class="bar-list">
                        <li v-for="row in projectBreakdown" :key="row.project_id">
                            <span>{{ row.project_name || '-' }}</span>
                            <div class="bar-list__track">
                                <div
                                    class="bar-list__fill"
                                    :style="{ width: `${(Number(row.count || 0) / projectMax) * 100}%` }"
                                />
                            </div>
                            <strong>{{ row.count }}</strong>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="detail-block">
                <h3>Approved Trend</h3>
                <div class="trend-chart" v-if="trendPath">
                    <svg viewBox="0 0 360 150" role="img" aria-label="Partner approved trend chart">
                        <path v-if="trendAreaPath" :d="trendAreaPath" class="trend-area" />
                        <polyline :points="trendPath" class="trend-line" />
                    </svg>
                </div>
                <p class="panel__hint" v-if="!trendPath && !loading">No trend data available.</p>
                <ul class="trend-labels" v-if="trend.length">
                    <li v-for="item in trend" :key="item.day">
                        <small>{{ item.day }}</small>
                        <strong>{{ item.count }}</strong>
                    </li>
                </ul>
            </div>

            <div class="detail-block">
                <h3>Approved Submission Report</h3>
                <div class="table-wrap">
                    <table class="table" v-if="!tableLoading && submissions.length">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Project</th>
                                <th>Municipality</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in submissions" :key="row.id">
                                <td>#{{ row.id }}</td>
                                <td>{{ row.title || '-' }}</td>
                                <td>{{ row.project?.name || '-' }}</td>
                                <td>{{ row.municipality?.name || '-' }}</td>
                                <td>
                                    <span class="status-pill status-pill--active">{{ rowStatusLabel(row.status) }}</span>
                                </td>
                                <td>{{ formatDateTime(row.submitted_at || row.created_at) }}</td>
                                <td>{{ formatDateTime(row.updated_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="panel__hint" v-else-if="tableLoading">Loading approved submissions...</p>
                    <p class="panel__hint" v-else>No approved submissions found for these filters.</p>
                </div>

                <div class="pagination-bar" v-if="!tableLoading && pagination.last_page > 1">
                    <button
                        class="btn btn--ghost"
                        type="button"
                        :disabled="pagination.current_page <= 1"
                        @click="goToPage(pagination.current_page - 1)"
                    >
                        Previous
                    </button>
                    <button
                        class="btn btn--ghost"
                        type="button"
                        :disabled="pagination.current_page >= pagination.last_page"
                        @click="goToPage(pagination.current_page + 1)"
                    >
                        Next
                    </button>
                    <span class="pagination-meta">
                        Page {{ pagination.current_page }} of {{ pagination.last_page }}
                        ({{ pagination.total }} records)
                    </span>
                </div>
            </div>

        </section>
    </AppShell>
</template>
