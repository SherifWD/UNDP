<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import L from 'leaflet';
import AppShell from '../components/AppShell.vue';
import KpiCards from '../components/KpiCards.vue';
import api from '../api';
import { useAsyncExport } from '../composables/useAsyncExport';
import { useAuthStore } from '../stores/auth';

const FILTER_STORAGE_KEY = 'undp_reports_filters_v1';
const asyncExport = useAsyncExport();
const auth = useAuthStore();

const kpis = ref({});
const statusBreakdown = ref({});
const statusSummary = ref([]);
const municipalityBreakdown = ref([]);
const projectBreakdown = ref([]);
const trend = ref([]);
const reviewBacklog = ref([]);
const fundingOverview = ref(null);
const municipalities = ref([]);
const projects = ref([]);
const markers = ref([]);
const clusters = ref([]);
const loading = ref(false);
const error = ref('');
const detailRows = ref([]);
const detailLoading = ref(false);
const detailError = ref('');
const reportType = ref('submissions');
const reportPagination = reactive({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
});
const detailFilters = reactive({
    search: '',
    action: '',
    role: '',
    user_id: '',
    status: '',
    sort_by: '',
    sort_dir: 'desc',
});
const fundingRequests = ref([]);
const fundingLoading = ref(false);
const fundingError = ref('');
const fundingStatusFilter = ref('pending');
const fundingReviewNotes = reactive({});

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

const FUNDING_STATUS_COLORS = {
    pending: '#f59e0b',
    approved: '#16a34a',
    declined: '#dc2626',
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

const projectMax = computed(() => {
    const values = projectBreakdown.value.map((item) => Number(item.count || 0));
    return values.length ? Math.max(...values, 1) : 1;
});

const reviewBacklogMax = computed(() => {
    const values = reviewBacklog.value.map((item) => Number(item.count || 0));
    return values.length ? Math.max(...values, 1) : 1;
});

const fundingSlices = computed(() => {
    const entries = Object.entries(fundingOverview.value?.status_breakdown || {})
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
            color: FUNDING_STATUS_COLORS[status] || '#0ea5e9',
        };
        offset += arc;
        return slice;
    });
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

const detailFilterChips = computed(() => {
    const chips = [`Report: ${reportTypeLabel.value}`];

    if (detailFilters.search) {
        chips.push(`Search: ${detailFilters.search}`);
    }

    if (reportType.value === 'audit_logs' && detailFilters.action) {
        chips.push(`Action: ${detailFilters.action}`);
    }

    if (reportType.value === 'users' && detailFilters.role) {
        chips.push(`Role: ${detailFilters.role}`);
    }

    if (detailFilters.status) {
        chips.push(`Status: ${detailFilters.status}`);
    }

    if (detailFilters.user_id) {
        chips.push(`User ID: ${detailFilters.user_id}`);
    }

    return chips;
});

const canExportCsv = computed(() => auth.hasPermission('reports.export.csv'));
const canExportPdf = computed(() => auth.hasPermission('reports.export.pdf'));
const canViewAudit = computed(() => auth.hasPermission('audit.view'));
const canViewUsers = computed(() => auth.hasPermission('users.view'));
const canReviewFundingRequests = computed(() => auth.hasPermission('funding_requests.review'));

const availableReportTypes = computed(() => {
    const options = [
        { value: 'submissions', label: 'Submissions Report' },
    ];

    if (canViewAudit.value) {
        options.push({ value: 'audit_logs', label: 'Audit Log Report' });
    }

    if (canViewUsers.value) {
        options.push({ value: 'users', label: 'Users Report' });
    }

    return options;
});

const reportTypeLabel = computed(() => {
    return availableReportTypes.value.find((option) => option.value === reportType.value)?.label || 'Submissions Report';
});

const detailSortOptions = computed(() => {
    if (reportType.value === 'users') {
        return [
            { value: 'created_at', label: 'Created At' },
            { value: 'last_login_at', label: 'Last Login' },
            { value: 'name', label: 'Name' },
            { value: 'role', label: 'Role' },
            { value: 'status', label: 'Status' },
        ];
    }

    if (reportType.value === 'submissions') {
        return [
            { value: 'created_at', label: 'Created At' },
            { value: 'submitted_at', label: 'Submitted At' },
            { value: 'updated_at', label: 'Updated At' },
            { value: 'status', label: 'Status' },
        ];
    }

    return [];
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
    statusSummary.value = data.status_summary || [];
    municipalityBreakdown.value = data.municipality_breakdown || [];
    projectBreakdown.value = data.project_breakdown || [];
    trend.value = data.trend || [];
    reviewBacklog.value = data.review_backlog || [];
    fundingOverview.value = data.funding_overview || null;
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

const normalizeDetailPagination = (payload) => {
    detailRows.value = payload.data || [];
    reportPagination.current_page = Number(payload.current_page || 1);
    reportPagination.last_page = Number(payload.last_page || 1);
    reportPagination.per_page = Number(payload.per_page || reportPagination.per_page || 15);
    reportPagination.total = Number(payload.total || detailRows.value.length || 0);
};

const ensureReportTypeAllowed = () => {
    const allowed = availableReportTypes.value.map((option) => option.value);

    if (!allowed.includes(reportType.value)) {
        reportType.value = allowed[0] || 'submissions';
    }
};

const detailParamsForReportType = (page = 1) => {
    const base = {
        page,
        per_page: reportPagination.per_page,
        date_from: filters.date_from || undefined,
        date_to: filters.date_to || undefined,
    };

    if (reportType.value === 'submissions') {
        return {
            endpoint: '/submissions',
            params: {
                ...base,
                search: detailFilters.search || undefined,
                status: filters.status || detailFilters.status || undefined,
                municipality_id: filters.municipality_id || undefined,
                project_id: filters.project_id || undefined,
                sort_by: detailFilters.sort_by || 'created_at',
                sort_dir: detailFilters.sort_dir || 'desc',
            },
        };
    }

    if (reportType.value === 'audit_logs') {
        return {
            endpoint: '/audit-logs',
            params: {
                ...base,
                action: detailFilters.action || detailFilters.search || undefined,
                user_id: detailFilters.user_id || undefined,
                role: detailFilters.role || undefined,
                status: detailFilters.status || undefined,
                municipality_id: filters.municipality_id || undefined,
                project_id: filters.project_id || undefined,
            },
        };
    }

    return {
        endpoint: '/users',
        params: {
            page,
            per_page: reportPagination.per_page,
            search: detailFilters.search || undefined,
            role: detailFilters.role || undefined,
            status: detailFilters.status || undefined,
            municipality_id: filters.municipality_id || undefined,
            sort_by: detailFilters.sort_by || 'created_at',
            sort_dir: detailFilters.sort_dir || 'desc',
        },
    };
};

const loadDetailedReport = async (page = 1) => {
    detailLoading.value = true;
    detailError.value = '';

    try {
        ensureReportTypeAllowed();
        const request = detailParamsForReportType(page);
        const { data } = await api.get(request.endpoint, { params: request.params });
        normalizeDetailPagination(data);
    } catch (err) {
        detailError.value = err.response?.data?.message || 'Unable to load detailed report data.';
        detailRows.value = [];
        reportPagination.current_page = 1;
        reportPagination.last_page = 1;
        reportPagination.total = 0;
    } finally {
        detailLoading.value = false;
    }
};

const loadFundingRequests = async () => {
    if (!canReviewFundingRequests.value) {
        fundingRequests.value = [];
        return;
    }

    fundingLoading.value = true;
    fundingError.value = '';

    try {
        const { data } = await api.get('/funding-requests', {
            params: {
                status: fundingStatusFilter.value || undefined,
                municipality_id: filters.municipality_id || undefined,
                project_id: filters.project_id || undefined,
                per_page: 30,
                sort_by: fundingStatusFilter.value === 'pending' ? 'created_at' : 'reviewed_at',
                sort_dir: 'desc',
            },
        });

        fundingRequests.value = data.data || [];
    } catch (err) {
        fundingRequests.value = [];
        fundingError.value = err.response?.data?.message || 'Unable to load funding requests.';
    } finally {
        fundingLoading.value = false;
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
        await loadDetailedReport(1);
        await loadFundingRequests();
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
    detailFilters.status = '';

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

const applyDetailedFilters = async () => {
    await loadDetailedReport(1);
};

const resetDetailedFilters = async () => {
    Object.assign(detailFilters, {
        search: '',
        action: '',
        role: '',
        user_id: '',
        status: '',
        sort_by: '',
        sort_dir: 'desc',
    });

    await loadDetailedReport(1);
};

const goToDetailPage = async (page) => {
    if (page < 1 || page > reportPagination.last_page || page === reportPagination.current_page) {
        return;
    }

    await loadDetailedReport(page);
};

const exportPayloadForReportType = () => {
    const base = {
        format: 'csv',
        type: reportType.value,
        date_from: filters.date_from || null,
        date_to: filters.date_to || null,
        municipality_id: filters.municipality_id || null,
        project_id: filters.project_id || null,
    };

    if (reportType.value === 'submissions') {
        return {
            ...base,
            status: filters.status || detailFilters.status || null,
            search: detailFilters.search || null,
        };
    }

    if (reportType.value === 'audit_logs') {
        return {
            ...base,
            action: detailFilters.action || detailFilters.search || null,
            role: detailFilters.role || null,
            status: detailFilters.status || null,
        };
    }

    return {
        ...base,
        search: detailFilters.search || null,
        role: detailFilters.role || null,
        status: detailFilters.status || null,
        sort_by: detailFilters.sort_by || null,
        sort_dir: detailFilters.sort_dir || null,
    };
};

const startCsvExport = async () => {
    if (!canExportCsv.value) {
        return;
    }

    await asyncExport.startExport({
        ...exportPayloadForReportType(),
    });
};

const startSummaryPdfExport = async () => {
    if (!canExportPdf.value) {
        return;
    }

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

const rowStatusLabel = (value) => String(value || '-').replaceAll('_', ' ');

const formatDateTime = (value) => {
    if (!value) {
        return '-';
    }

    return new Date(value).toLocaleString();
};

const reviewFundingRequest = async (row, decision) => {
    if (!row?.id || !canReviewFundingRequests.value) {
        return;
    }

    const reviewComment = String(fundingReviewNotes[row.id] || '').trim();
    if (!reviewComment) {
        fundingError.value = 'Review reason is required to approve or decline.';
        return;
    }

    fundingError.value = '';

    try {
        if (decision === 'approve') {
            await api.post(`/funding-requests/${row.id}/approve`, {
                review_comment: reviewComment,
            });
        } else {
            await api.post(`/funding-requests/${row.id}/decline`, {
                review_comment: reviewComment,
            });
        }

        fundingReviewNotes[row.id] = '';
        await Promise.all([
            loadFundingRequests(),
            loadKpis(),
        ]);
    } catch (err) {
        fundingError.value = err.response?.data?.message || 'Unable to review funding request.';
    }
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

watch(reportType, async () => {
    Object.assign(detailFilters, {
        action: '',
        role: '',
        user_id: '',
        status: reportType.value === 'submissions' ? filters.status : '',
        sort_by: '',
        sort_dir: 'desc',
    });

    await loadDetailedReport(1);
});

watch(availableReportTypes, () => {
    ensureReportTypeAllowed();
});

onMounted(async () => {
    ensureReportTypeAllowed();
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
                <select v-model="reportType">
                    <option v-for="option in availableReportTypes" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
                <button class="btn btn--primary" @click="applyFilters">Apply</button>
                <button class="btn btn--ghost" @click="resetFilters">Reset Filters</button>
                <button
                    v-if="canExportCsv"
                    class="btn btn--ghost"
                    :disabled="asyncExport.loading.value"
                    @click="startCsvExport"
                >
                    Export CSV
                </button>
                <button
                    v-if="canExportPdf && reportType === 'submissions'"
                    class="btn btn--ghost"
                    :disabled="asyncExport.loading.value"
                    @click="startSummaryPdfExport"
                >
                    Export PDF
                </button>
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
                                    <span>{{ slice.status.replaceAll('_', ' ') }}</span>
                                    <strong>{{ slice.count }}</strong>
                                </button>
                            </li>
                        </ul>
                    </div>
                    <ul class="stat-list stat-list--dense" v-if="statusSummary.length">
                        <li v-for="row in statusSummary" :key="`status-summary-${row.status}`">
                            <span>{{ row.label }}</span>
                            <strong>{{ row.count }} / {{ row.percentage }}%</strong>
                        </li>
                    </ul>
                </div>

                <div class="detail-block">
                    <h3>Municipality Breakdown</h3>
                    <ul class="bar-list">
                        <li v-for="row in municipalityBreakdown" :key="row.municipality_id">
                            <div>
                                <span>{{ row.municipality_name }}</span>
                                <small>{{ row.approval_rate_percent || 0 }}% approved</small>
                            </div>
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
                    <ul class="bar-list">
                        <li v-for="row in projectBreakdown" :key="row.project_id">
                            <div>
                                <span>{{ row.project_name }}</span>
                                <small>{{ row.municipality_name || '-' }} | {{ row.approval_rate_percent || 0 }}% approved</small>
                            </div>
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
                            <span>Approved {{ item.approved || 0 }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="split-grid">
                <div class="detail-block">
                    <h3>Review Backlog Aging</h3>
                    <ul class="bar-list">
                        <li v-for="bucket in reviewBacklog" :key="bucket.key">
                            <span>{{ bucket.label }}</span>
                            <div class="bar-list__track">
                                <div
                                    class="bar-list__fill"
                                    :style="{ width: `${(Number(bucket.count || 0) / reviewBacklogMax) * 100}%` }"
                                />
                            </div>
                            <strong>{{ bucket.count }}</strong>
                        </li>
                    </ul>
                </div>

                <div class="detail-block" v-if="fundingOverview">
                    <h3>Funding Request Overview</h3>
                    <div class="status-chart-wrap">
                        <svg viewBox="0 0 42 42" class="status-donut" role="img" aria-label="Funding status breakdown chart">
                            <circle cx="21" cy="21" r="15.9155" fill="transparent" stroke="#e2e8f0" stroke-width="6" />
                            <circle
                                v-for="slice in fundingSlices"
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
                            />
                        </svg>
                        <ul class="status-legend">
                            <li v-for="slice in fundingSlices" :key="`funding-${slice.status}`">
                                <div class="status-legend__btn status-legend__btn--static">
                                    <span class="status-legend__dot" :style="{ backgroundColor: slice.color }" />
                                    <span>{{ slice.status.replaceAll('_', ' ') }}</span>
                                    <strong>{{ slice.count }}</strong>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <ul class="stat-list stat-list--dense">
                        <li>
                            <span>Total Requested</span>
                            <strong>{{ Number(fundingOverview.total_requested_amount || 0).toLocaleString() }}</strong>
                        </li>
                        <li>
                            <span>Pending Amount</span>
                            <strong>{{ Number(fundingOverview.pending_requested_amount || 0).toLocaleString() }}</strong>
                        </li>
                        <li>
                            <span>Approved Amount</span>
                            <strong>{{ Number(fundingOverview.approved_requested_amount || 0).toLocaleString() }}</strong>
                        </li>
                        <li>
                            <span>Approval Rate</span>
                            <strong>{{ fundingOverview.approval_rate_percent || 0 }}%</strong>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="detail-block">
                <div class="map-shell__head">
                    <h3>{{ reportTypeLabel }}</h3>
                    <p class="panel__hint">All filters and exports respect current role permissions and scoped data.</p>
                </div>

                <div class="toolbar">
                    <input v-model="detailFilters.search" placeholder="Search records">
                    <input
                        v-if="reportType === 'audit_logs'"
                        v-model="detailFilters.action"
                        placeholder="Action contains"
                    >
                    <input
                        v-if="reportType === 'audit_logs'"
                        v-model="detailFilters.user_id"
                        type="number"
                        min="1"
                        placeholder="Actor user ID"
                    >
                    <select v-if="reportType !== 'audit_logs'" v-model="detailFilters.status">
                        <option value="">All statuses</option>
                        <option v-if="reportType === 'submissions'" value="under_review">Under Review</option>
                        <option v-if="reportType === 'submissions'" value="approved">Approved</option>
                        <option v-if="reportType === 'submissions'" value="rework_requested">Rework Requested</option>
                        <option v-if="reportType === 'submissions'" value="rejected">Rejected</option>
                        <option v-if="reportType === 'users'" value="active">Active</option>
                        <option v-if="reportType === 'users'" value="disabled">Disabled</option>
                    </select>
                    <select v-if="reportType !== 'submissions'" v-model="detailFilters.role">
                        <option value="">All roles</option>
                        <option value="reporter">Reporter</option>
                        <option value="municipal_focal_point">Municipal Focal Point</option>
                        <option value="undp_admin">UNDP Admin</option>
                        <option value="partner_donor_viewer">Partner / Donor Viewer</option>
                        <option value="auditor">Auditor</option>
                    </select>
                    <select v-if="detailSortOptions.length" v-model="detailFilters.sort_by">
                        <option value="">Default sort</option>
                        <option v-for="option in detailSortOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                    <select v-if="detailSortOptions.length" v-model="detailFilters.sort_dir">
                        <option value="desc">Descending</option>
                        <option value="asc">Ascending</option>
                    </select>
                    <button class="btn btn--primary" @click="applyDetailedFilters">Apply Table Filters</button>
                    <button class="btn btn--ghost" @click="resetDetailedFilters">Reset Table Filters</button>
                </div>

                <div class="chips-row" v-if="detailFilterChips.length">
                    <span class="filter-chip" v-for="chip in detailFilterChips" :key="chip">{{ chip }}</span>
                </div>

                <p class="field-error" v-if="detailError">{{ detailError }}</p>

                <div class="table-wrap">
                    <table class="table" v-if="!detailLoading && detailRows.length">
                        <thead v-if="reportType === 'submissions'">
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Reporter</th>
                                <th>Project</th>
                                <th>Municipality</th>
                                <th>Submitted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <thead v-else-if="reportType === 'audit_logs'">
                            <tr>
                                <th>ID</th>
                                <th>Timestamp</th>
                                <th>Action</th>
                                <th>Actor</th>
                                <th>Role</th>
                                <th>Entity</th>
                            </tr>
                        </thead>
                        <thead v-else>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email / Phone</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Municipality</th>
                                <th>Last Login</th>
                            </tr>
                        </thead>
                        <tbody v-if="reportType === 'submissions'">
                            <tr v-for="row in detailRows" :key="`submission-${row.id}`">
                                <td>#{{ row.id }}</td>
                                <td>{{ row.title || '-' }}</td>
                                <td>
                                    <span class="status-pill">{{ rowStatusLabel(row.status) }}</span>
                                </td>
                                <td>{{ row.reporter?.name || '-' }}</td>
                                <td>{{ row.project?.name || '-' }}</td>
                                <td>{{ row.municipality?.name || '-' }}</td>
                                <td>{{ formatDateTime(row.submitted_at || row.created_at) }}</td>
                                <td>
                                    <a :href="`/submissions/${row.id}`" target="_blank" rel="noopener">View</a>
                                </td>
                            </tr>
                        </tbody>
                        <tbody v-else-if="reportType === 'audit_logs'">
                            <tr v-for="row in detailRows" :key="`audit-${row.id}`">
                                <td>#{{ row.id }}</td>
                                <td>{{ formatDateTime(row.timestamp) }}</td>
                                <td>{{ row.action || '-' }}</td>
                                <td>{{ row.actor?.name || '-' }}</td>
                                <td>{{ rowStatusLabel(row.actor?.role) }}</td>
                                <td>{{ row.entity_type || '-' }} #{{ row.entity_id || '-' }}</td>
                            </tr>
                        </tbody>
                        <tbody v-else>
                            <tr v-for="row in detailRows" :key="`user-${row.id}`">
                                <td>#{{ row.id }}</td>
                                <td>{{ row.name || '-' }}</td>
                                <td>{{ row.email || row.phone_e164 || '-' }}</td>
                                <td>{{ rowStatusLabel(row.role) }}</td>
                                <td>
                                    <span class="status-pill" :class="row.status === 'active' ? 'status-pill--active' : 'status-pill--disabled'">
                                        {{ rowStatusLabel(row.status) }}
                                    </span>
                                </td>
                                <td>{{ row.municipality?.name || '-' }}</td>
                                <td>{{ formatDateTime(row.last_login_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="panel__hint" v-else-if="detailLoading">Loading report records...</p>
                    <p class="panel__hint" v-else>No records found for current filters.</p>
                </div>

                <div class="pagination-bar" v-if="!detailLoading && reportPagination.last_page > 1">
                    <button
                        class="btn btn--ghost"
                        type="button"
                        :disabled="reportPagination.current_page <= 1"
                        @click="goToDetailPage(reportPagination.current_page - 1)"
                    >
                        Previous
                    </button>
                    <button
                        class="btn btn--ghost"
                        type="button"
                        :disabled="reportPagination.current_page >= reportPagination.last_page"
                        @click="goToDetailPage(reportPagination.current_page + 1)"
                    >
                        Next
                    </button>
                    <span class="pagination-meta">
                        Page {{ reportPagination.current_page }} of {{ reportPagination.last_page }}
                        ({{ reportPagination.total }} records)
                    </span>
                </div>
            </div>

            <div class="detail-block" v-if="canReviewFundingRequests">
                <div class="map-shell__head">
                    <h3>Funding Requests Review (Admin)</h3>
                    <p class="panel__hint">Review donor funding requests and approve or decline with a required reason.</p>
                </div>

                <p class="field-error" v-if="fundingError">{{ fundingError }}</p>

                <div class="toolbar">
                    <select v-model="fundingStatusFilter" @change="loadFundingRequests">
                        <option value="pending">Pending Review</option>
                        <option value="approved">Approved</option>
                        <option value="declined">Declined</option>
                    </select>
                    <button class="btn btn--ghost" type="button" @click="loadFundingRequests">Refresh Requests</button>
                </div>

                <div class="table-wrap">
                    <table class="table" v-if="!fundingLoading && fundingRequests.length">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Project</th>
                                <th>Municipality</th>
                                <th>Donor</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Request Reason</th>
                                <th>Review Reason</th>
                                <th>Requested</th>
                                <th>Reviewed</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in fundingRequests" :key="`funding-${row.id}`">
                                <td>#{{ row.id }}</td>
                                <td>{{ row.project?.name || '-' }}</td>
                                <td>{{ row.project?.municipality?.name || '-' }}</td>
                                <td>{{ row.donor?.name || '-' }}</td>
                                <td>{{ row.currency }} {{ Number(row.amount || 0).toLocaleString() }}</td>
                                <td>
                                    <span class="status-pill" :class="row.status === 'approved' ? 'status-pill--active' : row.status === 'declined' ? 'status-pill--disabled' : ''">
                                        {{ row.status_label }}
                                    </span>
                                </td>
                                <td>{{ row.reason || '-' }}</td>
                                <td>{{ row.review_comment || '-' }}</td>
                                <td>{{ formatDateTime(row.created_at) }}</td>
                                <td>{{ formatDateTime(row.reviewed_at) }}</td>
                                <td>
                                    <div class="tracky-funding-review-cell" v-if="row.status === 'pending'">
                                        <textarea
                                            v-model="fundingReviewNotes[row.id]"
                                            rows="2"
                                            placeholder="Review reason (required)"
                                        />
                                        <div class="inline-group">
                                            <button class="btn btn--primary" type="button" @click="reviewFundingRequest(row, 'approve')">Approve</button>
                                            <button class="btn btn--danger" type="button" @click="reviewFundingRequest(row, 'decline')">Decline</button>
                                        </div>
                                    </div>
                                    <span v-else>Reviewed</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="panel__hint" v-else-if="fundingLoading">Loading funding requests...</p>
                    <p class="panel__hint" v-else>No pending funding requests for the current scope.</p>
                </div>
            </div>

            <div class="detail-block map-shell" ref="mapShellRef">
                <div class="map-shell__head">
                    <div>
                        <h3>Interactive Map</h3>
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
