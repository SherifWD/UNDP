<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import AppShell from '../components/AppShell.vue';
import KpiCards from '../components/KpiCards.vue';
import api from '../api';
import { useAsyncExport } from '../composables/useAsyncExport';

const asyncExport = useAsyncExport();
const kpis = ref({});
const trend = ref([]);
const municipalities = ref([]);
const projects = ref([]);
const loading = ref(false);
const error = ref('');
let refreshTimer = null;

const filters = reactive({
    date_from: '',
    date_to: '',
    municipality_id: '',
    project_id: '',
});

const exportStatusLabel = computed(() => {
    if (!asyncExport.task.value) {
        return '';
    }

    const task = asyncExport.task.value;
    return `Export ${task.status} (${task.progress}%)`;
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

const loadLookups = async () => {
    try {
        const [municipalityRes, projectRes] = await Promise.all([
            api.get('/municipalities'),
            api.get('/projects'),
        ]);

        municipalities.value = municipalityRes.data.data || [];
        projects.value = projectRes.data.data || [];
    } catch {
        municipalities.value = [];
        projects.value = [];
    }
};

const loadPartnerDashboard = async () => {
    loading.value = true;
    error.value = '';

    try {
        const { data } = await api.get('/dashboard/partner', { params: filters });
        kpis.value = data.kpis || {};
        trend.value = data.trend || [];
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load partner dashboard.';
    } finally {
        loading.value = false;
    }
};

const startSummaryPdfExport = async () => {
    await asyncExport.startExport({
        format: 'pdf',
        type: 'summary',
        municipality_id: filters.municipality_id || null,
        project_id: filters.project_id || null,
        date_from: filters.date_from || null,
        date_to: filters.date_to || null,
    });
};

const queueAutoRefresh = () => {
    if (refreshTimer) {
        clearTimeout(refreshTimer);
    }

    refreshTimer = setTimeout(() => {
        loadPartnerDashboard();
    }, 220);
};

watch(
    () => [filters.date_from, filters.date_to, filters.municipality_id, filters.project_id],
    queueAutoRefresh,
);

onMounted(async () => {
    await loadLookups();
    await loadPartnerDashboard();
});

onBeforeUnmount(() => {
    if (refreshTimer) {
        clearTimeout(refreshTimer);
    }
});
</script>

<template>
    <AppShell>
        <section class="panel">
            <header class="panel__header">
                <h2>Partner / Donor Read-Only Dashboard</h2>
                <p class="panel__hint">Approved aggregated data only.</p>
            </header>

            <div class="view-only-banner">View-Only</div>
            <div class="read-only-lock">Locked: no edit, validation, or configuration actions are available in this view.</div>

            <p class="field-error" v-if="error">{{ error }}</p>

            <div class="toolbar">
                <input v-model="filters.date_from" type="date">
                <input v-model="filters.date_to" type="date">
                <select v-model="filters.municipality_id">
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
                <button class="btn btn--primary" @click="loadPartnerDashboard">Refresh</button>
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

            <KpiCards :kpis="kpis" />

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
        </section>
    </AppShell>
</template>
