<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import AppShell from '../components/AppShell.vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';

const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const router = useRouter();

const municipalities = ref([]);
const projects = ref([]);
const tabCounts = ref({ all: 0, by_municipality: {} });
const loading = ref(false);
const detailLoading = ref(false);
const saving = ref(false);
const error = ref('');

const activeMunicipalityTab = ref('all');
const projectDetailsModalOpen = ref(false);
const projectDetails = ref(null);
const projectReporters = ref([]);
const projectFormModalOpen = ref(false);
const municipalityModalOpen = ref(false);
const editingProjectId = ref(null);

const filters = reactive({
    search: '',
    status: '',
    municipality_id: '',
});

const pagination = reactive({
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
});

const municipalityForm = reactive({
    name_en: '',
    name_ar: '',
    code: '',
});

const projectForm = reactive({
    municipality_id: '',
    name_en: '',
    name_ar: '',
    description: '',
    status: 'active',
    latitude: '',
    longitude: '',
});

let searchTimer = null;

const canManageMunicipalities = computed(() => auth.hasPermission('municipalities.manage'));
const canManageProjects = computed(() => auth.hasPermission('projects.manage'));
const canViewProjectSubmissions = computed(() => (
    auth.hasPermission('submissions.view.own')
    || auth.hasPermission('submissions.view.municipality')
    || auth.hasPermission('submissions.view.all')
    || auth.hasPermission('submissions.view.approved_aggregated')
));

const projectReference = (projectId) => `PRJ-${String(projectId).padStart(3, '0')}`;

const projectPriority = (project) => {
    const pending = Number(project?.stats?.pending_submissions || 0);

    if (pending >= 8) return 'high';
    if (pending >= 3) return 'medium';
    return 'low';
};

const projectPriorityClass = (project) => `badge--${projectPriority(project)}`;

const municipalityTabs = computed(() => {
    const tabs = [{ id: 'all', label: t('projectsPage.allMunicipalities'), count: Number(tabCounts.value.all || 0) }];

    municipalities.value.forEach((municipality) => {
        tabs.push({
            id: String(municipality.id),
            label: municipality.name,
            count: Number(tabCounts.value.by_municipality?.[municipality.id] || 0),
        });
    });

    return tabs;
});

const visiblePages = computed(() => {
    const pages = [];
    const current = pagination.current_page;
    const last = pagination.last_page;

    if (last <= 7) {
        for (let page = 1; page <= last; page += 1) {
            pages.push(page);
        }
        return pages;
    }

    pages.push(1);

    if (current > 3) {
        pages.push('ellipsis-left');
    }

    const start = Math.max(2, current - 1);
    const end = Math.min(last - 1, current + 1);

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    if (current < last - 2) {
        pages.push('ellipsis-right');
    }

    pages.push(last);

    return pages;
});

const openProjectSubmissions = (project) => {
    if (!project || !canViewProjectSubmissions.value) {
        return;
    }

    router.push({
        name: 'project-submissions',
        params: {
            id: String(project.id),
        },
    });
};

const setMunicipalityTab = async (tabId) => {
    activeMunicipalityTab.value = tabId;
    filters.municipality_id = tabId === 'all' ? '' : String(tabId);
    await loadProjects(1);
};

const populateProjectForm = (project) => {
    editingProjectId.value = project.id;

    Object.assign(projectForm, {
        municipality_id: project.municipality?.id || '',
        name_en: project.name_en || '',
        name_ar: project.name_ar || '',
        description: project.description || '',
        status: project.status || 'active',
        latitude: project.latitude ?? '',
        longitude: project.longitude ?? '',
    });
};

const resetProjectForm = () => {
    Object.assign(projectForm, {
        municipality_id: '',
        name_en: '',
        name_ar: '',
        description: '',
        status: 'active',
        latitude: '',
        longitude: '',
    });
    editingProjectId.value = null;
};

const loadMunicipalities = async () => {
    try {
        const { data } = await api.get('/municipalities');
        municipalities.value = data.data || [];
    } catch {
        municipalities.value = [];
    }
};

const loadProjects = async (page = pagination.current_page) => {
    loading.value = true;
    error.value = '';

    try {
        const { data } = await api.get('/projects', {
            params: {
                with_stats: 1,
                per_page: pagination.per_page,
                page,
                search: filters.search || undefined,
                status: filters.status || undefined,
                municipality_id: filters.municipality_id || undefined,
            },
        });

        projects.value = data.data || [];
        pagination.current_page = data.current_page || 1;
        pagination.last_page = data.last_page || 1;
        pagination.total = data.total || projects.value.length;
        tabCounts.value = data.tab_counts || { all: projects.value.length, by_municipality: {} };
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load projects.';
        projects.value = [];
        tabCounts.value = { all: 0, by_municipality: {} };
        pagination.current_page = 1;
        pagination.last_page = 1;
        pagination.total = 0;
    } finally {
        loading.value = false;
    }
};

const fetchProjectDetails = async (projectId) => {
    detailLoading.value = true;
    error.value = '';

    try {
        const { data } = await api.get(`/projects/${projectId}`);
        projectDetails.value = data.project;
        projectReporters.value = data.reporters || [];
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load project details.';
        projectDetails.value = null;
        projectReporters.value = [];
    } finally {
        detailLoading.value = false;
    }
};

const openProjectDetails = async (project) => {
    if (!project) {
        return;
    }

    projectDetailsModalOpen.value = true;
    await fetchProjectDetails(project.id);
};

const closeProjectDetails = () => {
    projectDetailsModalOpen.value = false;
    projectDetails.value = null;
    projectReporters.value = [];
};

const saveMunicipality = async () => {
    if (!canManageMunicipalities.value) {
        return;
    }

    saving.value = true;
    error.value = '';

    try {
        await api.post('/municipalities', {
            name_en: municipalityForm.name_en,
            name_ar: municipalityForm.name_ar,
            code: municipalityForm.code || null,
        });

        Object.assign(municipalityForm, {
            name_en: '',
            name_ar: '',
            code: '',
        });

        municipalityModalOpen.value = false;
        ui.pushToast('Municipality saved successfully.');
        await loadMunicipalities();
        await loadProjects(1);
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to save municipality.';
    } finally {
        saving.value = false;
    }
};

const saveProject = async () => {
    if (!canManageProjects.value) {
        return;
    }

    saving.value = true;
    error.value = '';

    const payload = {
        municipality_id: projectForm.municipality_id ? Number(projectForm.municipality_id) : null,
        name_en: projectForm.name_en,
        name_ar: projectForm.name_ar,
        description: projectForm.description || null,
        status: projectForm.status,
        latitude: projectForm.latitude === '' ? null : Number(projectForm.latitude),
        longitude: projectForm.longitude === '' ? null : Number(projectForm.longitude),
    };

    try {
        if (editingProjectId.value) {
            await api.put(`/projects/${editingProjectId.value}`, payload);
            ui.pushToast('Project updated successfully.');
        } else {
            await api.post('/projects', payload);
            ui.pushToast('Project created successfully.');
        }

        projectFormModalOpen.value = false;
        resetProjectForm();
        await loadProjects(pagination.current_page);

        if (projectDetailsModalOpen.value && projectDetails.value) {
            await fetchProjectDetails(projectDetails.value.id);
        }
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to save project.';
    } finally {
        saving.value = false;
    }
};

const openCreateProjectModal = () => {
    resetProjectForm();
    projectFormModalOpen.value = true;
};

const openEditProjectModal = (project) => {
    if (!project || !canManageProjects.value) {
        return;
    }

    if (projectDetailsModalOpen.value) {
        projectDetailsModalOpen.value = false;
    }

    populateProjectForm(project);
    projectFormModalOpen.value = true;
};

const closeProjectFormModal = () => {
    projectFormModalOpen.value = false;
    resetProjectForm();
};

const goToPage = async (page) => {
    if (typeof page !== 'number' || page === pagination.current_page || page < 1 || page > pagination.last_page) {
        return;
    }

    await loadProjects(page);
};

watch(() => filters.status, async () => {
    await loadProjects(1);
});

watch(() => filters.search, () => {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(() => {
        loadProjects(1);
    }, 280);
});

onMounted(async () => {
    await loadMunicipalities();

    const preferredMunicipalityId = Number(auth.user?.municipality?.id || 0);
    if (preferredMunicipalityId && municipalities.value.some((row) => Number(row.id) === preferredMunicipalityId)) {
        activeMunicipalityTab.value = String(preferredMunicipalityId);
        filters.municipality_id = String(preferredMunicipalityId);
    }

    await loadProjects(1);
});

onBeforeUnmount(() => {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }
});
</script>

<template>
    <AppShell>
        <section class="tracky-projects">
            <header class="tracky-projects__head">
                <div>
                    <h2>{{ t('projectsPage.title') }}</h2>
                    <p>{{ t('projectsPage.subtitle') }}</p>
                </div>
                <div class="tracky-projects__head-actions">
                    <button
                        class="tracky-btn tracky-btn--ghost"
                        type="button"
                        v-if="canManageMunicipalities"
                        @click="municipalityModalOpen = true"
                    >
                        {{ t('projectsPage.createMunicipality') }}
                    </button>
                    <button
                        class="tracky-btn tracky-btn--primary"
                        type="button"
                        v-if="canManageProjects"
                        @click="openCreateProjectModal"
                    >
                        <span>+</span>
                        <span>{{ t('dashboard.addNewProject') }}</span>
                    </button>
                </div>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <section class="tracky-card tracky-projects__toolbar">
                <div class="tracky-projects__filters">
                    <div class="tracky-projects__search-wrap">
                        <input v-model="filters.search" :placeholder="t('dashboard.searchProjects')">
                    </div>

                    <select v-model="filters.status">
                        <option value="">{{ t('dashboard.allStatus') }}</option>
                        <option value="active">{{ t('dashboard.active') }}</option>
                        <option value="archived">{{ t('dashboard.archived') }}</option>
                    </select>
                </div>

                <div class="tracky-projects__tabs">
                    <button
                        type="button"
                        v-for="tab in municipalityTabs"
                        :key="tab.id"
                        :class="{ active: activeMunicipalityTab === tab.id }"
                        @click="setMunicipalityTab(tab.id)"
                    >
                        <span>{{ tab.label }}</span>
                        <small>{{ tab.count }}</small>
                    </button>
                </div>
            </section>

            <section class="tracky-card tracky-projects__content">
                <div class="tracky-projects__empty" v-if="loading">{{ t('common.loading') }}</div>

                <div class="tracky-projects-table-wrap" v-else-if="projects.length">
                    <table class="tracky-projects-table">
                        <thead>
                        <tr>
                            <th>{{ t('projectsPage.projects') }}</th>
                            <th>{{ t('projectsPage.projectReference') }}</th>
                            <th>{{ t('projectsPage.approvedSubmissions') }}</th>
                            <th>{{ t('projectsPage.pendingSubmissions') }}</th>
                            <th>{{ t('projectsPage.progress') }}</th>
                            <th>{{ t('projectsPage.status') }}</th>
                            <th>{{ t('projectsPage.tableColumns.actions') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="project in projects" :key="project.id" @click="openProjectDetails(project)">
                            <td>
                                <strong>{{ project.name }}</strong>
                                <p>ID:{{ project.id }}</p>
                            </td>
                            <td>{{ projectReference(project.id) }}</td>
                            <td>{{ project.stats?.approved_submissions || 0 }}</td>
                            <td>{{ project.stats?.pending_submissions || 0 }}</td>
                            <td>
                                <div class="tracky-project-progress-row">
                                    <small>{{ project.stats?.progress_percent || 0 }}%</small>
                                </div>
                                <div class="tracky-project-inline-progress">
                                    <span :style="{ width: `${project.stats?.progress_percent || 0}%` }" />
                                </div>
                            </td>
                            <td>
                                <span class="badge" :class="project.status === 'active' ? 'badge--active' : 'badge--archived'">
                                    {{ project.status === 'active' ? t('dashboard.active') : t('dashboard.archived') }}
                                </span>
                            </td>
                            <td>
                                <div class="tracky-project-actions" @click.stop>
                                    <button
                                        class="tracky-btn tracky-btn--ghost"
                                        type="button"
                                        v-if="canViewProjectSubmissions"
                                        @click="openProjectSubmissions(project)"
                                    >
                                        View Submission
                                    </button>
                                    <button
                                        class="tracky-btn tracky-btn--ghost"
                                        type="button"
                                        v-if="canManageProjects"
                                        @click="openEditProjectModal(project)"
                                    >
                                        {{ t('projectsPage.editProject') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>

                <div class="tracky-projects__empty" v-else>
                    <h3>{{ t('projectsPage.noProjects') }}</h3>
                    <p>Create a project to start monitoring activities.</p>
                </div>
            </section>

            <footer class="tracky-projects__pagination" v-if="!loading && pagination.last_page > 1">
                <p>Page {{ pagination.current_page }} of {{ pagination.last_page }}</p>
                <div class="tracky-page-buttons">
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.current_page <= 1" @click="goToPage(pagination.current_page - 1)">
                        Prev
                    </button>
                    <button
                        v-for="page in visiblePages"
                        :key="String(page)"
                        class="tracky-btn"
                        :class="typeof page === 'number' && page === pagination.current_page ? 'tracky-btn--primary' : 'tracky-btn--ghost'"
                        :disabled="typeof page !== 'number'"
                        @click="goToPage(page)"
                    >
                        {{ typeof page === 'number' ? page : '...' }}
                    </button>
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.current_page >= pagination.last_page" @click="goToPage(pagination.current_page + 1)">
                        Next
                    </button>
                </div>
            </footer>

            <div class="tracky-project-modal-backdrop" v-if="projectDetailsModalOpen" @click.self="closeProjectDetails">
                <article class="tracky-project-modal">
                    <header class="tracky-project-modal__head">
                        <div>
                            <h3>{{ detailLoading ? 'Loading project...' : projectDetails?.name || 'Project Details' }}</h3>
                            <p v-if="projectDetails">{{ projectReference(projectDetails.id) }}</p>
                        </div>
                        <div class="tracky-project-modal__head-actions">
                            <button
                                class="tracky-btn tracky-btn--soft"
                                type="button"
                                v-if="projectDetails && canViewProjectSubmissions"
                                @click="openProjectSubmissions(projectDetails)"
                            >
                                Go to Submissions
                            </button>
                            <button
                                class="tracky-btn tracky-btn--ghost"
                                type="button"
                                v-if="projectDetails && canManageProjects"
                                @click="openEditProjectModal(projectDetails)"
                            >
                                {{ t('projectsPage.editProject') }}
                            </button>
                            <button class="tracky-btn tracky-btn--ghost" type="button" @click="closeProjectDetails">Close</button>
                        </div>
                    </header>

                    <div class="tracky-project-modal__body" v-if="projectDetails && !detailLoading">
                        <section class="tracky-project-modal__column">
                            <div class="tracky-project-modal__meta-grid">
                                <div>
                                    <span>{{ t('projectsPage.status') }}</span>
                                    <strong>
                                        <span class="badge" :class="projectDetails.status === 'active' ? 'badge--active' : 'badge--archived'">
                                            {{ projectDetails.status === 'active' ? t('dashboard.active') : t('dashboard.archived') }}
                                        </span>
                                    </strong>
                                </div>
                                <div>
                                    <span>{{ t('projectsPage.municipality') }}</span>
                                    <strong>{{ projectDetails.municipality?.name || '-' }}</strong>
                                </div>
                                <div>
                                    <span>{{ t('projectsPage.progress') }}</span>
                                    <strong>{{ projectDetails.stats?.progress_percent || 0 }}%</strong>
                                </div>
                                <div>
                                    <span>{{ t('projectsPage.updatedAt') }}</span>
                                    <strong>{{ projectDetails.last_update_at ? new Date(projectDetails.last_update_at).toLocaleDateString() : '-' }}</strong>
                                </div>
                            </div>

                            <div class="tracky-project-mini-map">
                                <strong>Project location</strong>
                                <span>{{ projectDetails.latitude ?? '-' }}, {{ projectDetails.longitude ?? '-' }}</span>
                            </div>

                            <div class="tracky-project-section">
                                <h4>{{ t('dashboard.projectDescription') }}</h4>
                                <p>{{ projectDetails.description || t('dashboard.noDescription') }}</p>
                            </div>

                            <div class="tracky-project-section">
                                <div class="tracky-project-progress-row">
                                    <h4>{{ t('projectsPage.progress') }}</h4>
                                    <strong>{{ projectDetails.stats?.progress_percent || 0 }}%</strong>
                                </div>
                                <div class="tracky-progress">
                                    <div class="tracky-progress__bar" :style="{ width: `${projectDetails.stats?.progress_percent || 0}%` }" />
                                </div>
                            </div>
                        </section>

                        <section class="tracky-project-modal__column">
                            <div class="tracky-project-section">
                                <h4>{{ t('projectsPage.submissionStats') }}</h4>
                                <ul class="tracky-project-stats-list">
                                    <li>
                                        <span>{{ t('projectsPage.totalSubmissions') }}</span>
                                        <strong>{{ projectDetails.stats?.total_submissions || 0 }}</strong>
                                    </li>
                                    <li>
                                        <span>{{ t('projectsPage.approvedSubmissions') }}</span>
                                        <strong>{{ projectDetails.stats?.approved_submissions || 0 }}</strong>
                                    </li>
                                    <li>
                                        <span>{{ t('projectsPage.pendingSubmissions') }}</span>
                                        <strong>{{ projectDetails.stats?.pending_submissions || 0 }}</strong>
                                    </li>
                                    <li>
                                        <span>{{ t('projectsPage.rejectedSubmissions') }}</span>
                                        <strong>{{ projectDetails.stats?.rejected_submissions || 0 }}</strong>
                                    </li>
                                    <li>
                                        <span>{{ t('projectsPage.mediaAttachments') }}</span>
                                        <strong>{{ projectDetails.stats?.media_attachments || 0 }}</strong>
                                    </li>
                                </ul>
                            </div>

                            <div class="tracky-project-section" v-if="projectReporters.length">
                                <h4>Assigned Reporter Details</h4>
                                <ul class="tracky-project-stats-list">
                                    <li v-for="reporter in projectReporters" :key="reporter.id">
                                        <span>{{ reporter.name }}</span>
                                        <strong>{{ reporter.email || '-' }}</strong>
                                    </li>
                                </ul>
                            </div>
                        </section>
                    </div>

                    <div class="tracky-projects__empty" v-else-if="detailLoading">Loading project details...</div>
                </article>
            </div>

            <div class="modal-backdrop" v-if="projectFormModalOpen" @click.self="closeProjectFormModal">
                <article class="modal-card tracky-form-modal">
                    <h3>{{ editingProjectId ? t('projectsPage.editProject') : t('projectsPage.createProject') }}</h3>

                    <label class="field">
                        {{ t('projectsPage.municipality') }}
                        <select v-model="projectForm.municipality_id">
                            <option value="">{{ t('projectsPage.selectMunicipality') }}</option>
                            <option v-for="municipality in municipalities" :key="municipality.id" :value="municipality.id">
                                {{ municipality.name }}
                            </option>
                        </select>
                    </label>

                    <label class="field">
                        {{ t('projectsPage.projectNameEn') }}
                        <input v-model="projectForm.name_en" type="text">
                    </label>

                    <label class="field">
                        {{ t('projectsPage.projectNameAr') }}
                        <input v-model="projectForm.name_ar" type="text">
                    </label>

                    <label class="field">
                        {{ t('projectsPage.description') }}
                        <textarea v-model="projectForm.description" rows="3"></textarea>
                    </label>

                    <div class="inline-group">
                        <label class="field">
                            {{ t('projectsPage.status') }}
                            <select v-model="projectForm.status">
                                <option value="active">{{ t('dashboard.active') }}</option>
                                <option value="archived">{{ t('dashboard.archived') }}</option>
                            </select>
                        </label>
                        <label class="field">
                            {{ t('projectsPage.latitude') }}
                            <input v-model="projectForm.latitude" type="number" step="any">
                        </label>
                        <label class="field">
                            {{ t('projectsPage.longitude') }}
                            <input v-model="projectForm.longitude" type="number" step="any">
                        </label>
                    </div>

                    <div class="inline-group">
                        <button class="btn btn--primary" type="button" :disabled="saving" @click="saveProject">
                            {{ editingProjectId ? t('projectsPage.updateProject') : t('projectsPage.saveProject') }}
                        </button>
                        <button class="btn btn--ghost" type="button" :disabled="saving" @click="closeProjectFormModal">
                            {{ t('common.cancel') }}
                        </button>
                    </div>
                </article>
            </div>

            <div class="modal-backdrop" v-if="municipalityModalOpen" @click.self="municipalityModalOpen = false">
                <article class="modal-card tracky-form-modal">
                    <h3>{{ t('projectsPage.createMunicipality') }}</h3>

                    <label class="field">
                        {{ t('projectsPage.municipalityNameEn') }}
                        <input v-model="municipalityForm.name_en" type="text">
                    </label>

                    <label class="field">
                        {{ t('projectsPage.municipalityNameAr') }}
                        <input v-model="municipalityForm.name_ar" type="text">
                    </label>

                    <label class="field">
                        {{ t('projectsPage.municipalityCode') }}
                        <input v-model="municipalityForm.code" type="text">
                    </label>

                    <div class="inline-group">
                        <button class="btn btn--primary" type="button" :disabled="saving" @click="saveMunicipality">
                            {{ t('projectsPage.saveMunicipality') }}
                        </button>
                        <button class="btn btn--ghost" type="button" :disabled="saving" @click="municipalityModalOpen = false">
                            {{ t('common.cancel') }}
                        </button>
                    </div>
                </article>
            </div>
        </section>
    </AppShell>
</template>
