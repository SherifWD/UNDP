<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import L from 'leaflet';
import AppShell from '../components/AppShell.vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';

const auth = useAuthStore();
const { t } = useI18n();
const router = useRouter();

const loading = ref(false);
const error = ref('');

const kpis = ref({});
const projects = ref([]);
const municipalities = ref([]);
const mapMarkers = ref([]);

const selectedProjectId = ref(null);
const mapRef = ref(null);
const showFilterPanel = ref(false);
const DEFAULT_MAP_CENTER = [26.3351, 17.2283];
const DEFAULT_MAP_ZOOM = 6;
const lastMapScopeKey = ref('');
let map;
let markerLayer;

const filters = reactive({
    municipality_id: '',
    search: '',
    status: '',
    priority: '',
    area: '',
});

const canOpenProjectSubmissions = computed(() => (
    auth.hasPermission('submissions.view.own')
    || auth.hasPermission('submissions.view.municipality')
    || auth.hasPermission('submissions.view.all')
    || auth.hasPermission('submissions.view.approved_aggregated')
));
const canCreateProject = computed(() => auth.hasPermission('projects.manage'));

const greetingName = computed(() => {
    const fullName = auth.user?.name || 'User';
    return String(fullName).split(' ')[0];
});

const totalReports = computed(() => Number(kpis.value.total_submissions || 0));
const approvedCount = computed(() => Number(kpis.value.approved || 0));
const underReviewCount = computed(() => Number(kpis.value.under_review || 0));
const rejectedCount = computed(() => Number(kpis.value.rejected || 0));
const reworkCount = computed(() => Number(kpis.value.rework_requested || 0));

const donutSegments = computed(() => {
    const parts = [
        { key: 'approved', value: approvedCount.value, color: '#24A148' },
        { key: 'under_review', value: underReviewCount.value, color: '#56C7E6' },
        { key: 'rejected', value: rejectedCount.value, color: '#F06A6A' },
        { key: 'rework_requested', value: reworkCount.value, color: '#F4C300' },
    ];

    const total = parts.reduce((sum, part) => sum + part.value, 0);
    if (total <= 0) {
        return 'conic-gradient(#d9dde6 0deg 360deg)';
    }

    let current = 0;
    const stops = parts.map((part) => {
        const start = current;
        const end = current + (part.value / total) * 360;
        current = end;
        return `${part.color} ${start.toFixed(1)}deg ${end.toFixed(1)}deg`;
    });

    return `conic-gradient(${stops.join(', ')})`;
});

const beneficiaryTotal = computed(() => Math.max(totalReports.value * 120, 0));

const fundingTarget = 14000000;
const fundingCurrent = computed(() => {
    if (totalReports.value <= 0) return 0;
    const ratio = Math.max(0.18, Math.min(0.88, approvedCount.value / totalReports.value || 0));
    return Math.round(fundingTarget * ratio);
});

const fundingPercent = computed(() => ((fundingCurrent.value / fundingTarget) * 100).toFixed(1));

const projectStatsById = computed(() => {
    const rows = {};

    projects.value.forEach((project) => {
        const projectId = Number(project?.id || 0);
        if (!projectId) {
            return;
        }

        const stats = project?.stats || {};
        const progressValue = Number(stats.progress_percent || 0);

        rows[projectId] = {
            total: Number(stats.total_submissions || 0),
            approved: Number(stats.approved_submissions || 0),
            pending: Number(stats.pending_submissions || 0),
            rejected: Number(stats.rejected_submissions || 0),
            progressValues: Number.isFinite(progressValue) && progressValue > 0 ? [progressValue] : [],
        };
    });

    return rows;
});

const getProjectStats = (projectId) => {
    return projectStatsById.value[Number(projectId)] || {
        total: 0,
        approved: 0,
        pending: 0,
        rejected: 0,
        progressValues: [],
    };
};

const resolveProjectProgress = (projectId) => {
    const stats = getProjectStats(projectId);

    if (stats.progressValues.length > 0) {
        const avg = stats.progressValues.reduce((sum, value) => sum + value, 0) / stats.progressValues.length;
        return Math.max(0, Math.min(100, Math.round(avg)));
    }

    if (stats.total === 0) {
        const project = projects.value.find((row) => Number(row.id) === Number(projectId));
        return project?.status === 'archived' ? 100 : 0;
    }

    return Math.round((stats.approved / stats.total) * 100);
};

const resolveProjectPriority = (projectId) => {
    const pending = getProjectStats(projectId).pending;

    if (pending >= 8) {
        return 'high';
    }

    if (pending >= 3) {
        return 'medium';
    }

    return 'low';
};

const projectPriorityClass = (projectId) => `badge--${resolveProjectPriority(projectId)}`;

const projectReference = (projectId) => `PRJ-${String(projectId).padStart(3, '0')}`;

const shortProjectDescription = (description) => {
    if (!description) {
        return t('dashboard.noShortDescription');
    }

    return description.length > 74 ? `${description.slice(0, 74)}...` : description;
};

const filteredProjects = computed(() => {
    const search = filters.search.trim().toLowerCase();
    const selectedMunicipality = Number(filters.municipality_id || 0);
    const selectedProjectIdFromFilter = Number(filters.area || 0);

    return projects.value
        .filter((project) => {
            if (selectedMunicipality && Number(project.municipality?.id || 0) !== selectedMunicipality) {
                return false;
            }

            if (selectedProjectIdFromFilter && Number(project.id) !== selectedProjectIdFromFilter) {
                return false;
            }

            if (search) {
                const haystack = `${project.name || ''} ${project.municipality?.name || ''} ${project.id}`.toLowerCase();
                if (!haystack.includes(search)) {
                    return false;
                }
            }

            if (filters.status && project.status !== filters.status) {
                return false;
            }

            if (filters.priority && resolveProjectPriority(project.id) !== filters.priority) {
                return false;
            }

            return true;
        })
        .sort((a, b) => {
            return new Date(b.last_update_at || 0).getTime() - new Date(a.last_update_at || 0).getTime();
        });
});

const selectedProject = computed(() => {
    if (!filteredProjects.value.length || !selectedProjectId.value) return null;

    const current = filteredProjects.value.find((item) => Number(item.id) === Number(selectedProjectId.value));
    return current || null;
});

const selectedProjectStats = computed(() => {
    if (!selectedProject.value) {
        return null;
    }

    return getProjectStats(selectedProject.value.id);
});

const addNewProject = () => {
    router.push({ name: 'projects' });
};

const openProjectSubmissions = (project) => {
    if (!project || !canOpenProjectSubmissions.value) {
        return;
    }

    router.push({
        name: 'project-submissions',
        params: {
            id: String(project.id),
        },
    });
};

const selectProject = (project) => {
    if (!project) {
        return;
    }

    selectedProjectId.value = project.id;
    focusMapOnProject(project);
};

const closeProjectDetails = () => {
    selectedProjectId.value = null;
};

const applyFilterPanel = async () => {
    showFilterPanel.value = false;
    await loadDashboard();
};

const resetFilterPanel = async () => {
    filters.priority = '';
    filters.area = '';
    filters.status = '';
    await loadDashboard();
};

const selectedMunicipalityParam = () => {
    return filters.municipality_id ? Number(filters.municipality_id) : undefined;
};

const loadMunicipalities = async () => {
    if (municipalities.value.length) {
        return;
    }

    try {
        const municipalityRes = await api.get('/municipalities');
        municipalities.value = municipalityRes.data.data || [];
    } catch (err) {
        if (!error.value) {
            error.value = err.response?.data?.message || 'Municipalities are unavailable.';
        }
    }
};

const initMap = async () => {
    await nextTick();
    if (!mapRef.value || map) return;

    map = L.map(mapRef.value, {
        zoomControl: true,
    }).setView(DEFAULT_MAP_CENTER, DEFAULT_MAP_ZOOM);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    markerLayer = L.layerGroup().addTo(map);

    setTimeout(() => map?.invalidateSize(), 80);
};

const renderMarkers = () => {
    if (!markerLayer) return;
    markerLayer.clearLayers();

    mapMarkers.value.forEach((markerData) => {
        const isSelectedProjectMarker = markerData.type === 'project' && Number(markerData.id) === Number(selectedProjectId.value);

        const marker = L.circleMarker([markerData.lat, markerData.lng], {
            radius: isSelectedProjectMarker ? 10 : markerData.type === 'project' ? 8 : 6,
            color: markerData.type === 'project' ? '#3B6DD8' : '#E34D43',
            fillColor: markerData.type === 'project' ? '#3B6DD8' : '#E34D43',
            fillOpacity: 0.95,
            weight: isSelectedProjectMarker ? 3 : 1,
        });

        marker.bindPopup(`
            <strong>${markerData.name}</strong><br/>
            ${t('common.status')}: ${markerData.status || '-'}<br/>
            ${t('dashboard.municipality')}: ${markerData.municipality || '-'}
        `);

        if (markerData.type === 'project') {
            marker.on('click', () => {
                const project = projects.value.find((row) => Number(row.id) === Number(markerData.id));
                if (project) {
                    selectedProjectId.value = project.id;
                }
            });
        }

        markerLayer.addLayer(marker);
    });
};

const focusMapOnData = () => {
    if (!map) {
        return;
    }

    const markerPoints = mapMarkers.value
        .filter((item) => Number.isFinite(Number(item.lat)) && Number.isFinite(Number(item.lng)))
        .map((item) => [Number(item.lat), Number(item.lng)]);

    const projectPoints = projects.value
        .filter((item) => Number.isFinite(Number(item.latitude)) && Number.isFinite(Number(item.longitude)))
        .map((item) => [Number(item.latitude), Number(item.longitude)]);

    const points = markerPoints.length ? markerPoints : projectPoints;

    if (!points.length) {
        map.setView(DEFAULT_MAP_CENTER, DEFAULT_MAP_ZOOM);
        return;
    }

    const bounds = L.latLngBounds(points);

    if (bounds.isValid()) {
        map.fitBounds(bounds, {
            padding: [24, 24],
            maxZoom: 13,
        });
    }
};

const focusMapOnProject = (project) => {
    if (!map || !project) {
        return;
    }

    const lat = Number(project.latitude);
    const lng = Number(project.longitude);

    if (Number.isFinite(lat) && Number.isFinite(lng)) {
        map.flyTo([lat, lng], 12, { duration: 0.4 });
    }
};

const loadDashboard = async () => {
    loading.value = true;
    error.value = '';

    try {
        const municipalityId = selectedMunicipalityParam();
        const mapParams = {
            municipality_id: municipalityId,
            project_status: filters.status || undefined,
            project_id: filters.area || undefined,
            cluster: false,
            include_submissions: false,
        };

        const results = await Promise.allSettled([
            api.get('/dashboard/kpis', { params: { municipality_id: municipalityId } }),
            api.get('/dashboard/map', { params: mapParams }),
            api.get('/projects', {
                params: {
                    municipality_id: municipalityId,
                    status: filters.status || undefined,
                    with_stats: true,
                },
            }),
        ]);

        const [kpiResult, mapResult, projectResult] = results;
        const partialErrors = [];

        if (kpiResult.status === 'fulfilled') {
            kpis.value = kpiResult.value.data.kpis || {};
        } else {
            partialErrors.push(kpiResult.reason?.response?.data?.message || 'KPI data is unavailable.');
        }

        if (mapResult.status === 'fulfilled') {
            mapMarkers.value = mapResult.value.data.markers || [];
        } else {
            mapMarkers.value = [];
            partialErrors.push(mapResult.reason?.response?.data?.message || 'Map data is unavailable.');
        }

        if (projectResult.status === 'fulfilled') {
            projects.value = projectResult.value.data.data || [];
        } else {
            projects.value = [];
            partialErrors.push(projectResult.reason?.response?.data?.message || 'Projects are unavailable.');
        }

        await initMap();

        if (selectedProjectId.value && !filteredProjects.value.some((row) => Number(row.id) === Number(selectedProjectId.value))) {
            selectedProjectId.value = null;
        }

        renderMarkers();

        const currentScopeKey = `${municipalityId || 'all'}:${filters.area || 'all'}:${filters.status || 'all'}`;

        if (currentScopeKey !== lastMapScopeKey.value) {
            focusMapOnData();
            lastMapScopeKey.value = currentScopeKey;
        }

        if (partialErrors.length > 0) {
            error.value = partialErrors[0];
        }
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load dashboard.';
    } finally {
        loading.value = false;
    }
};

watch(filteredProjects, (rows) => {
    if (!rows.length || !rows.some((row) => Number(row.id) === Number(selectedProjectId.value))) {
        selectedProjectId.value = null;
    }
});

watch(selectedProjectId, () => {
    renderMarkers();

    nextTick(() => {
        setTimeout(() => map?.invalidateSize(), 40);
        setTimeout(() => map?.invalidateSize(), 260);
    });
});

onMounted(async () => {
    await Promise.allSettled([
        loadMunicipalities(),
        loadDashboard(),
    ]);
});

onBeforeUnmount(() => {
    if (map) {
        map.off();
        map.remove();
        map = null;
    }

});
</script>

<template>
    <AppShell>
        <section class="tracky-home">
            <header class="tracky-home__top">
                <div>
                    <h2>{{ t('dashboard.greeting', { name: greetingName }) }}</h2>
                </div>
                <button v-if="canCreateProject" class="tracky-btn tracky-btn--primary" type="button" @click="addNewProject">
                    <span>+</span>
                    <span>{{ t('dashboard.addNewProject') }}</span>
                </button>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <section class="tracky-card tracky-reports">
                <div class="tracky-reports__block">
                    <div class="tracky-reports__head">
                        <h3>{{ t('dashboard.reports') }}</h3>
                        <select>
                            <option>{{ t('dashboard.allProjects') }}</option>
                        </select>
                    </div>

                    <div class="tracky-reports__content">
                        <div class="tracky-donut" :style="{ background: donutSegments }">
                            <div class="tracky-donut__center">{{ totalReports.toLocaleString() }}</div>
                        </div>

                        <ul class="tracky-metric-list">
                            <li><span class="dot dot--approved" />{{ t('dashboard.approved') }} <strong>{{ approvedCount }}</strong></li>
                            <li><span class="dot dot--review" />{{ t('dashboard.underReview') }} <strong>{{ underReviewCount }}</strong></li>
                            <li><span class="dot dot--rejected" />{{ t('dashboard.rejected') }} <strong>{{ rejectedCount }}</strong></li>
                            <li><span class="dot dot--rework" />{{ t('dashboard.reworkRequested') }} <strong>{{ reworkCount }}</strong></li>
                        </ul>
                    </div>
                </div>

                <div class="tracky-divider" />

                <div class="tracky-reports__block">
                    <div class="tracky-reports__head">
                        <h3>{{ t('dashboard.fundingProgress') }}</h3>
                        <select>
                            <option>{{ t('dashboard.allSources') }}</option>
                        </select>
                    </div>
                    <p class="tracky-figure">${{ Math.round(fundingCurrent / 1000000) }}M/${{ Math.round(fundingTarget / 1000000) }}M</p>
                    <div class="tracky-progress">
                        <div class="tracky-progress__bar" :style="{ width: `${fundingPercent}%` }" />
                    </div>
                    <p class="tracky-subtle">{{ t('dashboard.fundingProgressLabel', { percent: fundingPercent }) }}</p>
                </div>

                <div class="tracky-divider" />

                <div class="tracky-reports__block">
                    <div class="tracky-reports__head">
                        <h3>{{ t('dashboard.beneficiariesOverview') }}</h3>
                        <select>
                            <option>{{ t('dashboard.allTime') }}</option>
                        </select>
                    </div>
                    <p class="tracky-figure">{{ beneficiaryTotal.toLocaleString() }}</p>
                    <div class="tracky-beneficiary-bars">
                        <span style="width: 42%; background:#2B8AF0" />
                        <span style="width: 28%; background:#233AA8" />
                        <span style="width: 12%; background:#EA6A35" />
                        <span style="width: 18%; background:#7F1A8E" />
                    </div>
                    <div class="tracky-beneficiary-legend">
                        <span><i style="background:#2B8AF0" />{{ t('dashboard.boys') }}</span>
                        <span><i style="background:#233AA8" />{{ t('dashboard.female') }}</span>
                        <span><i style="background:#EA6A35" />{{ t('dashboard.girls') }}</span>
                        <span><i style="background:#7F1A8E" />{{ t('dashboard.male') }}</span>
                    </div>
                </div>
            </section>

            <section class="tracky-card tracky-map-layout" :class="{ 'tracky-map-layout--detail-open': selectedProject }">
                <div class="tracky-map-board" :class="{ 'tracky-map-board--detail-open': selectedProject }">
                    <div class="tracky-map-board__toolbar">
                        <div class="tracky-map-controls">
                            <label>{{ t('dashboard.municipality') }}</label>
                            <select v-model="filters.municipality_id" @change="loadDashboard">
                                <option value="">{{ t('dashboard.allMunicipalities') }}</option>
                                <option v-for="m in municipalities" :key="m.id" :value="m.id">{{ m.name }}</option>
                            </select>
                        </div>

                        <div class="tracky-map-toolbar-search">
                            <div class="tracky-side-toolbar">
                                <input v-model="filters.search" :placeholder="t('dashboard.searchProjects')" />
                                <button type="button" class="tracky-btn tracky-btn--ghost" @click="showFilterPanel = !showFilterPanel">{{ t('dashboard.filter') }}</button>

                                <div class="tracky-filter-panel" v-if="showFilterPanel">
                                    <h4>{{ t('dashboard.projectFilters') }}</h4>
                                    <select v-model="filters.priority">
                                        <option value="">{{ t('dashboard.selectPriority') }}</option>
                                        <option value="high">{{ t('dashboard.high') }}</option>
                                        <option value="medium">{{ t('dashboard.medium') }}</option>
                                        <option value="low">{{ t('dashboard.low') }}</option>
                                    </select>
                                    <select v-model="filters.area">
                                        <option value="">{{ t('dashboard.selectArea') }}</option>
                                        <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
                                    </select>
                                    <select v-model="filters.status">
                                        <option value="">{{ t('dashboard.allStatus') }}</option>
                                        <option value="active">{{ t('dashboard.active') }}</option>
                                        <option value="archived">{{ t('dashboard.archived') }}</option>
                                    </select>
                                    <div class="tracky-filter-actions">
                                        <button class="tracky-btn tracky-btn--primary" type="button" @click="applyFilterPanel">{{ t('dashboard.apply') }}</button>
                                        <button class="tracky-btn tracky-btn--ghost" type="button" @click="resetFilterPanel">{{ t('dashboard.reset') }}</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tracky-map-board__body">
                        <div ref="mapRef" class="tracky-map" />

                        <aside class="tracky-map-results">
                            <p class="tracky-subtle tracky-project-count">
                                {{ t('dashboard.projectsFound', { count: filteredProjects.length }) }}
                            </p>

                            <div class="tracky-project-list" v-if="filteredProjects.length">
                                <article
                                    class="tracky-project-card"
                                    v-for="project in filteredProjects"
                                    :key="project.id"
                                    :class="{ 'tracky-project-card--active': Number(project.id) === Number(selectedProject?.id) }"
                                    @click="selectProject(project)"
                                >
                                    <div class="tracky-project-card__head">
                                        <p class="tracky-project-code">{{ projectReference(project.id) }}</p>
                                        <span class="badge" :class="projectPriorityClass(project.id)">
                                            {{ t(`dashboard.${resolveProjectPriority(project.id)}`) }}
                                        </span>
                                    </div>
                                    <h4>{{ project.name }}</h4>
                                    <p class="tracky-project-meta">{{ project.municipality?.name || '-' }} - {{ project.status }}</p>
                                    <div class="tracky-project-foot">
                                        <span>{{ shortProjectDescription(project.description) }}</span>
                                        <div class="tracky-project-metrics">
                                            <span>{{ t('dashboard.approved') }}: {{ getProjectStats(project.id).approved }}</span>
                                            <span>{{ t('dashboard.pending') }}: {{ getProjectStats(project.id).pending }}</span>
                                        </div>
                                    </div>
                                </article>
                            </div>
                            <p class="tracky-project-list-empty" v-else>{{ t('projectsPage.noProjects') }}</p>
                        </aside>
                    </div>
                </div>

                <Transition name="tracky-detail-reveal">
                    <aside class="tracky-detail-pane" v-if="selectedProject">
                        <header class="tracky-detail-pane__header">
                            <h3>{{ t('dashboard.projectDetails') }}</h3>
                            <div class="tracky-detail-pane__header-actions">
                                <span class="badge badge--active">{{ String(selectedProject.status || 'active').toUpperCase() }}</span>
                                <button class="tracky-detail-pane__close" type="button" @click="closeProjectDetails" aria-label="Close details">×</button>
                            </div>
                        </header>
                        <h4>{{ selectedProject.name }}</h4>
                        <p class="tracky-project-meta">{{ selectedProject.municipality?.name || '-' }}</p>
                        <hr />
                        <h5>{{ t('dashboard.projectDescription') }}</h5>
                        <p>{{ selectedProject.description || t('dashboard.noDescription') }}</p>
                        <hr />
                        <ul class="tracky-detail-kv">
                            <li>
                                <span>{{ t('dashboard.reports') }}</span>
                                <strong>{{ selectedProjectStats?.total || 0 }}</strong>
                            </li>
                            <li>
                                <span>{{ t('dashboard.approved') }}</span>
                                <strong>{{ selectedProjectStats?.approved || 0 }}</strong>
                            </li>
                            <li>
                                <span>{{ t('dashboard.pending') }}</span>
                                <strong>{{ selectedProjectStats?.pending || 0 }}</strong>
                            </li>
                            <li>
                                <span>{{ t('dashboard.rejected') }}</span>
                                <strong>{{ selectedProjectStats?.rejected || 0 }}</strong>
                            </li>
                            <li>
                                <span>{{ t('projectsPage.progress') }}</span>
                                <strong>{{ resolveProjectProgress(selectedProject.id) }}%</strong>
                            </li>
                            <li>
                                <span>{{ t('dashboard.location') }}</span>
                                <strong>{{ selectedProject.latitude ?? '-' }}, {{ selectedProject.longitude ?? '-' }}</strong>
                            </li>
                        </ul>
                        <button
                            class="tracky-btn tracky-btn--soft"
                            type="button"
                            v-if="canOpenProjectSubmissions"
                            @click="openProjectSubmissions(selectedProject)"
                        >
                            {{ t('dashboard.goToSubmission') }}
                        </button>
                    </aside>
                </Transition>
            </section>

            <div v-if="loading" class="tracky-loading-mask">{{ t('dashboard.loadingDashboard') }}</div>
        </section>
    </AppShell>
</template>
