<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import L from 'leaflet';
import AppShell from '../components/AppShell.vue';
import KpiCards from '../components/KpiCards.vue';
import api from '../api';

const FILTER_STORAGE_KEY = 'undp_reports_filters_v1';

const kpis = ref({});
const statusBreakdown = ref({});
const municipalityBreakdown = ref([]);
const projectBreakdown = ref([]);
const trend = ref([]);
const municipalities = ref([]);
const projects = ref([]);
const markers = ref([]);
const clusters = ref([]);
const loading = ref(false);
const error = ref('');

const filters = reactive({
    date_from: '',
    date_to: '',
    municipality_id: '',
    project_id: '',
    status: '',
});

const mapRef = ref(null);
let map;
let markerLayer;
let pollTimer;

const activeFilterChips = computed(() => {
    const chips = [];

    if (filters.status) {
        chips.push(`Status: ${filters.status}`);
    }
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

    return chips;
});

const exportCsvUrl = computed(() => {
    const params = new URLSearchParams({
        type: 'submissions',
        status: filters.status || '',
        municipality_id: filters.municipality_id || '',
        project_id: filters.project_id || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
    });

    return `/api/exports/csv?${params.toString()}`;
});

const exportPdfUrl = computed(() => {
    const params = new URLSearchParams({
        status: filters.status || '',
        municipality_id: filters.municipality_id || '',
        project_id: filters.project_id || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
    });

    return `/api/exports/pdf?${params.toString()}`;
});

const persistedFilters = () => {
    try {
        return JSON.parse(localStorage.getItem(FILTER_STORAGE_KEY) || '{}');
    } catch {
        return {};
    }
};

const persistFilters = () => {
    localStorage.setItem(FILTER_STORAGE_KEY, JSON.stringify(filters));
};

const initMap = async () => {
    await nextTick();

    if (!mapRef.value || map) return;

    map = L.map(mapRef.value).setView([26.3351, 17.2283], 6);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    markerLayer = L.layerGroup().addTo(map);

    map.on('zoomend', () => {
        loadMapData(true);
    });
};

const renderMarkers = () => {
    if (!markerLayer) return;
    markerLayer.clearLayers();

    const zoom = map ? map.getZoom() : 8;
    const useClusterPresentation = zoom < 11 && clusters.value.length > 0;

    if (useClusterPresentation) {
        clusters.value.forEach((cluster) => {
            const icon = L.divIcon({
                className: 'map-cluster-icon',
                html: `<span>${cluster.count}</span>`,
                iconSize: [34, 34],
            });

            const marker = L.marker([cluster.lat, cluster.lng], { icon });
            marker.bindPopup(`
                <strong>Cluster</strong><br/>
                Total: ${cluster.count}<br/>
                Projects: ${cluster.projects}<br/>
                Submissions: ${cluster.submissions}
            `);
            markerLayer.addLayer(marker);
        });

        return;
    }

    markers.value.forEach((item) => {
        const marker = L.circleMarker([item.lat, item.lng], {
            radius: item.type === 'project' ? 7 : 6,
            color: item.type === 'project' ? '#0ea5e9' : '#f97316',
            fillColor: item.type === 'project' ? '#0ea5e9' : '#f97316',
            fillOpacity: 0.85,
        });

        const detailLink = item.type === 'submission'
            ? `<a href="/submissions/${item.id}" target="_blank" rel="noopener">View details</a>`
            : '-';

        marker.bindPopup(`
            <strong>${item.name}</strong><br/>
            Type: ${item.type}<br/>
            Status: ${item.status}<br/>
            Municipality: ${item.municipality || '-'}<br/>
            ${detailLink}
        `);

        markerLayer.addLayer(marker);
    });
};

const loadLookups = async () => {
    const [municipalitiesRes, projectsRes] = await Promise.all([
        api.get('/municipalities'),
        api.get('/projects'),
    ]);

    municipalities.value = municipalitiesRes.data.data || [];
    projects.value = projectsRes.data.data || [];
};

const loadKpis = async () => {
    const { data } = await api.get('/dashboard/kpis', { params: filters });
    kpis.value = data.kpis || {};
    statusBreakdown.value = data.status_breakdown || {};
    municipalityBreakdown.value = data.municipality_breakdown || [];
    projectBreakdown.value = data.project_breakdown || [];
    trend.value = data.trend || [];
};

const loadMapData = async (silent = false) => {
    if (!silent) {
        loading.value = true;
    }

    try {
        const { data } = await api.get('/dashboard/map', {
            params: {
                ...filters,
                cluster: true,
                cluster_zoom: map ? map.getZoom() : 8,
            },
        });

        markers.value = data.markers || [];
        clusters.value = data.clusters || [];
        renderMarkers();
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load map data.';
    } finally {
        if (!silent) {
            loading.value = false;
        }
    }
};

const loadReports = async () => {
    loading.value = true;
    error.value = '';
    persistFilters();

    try {
        await Promise.all([
            loadKpis(),
            loadMapData(true),
        ]);
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load report data.';
    } finally {
        loading.value = false;
    }
};

const applyFilters = async () => {
    await loadReports();
};

const resetFilters = async () => {
    Object.assign(filters, {
        date_from: '',
        date_to: '',
        municipality_id: '',
        project_id: '',
        status: '',
    });

    await loadReports();
};

const drillStatus = async (status) => {
    filters.status = filters.status === status ? '' : status;
    await loadReports();
};

onMounted(async () => {
    Object.assign(filters, persistedFilters());
    await initMap();
    await loadLookups();
    await loadReports();

    pollTimer = setInterval(() => {
        loadReports();
    }, 180000);
});

onBeforeUnmount(() => {
    if (pollTimer) {
        clearInterval(pollTimer);
    }
    if (map) {
        map.off();
        map.remove();
        map = null;
    }
});
</script>

<template>
    <AppShell>
        <section class="panel">
            <header class="panel__header">
                <h2>KPI & Geo Reports</h2>
                <p class="panel__hint">Advanced analytics, drill-down filters, and clustered map markers.</p>
            </header>

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
                <select v-model="filters.status">
                    <option value="">All statuses</option>
                    <option value="under_review">Under Review</option>
                    <option value="approved">Approved</option>
                    <option value="rework_requested">Rework Requested</option>
                    <option value="rejected">Rejected</option>
                </select>
                <button class="btn btn--primary" @click="applyFilters">Apply</button>
                <button class="btn btn--ghost" @click="resetFilters">Reset Filters</button>
                <a class="btn btn--ghost" :href="exportCsvUrl">Export CSV</a>
                <a class="btn btn--ghost" :href="exportPdfUrl">Export PDF</a>
            </div>

            <div class="chips-row" v-if="activeFilterChips.length">
                <span class="filter-chip" v-for="chip in activeFilterChips" :key="chip">{{ chip }}</span>
            </div>

            <KpiCards :kpis="kpis" />

            <div class="split-grid">
                <div class="detail-block">
                    <h3>Status Analytics</h3>
                    <ul class="stat-list">
                        <li v-for="(count, status) in statusBreakdown" :key="status" @click="drillStatus(status)">
                            <span>{{ status }}</span>
                            <strong>{{ count }}</strong>
                        </li>
                    </ul>
                    <p class="panel__hint">Click a status to drill into filtered analytics.</p>
                </div>

                <div class="detail-block">
                    <h3>Municipality Breakdown</h3>
                    <ul class="stat-list">
                        <li v-for="row in municipalityBreakdown" :key="row.municipality_id">
                            <span>{{ row.municipality_name }}</span>
                            <strong>{{ row.count }}</strong>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="split-grid">
                <div class="detail-block">
                    <h3>Project Breakdown</h3>
                    <ul class="stat-list">
                        <li v-for="row in projectBreakdown" :key="row.project_id">
                            <span>{{ row.project_name }}</span>
                            <strong>{{ row.count }}</strong>
                        </li>
                    </ul>
                </div>

                <div class="detail-block">
                    <h3>Trend</h3>
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Day</th>
                            <th>Count</th>
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
            </div>

            <div class="detail-block">
                <h3>Interactive Map</h3>
                <p class="panel__hint">Zoom out to view clustered markers; zoom in for raw project/submission points.</p>
                <div ref="mapRef" class="map-canvas"></div>
            </div>
        </section>
    </AppShell>
</template>
