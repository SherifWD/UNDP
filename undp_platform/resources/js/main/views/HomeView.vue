<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
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
const statusBreakdown = ref({});
const submissions = ref([]);
const projects = ref([]);
const municipalities = ref([]);
const mapMarkers = ref([]);

const selectedProjectId = ref(null);
const mapRef = ref(null);
const showFilterPanel = ref(false);
let map;
let markerLayer;

const filters = reactive({
    municipality_id: '',
    search: '',
    status: '',
    priority: '',
    area: '',
});

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

const beneficiaryTotal = computed(() => {
    const fromData = submissions.value.reduce((sum, item) => {
        const value = Number(item?.data?.beneficiaries || 0);
        return sum + (Number.isFinite(value) ? value : 0);
    }, 0);

    if (fromData > 0) return fromData;
    return Math.max(totalReports.value * 120, 0);
});

const fundingTarget = 14000000;
const fundingCurrent = computed(() => {
    if (totalReports.value <= 0) return 0;
    const ratio = Math.max(0.18, Math.min(0.88, approvedCount.value / totalReports.value || 0));
    return Math.round(fundingTarget * ratio);
});

const fundingPercent = computed(() => ((fundingCurrent.value / fundingTarget) * 100).toFixed(1));

const filteredProjects = computed(() => {
    const search = filters.search.trim().toLowerCase();
    const selectedMunicipality = Number(filters.municipality_id || 0);

    return projects.value.filter((project) => {
        if (selectedMunicipality && Number(project.municipality?.id || 0) !== selectedMunicipality) {
            return false;
        }

        if (search && !String(project.name || '').toLowerCase().includes(search)) {
            return false;
        }

        if (filters.status && project.status !== filters.status) {
            return false;
        }

        return true;
    });
});

const selectedProject = computed(() => {
    if (!filteredProjects.value.length) return null;

    const current = filteredProjects.value.find((item) => Number(item.id) === Number(selectedProjectId.value));
    return current || filteredProjects.value[0];
});

const selectedProjectSubmissions = computed(() => {
    if (!selectedProject.value) return [];
    return submissions.value.filter((submission) => Number(submission.project?.id) === Number(selectedProject.value.id));
});

const addNewProject = () => {
    router.push({ name: 'projects' });
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

const initMap = async () => {
    await nextTick();
    if (!mapRef.value || map) return;

    map = L.map(mapRef.value, {
        zoomControl: true,
    }).setView([26.3351, 17.2283], 6);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    markerLayer = L.layerGroup().addTo(map);
};

const renderMarkers = () => {
    if (!markerLayer) return;
    markerLayer.clearLayers();

    mapMarkers.value.forEach((markerData) => {
        const marker = L.circleMarker([markerData.lat, markerData.lng], {
            radius: markerData.type === 'project' ? 8 : 6,
            color: markerData.type === 'project' ? '#3B6DD8' : '#E34D43',
            fillColor: markerData.type === 'project' ? '#3B6DD8' : '#E34D43',
            fillOpacity: 0.95,
            weight: 1,
        });

        marker.bindPopup(`
            <strong>${markerData.name}</strong><br/>
            ${t('common.status')}: ${markerData.status || '-'}<br/>
            ${t('dashboard.municipality')}: ${markerData.municipality || '-'}
        `);

        markerLayer.addLayer(marker);
    });
};

const loadDashboard = async () => {
    loading.value = true;
    error.value = '';

    try {
        const mapParams = {
            municipality_id: filters.municipality_id || undefined,
            status: filters.status || undefined,
            project_id: filters.area || undefined,
        };

        const [kpiRes, mapRes, projectRes, submissionRes, municipalityRes] = await Promise.all([
            api.get('/dashboard/kpis', { params: { municipality_id: filters.municipality_id || undefined } }),
            api.get('/dashboard/map', { params: mapParams }),
            api.get('/projects', { params: { municipality_id: filters.municipality_id || undefined } }),
            api.get('/submissions', { params: { per_page: 120, municipality_id: filters.municipality_id || undefined } }),
            api.get('/municipalities'),
        ]);

        kpis.value = kpiRes.data.kpis || {};
        statusBreakdown.value = kpiRes.data.status_breakdown || {};
        mapMarkers.value = mapRes.data.markers || [];
        projects.value = projectRes.data.data || [];
        submissions.value = submissionRes.data.data || [];
        municipalities.value = municipalityRes.data.data || [];

        if (!selectedProjectId.value && projects.value.length) {
            selectedProjectId.value = projects.value[0].id;
        }

        await initMap();
        renderMarkers();
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load dashboard.';
    } finally {
        loading.value = false;
    }
};

onMounted(loadDashboard);

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
                <button class="tracky-btn tracky-btn--primary" type="button" @click="addNewProject">
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

            <section class="tracky-card tracky-map-layout">
                <div class="tracky-map-wrap">
                    <div class="tracky-map-controls">
                        <label>{{ t('dashboard.municipality') }}</label>
                        <select v-model="filters.municipality_id" @change="loadDashboard">
                            <option value="">{{ t('dashboard.selectMunicipality') }}</option>
                            <option v-for="m in municipalities" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                    </div>
                    <div ref="mapRef" class="tracky-map" />
                </div>

                <aside class="tracky-side-rail">
                    <div class="tracky-side-toolbar">
                        <input v-model="filters.search" :placeholder="t('dashboard.searchProjects')" />
                        <button type="button" class="tracky-btn tracky-btn--ghost" @click="showFilterPanel = !showFilterPanel">{{ t('dashboard.filter') }}</button>
                    </div>

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

                    <div class="tracky-project-list">
                        <article
                            class="tracky-project-card"
                            v-for="project in filteredProjects"
                            :key="project.id"
                            :class="{ 'tracky-project-card--active': Number(project.id) === Number(selectedProject?.id) }"
                            @click="selectedProjectId = project.id"
                        >
                            <p class="tracky-project-code">PRJ-{{ String(project.id).padStart(3, '0') }}</p>
                            <h4>{{ project.name }}</h4>
                            <p class="tracky-project-meta">{{ project.municipality?.name || '-' }} - {{ project.status }}</p>
                            <div class="tracky-project-foot">
                                <span>{{ project.description ? project.description.slice(0, 34) : t('dashboard.noShortDescription') }}</span>
                                <span class="badge badge--progress">{{ project.status === 'active' ? t('dashboard.inProgress') : t('dashboard.archivedBadge') }}</span>
                            </div>
                        </article>
                    </div>
                </aside>

                <aside class="tracky-detail-pane" v-if="selectedProject">
                    <header>
                        <h3>{{ t('dashboard.projectDetails') }}</h3>
                        <span class="badge badge--active">{{ String(selectedProject.status || 'active').toUpperCase() }}</span>
                    </header>
                    <h4>{{ selectedProject.name }}</h4>
                    <p class="tracky-project-meta">{{ selectedProject.municipality?.name || '-' }}</p>
                    <hr />
                    <h5>{{ t('dashboard.projectDescription') }}</h5>
                    <p>{{ selectedProject.description || t('dashboard.noDescription') }}</p>
                    <hr />
                    <ul>
                        <li><span>{{ t('dashboard.duration') }}</span><strong>3 Months</strong></li>
                        <li><span>{{ t('dashboard.donors') }}</span><strong>UNDP + Partners</strong></li>
                        <li><span>{{ t('dashboard.programLead') }}</span><strong>UNDP Libya</strong></li>
                        <li><span>{{ t('dashboard.contact') }}</span><strong>+218 91 554 88 44</strong></li>
                    </ul>
                    <button class="tracky-btn tracky-btn--soft" type="button">
                        {{ t('dashboard.goToSubmission') }}
                    </button>
                    <p class="tracky-subtle">
                        {{ t('dashboard.relatedUpdates', { count: selectedProjectSubmissions.length }) }}
                    </p>
                </aside>
            </section>

            <div v-if="loading" class="tracky-loading-mask">{{ t('dashboard.loadingDashboard') }}</div>
        </section>
    </AppShell>
</template>
