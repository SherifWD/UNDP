<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AppShell from '../components/AppShell.vue';
import KpiCards from '../components/KpiCards.vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();

const loading = ref(false);
const error = ref('');
const municipalities = ref([]);
const overview = ref(null);
const selectedStatus = ref('');

const filters = reactive({
    municipality_id: '',
});

const canChooseMunicipality = computed(() => auth.hasPermission('dashboards.view.system'));

const statusRows = computed(() => {
    if (!overview.value?.kpis) {
        return [];
    }

    return [
        { key: 'under_review', label: 'Under Review', count: overview.value.kpis.under_review || 0 },
        { key: 'approved', label: 'Approved', count: overview.value.kpis.approved || 0 },
        { key: 'rework_requested', label: 'Rework Requested', count: overview.value.kpis.rework_requested || 0 },
        { key: 'rejected', label: 'Rejected', count: overview.value.kpis.rejected || 0 },
    ];
});

const filteredProjects = computed(() => {
    if (!overview.value?.projects?.length) {
        return [];
    }

    if (!selectedStatus.value) {
        return overview.value.projects;
    }

    return overview.value.projects.filter((project) => {
        if (selectedStatus.value === 'approved') {
            return (project.approved_submissions || 0) > 0;
        }

        if (selectedStatus.value === 'under_review') {
            return (project.total_submissions || 0) > (project.approved_submissions || 0);
        }

        return true;
    });
});

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

const loadOverview = async () => {
    loading.value = true;
    error.value = '';

    try {
        const params = canChooseMunicipality.value && filters.municipality_id
            ? { municipality_id: Number(filters.municipality_id) }
            : {};

        const { data } = await api.get('/dashboard/municipal-overview', { params });
        overview.value = data;
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load municipal overview.';
    } finally {
        loading.value = false;
    }
};

onMounted(async () => {
    await loadMunicipalities();
    await loadOverview();
});
</script>

<template>
    <AppShell>
        <section class="panel">
            <header class="panel__header">
                <h2>Municipal Overview</h2>
                <p class="panel__hint">Municipality-scoped submissions and project progress.</p>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <div class="toolbar" v-if="canChooseMunicipality">
                <select v-model="filters.municipality_id">
                    <option value="">My default municipality</option>
                    <option v-for="municipality in municipalities" :key="municipality.id" :value="municipality.id">
                        {{ municipality.name }}
                    </option>
                </select>
                <button class="btn btn--primary" @click="loadOverview">Apply</button>
            </div>

            <div v-if="loading">Loading...</div>

            <template v-else-if="overview">
                <h3>{{ overview.municipality?.name }}</h3>
                <KpiCards :kpis="overview.kpis || {}" />

                <div class="split-grid">
                    <div class="detail-block">
                        <h3>Status Breakdown</h3>
                        <ul class="stat-list">
                            <li
                                v-for="row in statusRows"
                                :key="row.key"
                                :class="{ 'row-selected': selectedStatus === row.key }"
                                @click="selectedStatus = selectedStatus === row.key ? '' : row.key"
                            >
                                <span>{{ row.label }}</span>
                                <strong>{{ row.count }}</strong>
                            </li>
                        </ul>
                        <p class="panel__hint">Click a status row to filter projects contextually.</p>
                    </div>

                    <div class="detail-block">
                        <h3>Projects</h3>
                        <div v-if="!filteredProjects.length" class="panel__hint">No projects available.</div>
                        <div v-for="project in filteredProjects" :key="project.id" class="project-card">
                            <div class="project-card__top">
                                <strong>{{ project.name }}</strong>
                                <span>{{ project.progress }}%</span>
                            </div>
                            <div class="project-progress">
                                <div class="project-progress__bar" :style="{ width: `${project.progress}%` }" />
                            </div>
                            <small>
                                Total: {{ project.total_submissions }} | Approved: {{ project.approved_submissions }} |
                                Last update: {{ project.last_update_at ? new Date(project.last_update_at).toLocaleString() : '-' }}
                            </small>
                        </div>
                    </div>
                </div>
            </template>
        </section>
    </AppShell>
</template>
