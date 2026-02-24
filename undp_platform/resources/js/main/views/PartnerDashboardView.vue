<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AppShell from '../components/AppShell.vue';
import KpiCards from '../components/KpiCards.vue';
import api from '../api';

const kpis = ref({});
const trend = ref([]);
const municipalities = ref([]);
const projects = ref([]);
const loading = ref(false);
const error = ref('');

const filters = reactive({
    date_from: '',
    date_to: '',
    municipality_id: '',
    project_id: '',
});

const exportPdfUrl = computed(() => {
    const params = new URLSearchParams({
        municipality_id: filters.municipality_id || '',
        project_id: filters.project_id || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
    });

    return `/api/exports/pdf?${params.toString()}`;
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

onMounted(async () => {
    await loadLookups();
    await loadPartnerDashboard();
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
                <button class="btn btn--primary" @click="loadPartnerDashboard">Apply</button>
                <a class="btn btn--ghost" :href="exportPdfUrl">Export PDF</a>
            </div>

            <KpiCards :kpis="kpis" />

            <div class="detail-block">
                <h3>Approved Trend</h3>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Approved Count</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-if="loading">
                        <td colspan="2">Loading...</td>
                    </tr>
                    <tr v-else-if="!trend.length">
                        <td colspan="2">No trend data available.</td>
                    </tr>
                    <tr v-for="item in trend" :key="item.day">
                        <td>{{ item.day }}</td>
                        <td>{{ item.count }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppShell>
</template>
