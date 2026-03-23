<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import L from 'leaflet';
import { useI18n } from 'vue-i18n';
import AppShell from '../components/AppShell.vue';
import KpiCards from '../components/KpiCards.vue';
import api from '../api';
import { useAsyncExport } from '../composables/useAsyncExport';
import { useAuthStore } from '../stores/auth';

const FILTER_STORAGE_KEY = 'undp_reports_filters_v1';
const { t } = useI18n();
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

const reportTypeLabels = {
    submissions: 'reportsPage.submissionsReport',
    audit_logs: 'reportsPage.auditLogReport',
    users: 'reportsPage.usersReport',
};

const statusLabel = (value) => {
    const key = String(value || '').trim();
    return key ? t(`statusLabels.${key}`, key.replaceAll('_', ' ')) : '-';
};

const roleLabel = (value) => {
    const key = String(value || '').trim();
    return key ? t(`roles.${key}`, key.replaceAll('_', ' ')) : '-';
};

const reportTypeLabelText = (value) => t(reportTypeLabels[value] || reportTypeLabels.submissions);

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
        chips.push(`${t('common.status')}: ${statusLabel(filters.status)}`);
    }
    if (filters.date_from) {
        chips.push(`${t('common.dateFrom')}: ${filters.date_from}`);
    }
    if (filters.date_to) {
        chips.push(`${t('common.dateTo')}: ${filters.date_to}`);
    }
    if (filters.municipality_id) {
        const municipality = municipalities.value.find((item) => Number(item.id) === Number(filters.municipality_id));
        chips.push(`${t('common.municipality')}: ${municipality?.name || filters.municipality_id}`);
    }
    if (filters.project_id) {
        const project = projects.value.find((item) => Number(item.id) === Number(filters.project_id));
        chips.push(`${t('common.project')}: ${project?.name || filters.project_id}`);
    }

    return chips;
});

const detailFilterChips = computed(() => {
    const chips = [`${t('projectSubmissions.reportType')}: ${reportTypeLabel.value}`];

    if (detailFilters.search) {
        chips.push(`${t('common.search')}: ${detailFilters.search}`);
    }

    if (reportType.value === 'audit_logs' && detailFilters.action) {
        chips.push(`${t('audit.action')}: ${detailFilters.action}`);
    }

    if (reportType.value === 'users' && detailFilters.role) {
        chips.push(`${t('common.role')}: ${roleLabel(detailFilters.role)}`);
    }

    if (detailFilters.status) {
        chips.push(`${t('common.status')}: ${statusLabel(detailFilters.status)}`);
    }

    if (detailFilters.user_id) {
        chips.push(`${t('audit.userId')}: ${detailFilters.user_id}`);
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
        { value: 'submissions', label: reportTypeLabelText('submissions') },
    ];

    if (canViewAudit.value) {
        options.push({ value: 'audit_logs', label: reportTypeLabelText('audit_logs') });
    }

    if (canViewUsers.value) {
        options.push({ value: 'users', label: reportTypeLabelText('users') });
    }

    return options;
});

const reportTypeLabel = computed(() => {
    return availableReportTypes.value.find((option) => option.value === reportType.value)?.label || reportTypeLabelText('submissions');
});

const detailSortOptions = computed(() => {
    if (reportType.value === 'users') {
        return [
            { value: 'created_at', label: t('reportsPage.createdAt') },
            { value: 'last_login_at', label: t('reportsPage.lastLogin') },
            { value: 'name', label: t('common.name') },
            { value: 'role', label: t('common.role') },
            { value: 'status', label: t('common.status') },
        ];
    }

    if (reportType.value === 'submissions') {
        return [
            { value: 'created_at', label: t('reportsPage.createdAt') },
            { value: 'submitted_at', label: t('reportsPage.submittedAt') },
            { value: 'updated_at', label: t('reportsPage.updatedAt') },
            { value: 'status', label: t('common.status') },
        ];
    }

    return [];
});

const exportStatusLabel = computed(() => {
    if (!asyncExport.task.value) {
        return '';
    }

    const task = asyncExport.task.value;
    return t('reportsPage.exportStatus', { status: task.status, progress: task.progress });
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
                <strong>${t('reportsPage.cluster')}</strong><br/>
                ${t('reportsPage.total')}: ${cluster.count}<br/>
                ${t('reportsPage.projects')}: ${cluster.projects}<br/>
                ${t('reportsPage.submissions')}: ${cluster.submissions}
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
            ? `<a href="/submissions/${item.id}" target="_blank" rel="noopener">${t('reportsPage.viewDetails')}</a>`
            : '-';

        marker.bindPopup(`
            <strong>${item.name}</strong><br/>
            ${t('reportsPage.type')}: ${item.type === 'project' ? t('common.project') : t('reportsPage.submissions')}<br/>
            ${t('common.status')}: ${statusLabel(item.status)}<br/>
            ${t('common.municipality')}: ${item.municipality || '-'}<br/>
            ${item.overlap_count > 1 ? `${t('reportsPage.overlapGroup')}: ${item.overlap_index}/${item.overlap_count}<br/>` : ''}
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
        error.value = err.response?.data?.message || t('reportsPage.unableToLoadMapData');
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
        detailError.value = err.response?.data?.message || t('reportsPage.unableToLoadDetailedReport');
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
        fundingError.value = err.response?.data?.message || t('reportsPage.unableToLoadFundingRequests');
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
        error.value = err.response?.data?.message || t('reportsPage.unableToLoadReportData');
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

const rowStatusLabel = (value) => {
    const key = String(value || '').trim();
    return key ? t(`statusLabels.${key}`, t(`roles.${key}`, key.replaceAll('_', ' '))) : '-';
};

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
        fundingError.value = t('reportsPage.reviewReasonRequired');
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
        fundingError.value = err.response?.data?.message || t('reportsPage.unableToReviewFundingRequest');
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
                <h2>{{ t('reportsPage.title') }}</h2>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <div class="toolbar">
                <input v-model="filters.date_from" type="date">
                <input v-model="filters.date_to" type="date">
                <select v-model="filters.municipality_id" @change="onMunicipalityChange">
                    <option value="">{{ t('reportsPage.allMunicipalities') }}</option>
                    <option v-for="municipality in municipalities" :key="municipality.id" :value="municipality.id">
                        {{ municipality.name }}
                    </option>
                </select>
                <select v-model="filters.project_id" @focus="loadProjectOptions()">
                    <option value="">{{ t('reportsPage.allProjects') }}</option>
                    <option v-for="project in projects" :key="project.id" :value="project.id">
                        {{ project.name }}
                    </option>
                </select>
                <select v-model="filters.status">
                    <option value="">{{ t('reportsPage.allStatuses') }}</option>
                    <option value="under_review">{{ t('statusLabels.under_review') }}</option>
                    <option value="approved">{{ t('statusLabels.approved') }}</option>
                    <option value="rework_requested">{{ t('statusLabels.rework_requested') }}</option>
                    <option value="rejected">{{ t('statusLabels.rejected') }}</option>
                </select>
                <select v-model="reportType">
                    <option v-for="option in availableReportTypes" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
                <button class="btn btn--primary" @click="applyFilters">{{ t('common.apply') }}</button>
                <button class="btn btn--ghost" @click="resetFilters">{{ t('reportsPage.resetFilters') }}</button>
                <button
                    v-if="canExportCsv"
                    class="btn btn--ghost"
                    :disabled="asyncExport.loading.value"
                    @click="startCsvExport"
                >
                    {{ t('common.exportCsv') }}
                </button>
                <button
                    v-if="canExportPdf && reportType === 'submissions'"
                    class="btn btn--ghost"
                    :disabled="asyncExport.loading.value"
                    @click="startSummaryPdfExport"
                >
                    {{ t('common.exportPdf') }}
                </button>
                <button
                    v-if="asyncExport.task.value?.status === 'ready'"
                    class="btn btn--primary"
                    @click="asyncExport.download"
                >
                    {{ t('common.download') }}
                </button>
            </div>
            <p class="panel__hint" v-if="exportStatusLabel">{{ exportStatusLabel }}</p>

            <div class="chips-row" v-if="activeFilterChips.length">
                <span class="filter-chip" v-for="chip in activeFilterChips" :key="chip">{{ chip }}</span>
            </div>

            <KpiCards :kpis="kpis" />

            <div class="split-grid">
                <div class="detail-block">
                    <h3>{{ t('reportsPage.statusAnalytics') }}</h3>
                    <div class="status-chart-wrap">
                        <svg viewBox="0 0 42 42" class="status-donut" role="img" :aria-label="t('reportsPage.statusBreakdownChart')">
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
                                    <span>{{ statusLabel(slice.status) }}</span>
                                    <strong>{{ slice.count }}</strong>
                                </button>
                            </li>
                        </ul>
                    </div>
                    <ul class="stat-list stat-list--dense" v-if="statusSummary.length">
                        <li v-for="row in statusSummary" :key="`status-summary-${row.status}`">
                            <span>{{ statusLabel(row.status || row.label) }}</span>
                            <strong>{{ row.count }} / {{ row.percentage }}%</strong>
                        </li>
                    </ul>
                </div>

                <div class="detail-block">
                    <h3>{{ t('reportsPage.municipalityBreakdown') }}</h3>
                    <ul class="bar-list">
                        <li v-for="row in municipalityBreakdown" :key="row.municipality_id">
                            <div>
                                <span>{{ row.municipality_name }}</span>
                                <small>{{ row.approval_rate_percent || 0 }}% {{ t('statusLabels.approved') }}</small>
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
                    <h3>{{ t('reportsPage.projectBreakdown') }}</h3>
                    <ul class="bar-list">
                        <li v-for="row in projectBreakdown" :key="row.project_id">
                            <div>
                                <span>{{ row.project_name }}</span>
                                <small>{{ row.municipality_name || '-' }} | {{ row.approval_rate_percent || 0 }}% {{ t('statusLabels.approved') }}</small>
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
                    <h3>{{ t('reportsPage.trend') }}</h3>
                    <div class="trend-chart" v-if="trendPath">
                        <svg viewBox="0 0 360 150" role="img" :aria-label="t('reportsPage.submissionTrendChart')">
                            <path v-if="trendAreaPath" :d="trendAreaPath" class="trend-area" />
                            <polyline :points="trendPath" class="trend-line" />
                        </svg>
                    </div>
                    <p class="panel__hint" v-if="!trendPath && !loading">{{ t('reportsPage.noTrend') }}</p>
                    <ul class="trend-labels" v-if="trend.length">
                        <li v-for="item in trend" :key="item.day">
                            <small>{{ item.day }}</small>
                            <strong>{{ item.count }}</strong>
                            <span>{{ t('reportsPage.approvedShort', { count: item.approved || 0 }) }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="split-grid">
                <div class="detail-block">
                    <h3>{{ t('reportsPage.reviewBacklogAging') }}</h3>
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
                    <h3>{{ t('reportsPage.fundingOverview') }}</h3>
                    <div class="status-chart-wrap">
                        <svg viewBox="0 0 42 42" class="status-donut" role="img" :aria-label="t('reportsPage.fundingStatusBreakdownChart')">
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
                                    <span>{{ statusLabel(slice.status) }}</span>
                                    <strong>{{ slice.count }}</strong>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <ul class="stat-list stat-list--dense">
                        <li>
                            <span>{{ t('reportsPage.totalRequested') }}</span>
                            <strong>{{ fundingOverview.total_requested_amount_label || Number(fundingOverview.total_requested_amount || 0).toLocaleString() }}</strong>
                        </li>
                        <li>
                            <span>{{ t('reportsPage.pendingAmount') }}</span>
                            <strong>{{ fundingOverview.pending_requested_amount_label || Number(fundingOverview.pending_requested_amount || 0).toLocaleString() }}</strong>
                        </li>
                        <li>
                            <span>{{ t('reportsPage.approvedAmount') }}</span>
                            <strong>{{ fundingOverview.approved_requested_amount_label || Number(fundingOverview.approved_requested_amount || 0).toLocaleString() }}</strong>
                        </li>
                        <li>
                            <span>{{ t('reportsPage.approvalRate') }}</span>
                            <strong>{{ fundingOverview.approval_rate_percent || 0 }}%</strong>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="detail-block">
                <div class="map-shell__head">
                    <h3>{{ reportTypeLabel }}</h3>
                    <p class="panel__hint">{{ t('reportsPage.allFiltersHint') }}</p>
                </div>

                <div class="toolbar">
                    <input v-model="detailFilters.search" :placeholder="t('reportsPage.searchRecords')">
                    <input
                        v-if="reportType === 'audit_logs'"
                        v-model="detailFilters.action"
                        :placeholder="t('reportsPage.actionContains')"
                    >
                    <input
                        v-if="reportType === 'audit_logs'"
                        v-model="detailFilters.user_id"
                        type="number"
                        min="1"
                        :placeholder="t('reportsPage.actorUserId')"
                    >
                    <select v-if="reportType !== 'audit_logs'" v-model="detailFilters.status">
                        <option value="">{{ t('reportsPage.allStatuses') }}</option>
                        <option v-if="reportType === 'submissions'" value="under_review">{{ t('statusLabels.under_review') }}</option>
                        <option v-if="reportType === 'submissions'" value="approved">{{ t('statusLabels.approved') }}</option>
                        <option v-if="reportType === 'submissions'" value="rework_requested">{{ t('statusLabels.rework_requested') }}</option>
                        <option v-if="reportType === 'submissions'" value="rejected">{{ t('statusLabels.rejected') }}</option>
                        <option v-if="reportType === 'users'" value="active">{{ t('common.active') }}</option>
                        <option v-if="reportType === 'users'" value="disabled">{{ t('common.disabled') }}</option>
                    </select>
                    <select v-if="reportType !== 'submissions'" v-model="detailFilters.role">
                        <option value="">{{ t('reportsPage.allRoles') }}</option>
                        <option value="reporter">{{ t('roles.reporter') }}</option>
                        <option value="municipal_focal_point">{{ t('roles.municipal_focal_point') }}</option>
                        <option value="undp_admin">{{ t('roles.undp_admin') }}</option>
                        <option value="partner_donor_viewer">{{ t('roles.partner_donor_viewer') }}</option>
                        <option value="auditor">{{ t('roles.auditor') }}</option>
                    </select>
                    <select v-if="detailSortOptions.length" v-model="detailFilters.sort_by">
                        <option value="">{{ t('reportsPage.defaultSort') }}</option>
                        <option v-for="option in detailSortOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                    <select v-if="detailSortOptions.length" v-model="detailFilters.sort_dir">
                        <option value="desc">{{ t('reportsPage.descending') }}</option>
                        <option value="asc">{{ t('reportsPage.ascending') }}</option>
                    </select>
                    <button class="btn btn--primary" @click="applyDetailedFilters">{{ t('reportsPage.applyTableFilters') }}</button>
                    <button class="btn btn--ghost" @click="resetDetailedFilters">{{ t('reportsPage.resetTableFilters') }}</button>
                </div>

                <div class="chips-row" v-if="detailFilterChips.length">
                    <span class="filter-chip" v-for="chip in detailFilterChips" :key="chip">{{ chip }}</span>
                </div>

                <p class="field-error" v-if="detailError">{{ detailError }}</p>

                <div class="table-wrap">
                    <table class="table" v-if="!detailLoading && detailRows.length">
                        <thead v-if="reportType === 'submissions'">
                            <tr>
                                <th>{{ t('common.id') }}</th>
                                <th>{{ t('common.title') }}</th>
                                <th>{{ t('common.status') }}</th>
                                <th>{{ t('validation.reporter') }}</th>
                                <th>{{ t('common.project') }}</th>
                                <th>{{ t('common.municipality') }}</th>
                                <th>{{ t('reportsPage.submittedAt') }}</th>
                                <th>{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <thead v-else-if="reportType === 'audit_logs'">
                            <tr>
                                <th>{{ t('common.id') }}</th>
                                <th>{{ t('audit.timestamp') }}</th>
                                <th>{{ t('audit.action') }}</th>
                                <th>{{ t('common.actor') }}</th>
                                <th>{{ t('common.role') }}</th>
                                <th>{{ t('reportsPage.entity') }}</th>
                            </tr>
                        </thead>
                        <thead v-else>
                            <tr>
                                <th>{{ t('common.id') }}</th>
                                <th>{{ t('common.name') }}</th>
                                <th>{{ t('reportsPage.emailPhone') }}</th>
                                <th>{{ t('common.role') }}</th>
                                <th>{{ t('common.status') }}</th>
                                <th>{{ t('common.municipality') }}</th>
                                <th>{{ t('reportsPage.lastLogin') }}</th>
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
                                    <a :href="`/submissions/${row.id}`" target="_blank" rel="noopener">{{ t('common.view') }}</a>
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
                    <p class="panel__hint" v-else-if="detailLoading">{{ t('reportsPage.reportRecordsLoading') }}</p>
                    <p class="panel__hint" v-else>{{ t('reportsPage.noRecords') }}</p>
                </div>

                <div class="pagination-bar" v-if="!detailLoading && reportPagination.last_page > 1">
                    <button
                        class="btn btn--ghost"
                        type="button"
                        :disabled="reportPagination.current_page <= 1"
                        @click="goToDetailPage(reportPagination.current_page - 1)"
                    >
                        {{ t('common.previous') }}
                    </button>
                    <button
                        class="btn btn--ghost"
                        type="button"
                        :disabled="reportPagination.current_page >= reportPagination.last_page"
                        @click="goToDetailPage(reportPagination.current_page + 1)"
                    >
                        {{ t('common.next') }}
                    </button>
                    <span class="pagination-meta">
                        {{ t('reportsPage.pageMeta', { page: reportPagination.current_page, total: reportPagination.last_page, records: reportPagination.total }) }}
                    </span>
                </div>
            </div>

            <div class="detail-block" v-if="canReviewFundingRequests">
                <div class="map-shell__head">
                    <h3>{{ t('reportsPage.fundingReviewTitle') }}</h3>
                    <p class="panel__hint">{{ t('reportsPage.fundingReviewHint') }}</p>
                </div>

                <p class="field-error" v-if="fundingError">{{ fundingError }}</p>

                <div class="toolbar">
                    <select v-model="fundingStatusFilter" @change="loadFundingRequests">
                        <option value="pending">{{ t('reportsPage.pendingReview') }}</option>
                        <option value="approved">{{ t('statusLabels.approved') }}</option>
                        <option value="declined">{{ t('statusLabels.declined') }}</option>
                    </select>
                    <button class="btn btn--ghost" type="button" @click="loadFundingRequests">{{ t('reportsPage.refreshRequests') }}</button>
                </div>

                <div class="table-wrap">
                    <table class="table" v-if="!fundingLoading && fundingRequests.length">
                        <thead>
                            <tr>
                                <th>{{ t('common.id') }}</th>
                                <th>{{ t('common.project') }}</th>
                                <th>{{ t('common.municipality') }}</th>
                                <th>{{ t('dashboard.donors') }}</th>
                                <th>{{ t('common.amount') }}</th>
                                <th>{{ t('common.status') }}</th>
                                <th>{{ t('reportsPage.requestReason') }}</th>
                                <th>{{ t('reportsPage.reviewReason') }}</th>
                                <th>{{ t('reportsPage.requested') }}</th>
                                <th>{{ t('reportsPage.reviewed') }}</th>
                                <th>{{ t('common.actions') }}</th>
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
                                        {{ statusLabel(row.status) }}
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
                                            :placeholder="t('reportsPage.reviewReasonRequired')"
                                        />
                                        <div class="inline-group">
                                            <button class="btn btn--primary" type="button" @click="reviewFundingRequest(row, 'approve')">{{ t('submissionDetail.approve') }}</button>
                                            <button class="btn btn--danger" type="button" @click="reviewFundingRequest(row, 'decline')">{{ t('submissionDetail.reject') }}</button>
                                        </div>
                                    </div>
                                    <span v-else>{{ t('common.reviewed') }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="panel__hint" v-else-if="fundingLoading">{{ t('reportsPage.loadingFundingRequests') }}</p>
                    <p class="panel__hint" v-else>{{ t('reportsPage.noFundingRequests') }}</p>
                </div>
            </div>

            <div class="detail-block map-shell" ref="mapShellRef">
                <div class="map-shell__head">
                    <div>
                        <h3>{{ t('reportsPage.interactiveMap') }}</h3>
                    </div>
                    <button class="btn btn--ghost" type="button" @click="toggleMapFullscreen">
                        {{ isMapFullscreen ? t('reportsPage.exitFullScreen') : t('reportsPage.fullScreenMap') }}
                    </button>
                </div>
                <div ref="mapRef" class="map-canvas"></div>
            </div>
        </section>
    </AppShell>
</template>
