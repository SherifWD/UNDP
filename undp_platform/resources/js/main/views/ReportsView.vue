<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import L from 'leaflet';
import AppShell from '../components/AppShell.vue';
import KpiCards from '../components/KpiCards.vue';
import api from '../api';
import { useAsyncExport } from '../composables/useAsyncExport';

const FILTER_STORAGE_KEY = 'undp_reports_filters_v1';
const asyncExport = useAsyncExport();

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
const mapShellRef = ref(null);
const isMapFullscreen = ref(false);
let map;
let markerLayer;
let mapRefreshTimer;
const projectOptionsLoaded = ref(false);

const STATUS_COLORS = {
    approved: '#16a34a',
    under_review: '#f59e0b',
    rework_requested: '#f97316',
    rejected: '#dc2626',
    submitted: '#0ea5e9',
    draft: '#64748b',
    queued: '#94a3b8',
};

const statusSlices = computed(() => {
    const entries = Object.entries(statusBreakdown.value || {})
        .filter(([, count]) => Number(count) > 0);

    const total = entries.reduce((sum, [, count]) => sum + Number(count), 0);
    let offset = 0;

    return entries.map(([status, count]) => {
        const safeCount = Number(count);
        const ratio = total > 0 ? safeCount / total : 0;
        const arc = ratio * 100;
        const slice = {
            status,
            count: safeCount,
            ratio,
            dash: `${arc} ${100 - arc}`,
            offset: -offset,
            color: STATUS_COLORS[status] || '#0ea5e9',
        };
        offset += arc;
        return slice;
    });
});

const municipalityMax = computed(() => {
    const values = municipalityBreakdown.value.map((item) => Number(item.count || 0));
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

const exportStatusLabel = computed(() => {
    if (!asyncExport.task.value) {
        return '';
    }

    const task = asyncExport.task.value;
    return `Export ${task.status} (${task.progress}%)`;
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
        if (mapRefreshTimer) {
            clearTimeout(mapRefreshTimer);
        }

        mapRefreshTimer = setTimeout(() => {
            loadMapData(true);
        }, 180);
    });
};

const spiderfyDuplicateMarkers = (items, zoom) => {
    const groups = new Map();

    items.forEach((item) => {
        const key = `${Number(item.lat).toFixed(5)}:${Number(item.lng).toFixed(5)}`;

        if (!groups.has(key)) {
            groups.set(key, []);
        }

        groups.get(key).push(item);
    });

    const spread = Math.max(0.00022, 0.0012 / Math.max(zoom, 1));
    const output = [];

    groups.forEach((group) => {
        if (group.length === 1) {
            output.push({
                ...group[0],
                display_lat: Number(group[0].lat),
                display_lng: Number(group[0].lng),
                overlap_count: 1,
                overlap_index: 1,
            });

            return;
        }

        const radius = spread * Math.min(2.2, 1 + group.length * 0.14);

        group.forEach((item, index) => {
            const angle = (2 * Math.PI * index) / group.length;
            output.push({
                ...item,
                display_lat: Number(item.lat) + Math.sin(angle) * radius,
                display_lng: Number(item.lng) + Math.cos(angle) * radius,
                overlap_count: group.length,
                overlap_index: index + 1,
            });
        });
    });

    return output;
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

    spiderfyDuplicateMarkers(markers.value, zoom).forEach((item) => {
        const marker = L.circleMarker([item.display_lat, item.display_lng], {
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
            ${item.overlap_count > 1 ? `Overlap group: ${item.overlap_index}/${item.overlap_count}<br/>` : ''}
            ${detailLink}
        `);

        markerLayer.addLayer(marker);
    });
};

const loadMunicipalities = async () => {
    if (municipalities.value.length) {
        return;
    }

    const { data } = await api.get('/municipalities');
    municipalities.value = data.data || [];
};

const loadProjectOptions = async (force = false) => {
    if (projectOptionsLoaded.value && !force) {
        return;
    }

    const { data } = await api.get('/projects', {
        params: {
            municipality_id: filters.municipality_id || undefined,
            per_page: 100,
        },
    });

    projects.value = data.data || [];
    projectOptionsLoaded.value = true;
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
                include_submissions: Boolean(filters.status || filters.project_id || ((map ? map.getZoom() : 8) >= 10)),
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

    projectOptionsLoaded.value = false;
    projects.value = [];

    await loadReports();
};

const onMunicipalityChange = async () => {
    filters.project_id = '';
    projectOptionsLoaded.value = false;
    await loadProjectOptions(true);
};

const drillStatus = async (status) => {
    filters.status = filters.status === status ? '' : status;
    await loadReports();
};

const startSubmissionsCsvExport = async () => {
    await asyncExport.startExport({
        format: 'csv',
        type: 'submissions',
        status: filters.status || null,
        municipality_id: filters.municipality_id || null,
        project_id: filters.project_id || null,
        date_from: filters.date_from || null,
        date_to: filters.date_to || null,
    });
};

const startSummaryPdfExport = async () => {
    await asyncExport.startExport({
        format: 'pdf',
        type: 'summary',
        status: filters.status || null,
        municipality_id: filters.municipality_id || null,
        project_id: filters.project_id || null,
        date_from: filters.date_from || null,
        date_to: filters.date_to || null,
    });
};

const onFullscreenChange = () => {
    isMapFullscreen.value = Boolean(document.fullscreenElement && mapShellRef.value && document.fullscreenElement === mapShellRef.value);
    setTimeout(() => map?.invalidateSize(), 80);
};

const toggleMapFullscreen = async () => {
    if (!mapShellRef.value) {
        return;
    }

    if (!document.fullscreenElement) {
        await mapShellRef.value.requestFullscreen();
        isMapFullscreen.value = true;
    } else if (document.fullscreenElement === mapShellRef.value) {
        await document.exitFullscreen();
        isMapFullscreen.value = false;
    }

    setTimeout(() => map?.invalidateSize(), 80);
};

onMounted(async () => {
    Object.assign(filters, persistedFilters());
    await initMap();
    await loadMunicipalities();
    if (filters.municipality_id || filters.project_id) {
        await loadProjectOptions(true);
    }
    await loadReports();
    document.addEventListener('fullscreenchange', onFullscreenChange);
});

onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', onFullscreenChange);

    if (mapRefreshTimer) {
        clearTimeout(mapRefreshTimer);
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
                <select v-model="filters.municipality_id" @change="onMunicipalityChange">
                    <option value="">All municipalities</option>
                    <option v-for="municipality in municipalities" :key="municipality.id" :value="municipality.id">
                        {{ municipality.name }}
                    </option>
                </select>
                <select v-model="filters.project_id" @focus="loadProjectOptions()">
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
                <button class="btn btn--ghost" :disabled="asyncExport.loading.value" @click="startSubmissionsCsvExport">Export CSV</button>
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
                    <h3>Status Analytics</h3>
                    <div class="status-chart-wrap">
                        <svg viewBox="0 0 42 42" class="status-donut" role="img" aria-label="Status breakdown chart">
                            <circle cx="21" cy="21" r="15.9155" fill="transparent" stroke="#e2e8f0" stroke-width="6" />
                            <circle
                                v-for="slice in statusSlices"
                                :key="slice.status"
                                cx="21"
                                cy="21"
                                r="15.9155"
                                fill="transparent"
                                :stroke="slice.color"
                                stroke-width="6"
                                :stroke-dasharray="slice.dash"
                                :stroke-dashoffset="slice.offset"
                                stroke-linecap="round"
                                @click="drillStatus(slice.status)"
                            />
                        </svg>
                        <ul class="status-legend">
                            <li v-for="slice in statusSlices" :key="slice.status">
                                <button class="status-legend__btn" type="button" @click="drillStatus(slice.status)">
                                    <span class="status-legend__dot" :style="{ backgroundColor: slice.color }" />
                                    <span>{{ slice.status }}</span>
                                    <strong>{{ slice.count }}</strong>
                                </button>
                            </li>
                        </ul>
                    </div>
                    <p class="panel__hint">Click a status to drill into filtered analytics.</p>
                </div>

                <div class="detail-block">
                    <h3>Municipality Breakdown</h3>
                    <ul class="bar-list">
                        <li v-for="row in municipalityBreakdown" :key="row.municipality_id">
                            <span>{{ row.municipality_name }}</span>
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
                    <div class="trend-chart" v-if="trendPath">
                        <svg viewBox="0 0 360 150" role="img" aria-label="Submission trend chart">
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
            </div>

            <div class="detail-block map-shell" ref="mapShellRef">
                <div class="map-shell__head">
                    <div>
                        <h3>Interactive Map</h3>
                        <p class="panel__hint">Zoom out for clusters. Zoom in for spiderfied overlapping markers and raw points.</p>
                    </div>
                    <button class="btn btn--ghost" type="button" @click="toggleMapFullscreen">
                        {{ isMapFullscreen ? 'Exit Full Screen' : 'Full Screen Map' }}
                    </button>
                </div>
                <div ref="mapRef" class="map-canvas"></div>
            </div>
        </section>
    </AppShell>
</template>
