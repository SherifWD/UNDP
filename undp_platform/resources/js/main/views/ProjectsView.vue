<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
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
const submissions = ref([]);
const loading = ref(false);
const saving = ref(false);
const error = ref('');

const viewMode = ref('table');
const activeMunicipalityTab = ref('all');
const selectedProjectId = ref(null);
const projectDetailsModalOpen = ref(false);
const projectFormModalOpen = ref(false);
const municipalityModalOpen = ref(false);
const editingProjectId = ref(null);

const filters = reactive({
    search: '',
    status: '',
    municipality_id: '',
});

const pagination = reactive({
    page: 1,
    perPage: 9,
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

const canManageMunicipalities = computed(() => auth.hasPermission('municipalities.manage'));
const canManageProjects = computed(() => auth.hasPermission('projects.manage'));
const canOpenSubmissionWorklist = computed(() => auth.hasPermission('submissions.validate'));

const projectReference = (projectId) => `PRJ-${String(projectId).padStart(3, '0')}`;

const formatDateTime = (value) => {
    if (!value) {
        return '-';
    }

    return new Date(value).toLocaleDateString();
};

const normalizeText = (value) => String(value || '').toLowerCase();

const submissionStatsByProject = computed(() => {
    const pendingStatuses = new Set(['under_review', 'submitted', 'rework_requested', 'queued']);
    const rows = {};

    submissions.value.forEach((submission) => {
        const projectId = Number(submission?.project?.id || submission?.project_id || 0);
        if (!projectId) {
            return;
        }

        if (!rows[projectId]) {
            rows[projectId] = {
                total: 0,
                approved: 0,
                pending: 0,
                rejected: 0,
                progressValues: [],
                mediaItems: 0,
            };
        }

        const current = rows[projectId];
        current.total += 1;

        if (submission.status === 'approved') {
            current.approved += 1;
        } else if (submission.status === 'rejected') {
            current.rejected += 1;
        } else if (pendingStatuses.has(submission.status)) {
            current.pending += 1;
        }

        const progressValue = Number(submission?.data?.progress_percent || 0);
        if (Number.isFinite(progressValue) && progressValue > 0) {
            current.progressValues.push(progressValue);
        }

        const mediaAssets = Array.isArray(submission?.media_assets) ? submission.media_assets.length : 0;
        const mediaFallback = Array.isArray(submission?.media) ? submission.media.length : 0;
        current.mediaItems += mediaAssets || mediaFallback;
    });

    return rows;
});

const resolveProgress = (projectId, status) => {
    const stats = submissionStatsByProject.value[Number(projectId)] || null;

    if (stats?.progressValues?.length) {
        const avg = stats.progressValues.reduce((sum, value) => sum + value, 0) / stats.progressValues.length;
        return Math.max(0, Math.min(100, Math.round(avg)));
    }

    if (!stats || stats.total === 0) {
        return status === 'archived' ? 100 : 0;
    }

    return Math.max(0, Math.min(100, Math.round((stats.approved / stats.total) * 100)));
};

const resolvePriority = (pendingCount) => {
    if (pendingCount >= 8) return 'high';
    if (pendingCount >= 3) return 'medium';
    return 'low';
};

const filteredProjects = computed(() => {
    const search = normalizeText(filters.search.trim());

    return projects.value
        .filter((project) => {
            if (filters.municipality_id && Number(project.municipality?.id) !== Number(filters.municipality_id)) {
                return false;
            }

            if (filters.status && project.status !== filters.status) {
                return false;
            }

            if (search) {
                const haystack = `${project.name || ''} ${project.municipality?.name || ''} ${projectReference(project.id)}`.toLowerCase();
                if (!haystack.includes(search)) {
                    return false;
                }
            }

            return true;
        })
        .sort((a, b) => new Date(b.last_update_at || 0).getTime() - new Date(a.last_update_at || 0).getTime());
});

const enrichedProjects = computed(() => {
    return filteredProjects.value.map((project) => {
        const stats = submissionStatsByProject.value[Number(project.id)] || {
            total: 0,
            approved: 0,
            pending: 0,
            rejected: 0,
            progressValues: [],
            mediaItems: 0,
        };

        const progress = resolveProgress(project.id, project.status);
        const priority = resolvePriority(stats.pending);

        return {
            ...project,
            reference: projectReference(project.id),
            stats,
            progress,
            priority,
        };
    });
});

const municipalityTabs = computed(() => {
    const tabs = [{ id: 'all', label: t('projectsPage.allMunicipalities'), count: projects.value.length }];

    municipalities.value.forEach((municipality) => {
        const count = projects.value.filter((project) => Number(project.municipality?.id) === Number(municipality.id)).length;
        tabs.push({
            id: String(municipality.id),
            label: municipality.name,
            count,
        });
    });

    return tabs;
});

const totalPages = computed(() => {
    return Math.max(1, Math.ceil(enrichedProjects.value.length / pagination.perPage));
});

const paginatedProjects = computed(() => {
    const start = (pagination.page - 1) * pagination.perPage;
    return enrichedProjects.value.slice(start, start + pagination.perPage);
});

const visiblePages = computed(() => {
    const pages = [];
    const current = pagination.page;
    const total = totalPages.value;

    if (total <= 7) {
        for (let page = 1; page <= total; page += 1) {
            pages.push(page);
        }
        return pages;
    }

    pages.push(1);

    if (current > 3) {
        pages.push('...-left');
    }

    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    if (current < total - 2) {
        pages.push('...-right');
    }

    pages.push(total);

    return pages;
});

const selectedProject = computed(() => {
    return enrichedProjects.value.find((project) => Number(project.id) === Number(selectedProjectId.value)) || null;
});

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

const loadData = async () => {
    loading.value = true;
    error.value = '';

    try {
        const results = await Promise.allSettled([
            api.get('/municipalities'),
            api.get('/projects'),
            api.get('/submissions', { params: { per_page: 100 } }),
        ]);

        const [municipalityResult, projectsResult, submissionsResult] = results;
        const partialErrors = [];

        if (municipalityResult.status === 'fulfilled') {
            municipalities.value = municipalityResult.value.data.data || [];
        } else {
            municipalities.value = [];
            partialErrors.push(municipalityResult.reason?.response?.data?.message || 'Unable to load municipalities.');
        }

        if (projectsResult.status === 'fulfilled') {
            projects.value = projectsResult.value.data.data || [];
        } else {
            projects.value = [];
            partialErrors.push(projectsResult.reason?.response?.data?.message || 'Unable to load projects.');
        }

        if (submissionsResult.status === 'fulfilled') {
            submissions.value = submissionsResult.value.data.data || [];
        } else {
            submissions.value = [];
        }

        if (!filters.municipality_id) {
            const preferredMunicipalityId = Number(auth.user?.municipality?.id || 0);

            if (preferredMunicipalityId && municipalities.value.some((row) => Number(row.id) === preferredMunicipalityId)) {
                filters.municipality_id = String(preferredMunicipalityId);
                activeMunicipalityTab.value = String(preferredMunicipalityId);
            }
        }

        if (partialErrors.length > 0) {
            error.value = partialErrors[0];
        }
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load projects and municipalities.';
    } finally {
        loading.value = false;
    }
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
        await loadData();
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
        await loadData();
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

    projectFormModalOpen.value = true;
};

const closeProjectFormModal = () => {
    projectFormModalOpen.value = false;
    resetProjectForm();
};

const openProjectDetails = (project) => {
    selectedProjectId.value = project.id;
    projectDetailsModalOpen.value = true;
};

const closeProjectDetails = () => {
    projectDetailsModalOpen.value = false;
};

const openSubmissionPage = (project) => {
    if (!project || !canOpenSubmissionWorklist.value) {
        return;
    }

    router.push({
        name: 'validation',
        query: {
            project_id: String(project.id),
        },
    });
};

const goToPage = (page) => {
    if (typeof page !== 'number') {
        return;
    }

    if (page < 1 || page > totalPages.value || page === pagination.page) {
        return;
    }

    pagination.page = page;
};

watch(activeMunicipalityTab, (value) => {
    filters.municipality_id = value === 'all' ? '' : String(value);
});

watch(() => filters.municipality_id, (value) => {
    const normalized = value ? String(value) : 'all';
    if (activeMunicipalityTab.value !== normalized) {
        activeMunicipalityTab.value = normalized;
    }
});

watch([() => filters.search, () => filters.status, () => filters.municipality_id, viewMode], () => {
    pagination.page = 1;
});

watch(totalPages, (value) => {
    if (pagination.page > value) {
        pagination.page = value;
    }
});

onMounted(loadData);
</script>

<template>
    <AppShell>
        <section class="tracky-projects">
            <header class="tracky-projects__head">
                <div>
                    <h2>{{ t('projectsPage.summaryTitle') }}</h2>
                    <p>{{ t('projectsPage.summarySubtitle') }}</p>
                </div>
                <div class="tracky-projects__head-actions">
                    <button
                        class="tracky-btn tracky-btn--ghost"
                        type="button"
                        v-if="canManageMunicipalities"
                        @click="municipalityModalOpen = true"
                    >
                        {{ t('projectsPage.addMunicipality') }}
                    </button>
                    <button
                        class="tracky-btn tracky-btn--primary"
                        type="button"
                        v-if="canManageProjects"
                        @click="openCreateProjectModal"
                    >
                        <span>+</span>
                        <span>{{ t('projectsPage.addProject') }}</span>
                    </button>
                </div>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <section class="tracky-card tracky-projects__toolbar">
                <div class="tracky-projects__search-wrap">
                    <input v-model="filters.search" :placeholder="t('projectsPage.searchPlaceholder')">
                </div>

                <div class="tracky-projects__filters">
                    <select v-model="filters.status">
                        <option value="">{{ t('projectsPage.allStatuses') }}</option>
                        <option value="active">{{ t('dashboard.active') }}</option>
                        <option value="archived">{{ t('dashboard.archived') }}</option>
                    </select>

                    <select v-model="filters.municipality_id">
                        <option value="">{{ t('projectsPage.allMunicipalities') }}</option>
                        <option v-for="municipality in municipalities" :key="municipality.id" :value="municipality.id">
                            {{ municipality.name }}
                        </option>
                    </select>

                    <button class="tracky-btn tracky-btn--ghost" type="button" @click="viewMode = viewMode === 'table' ? 'cards' : 'table'">
                        {{ viewMode === 'table' ? t('projectsPage.cardView') : t('projectsPage.tableView') }}
                    </button>
                </div>

                <div class="tracky-projects__tabs">
                    <button
                        type="button"
                        v-for="tab in municipalityTabs"
                        :key="tab.id"
                        :class="{ active: activeMunicipalityTab === tab.id }"
                        @click="activeMunicipalityTab = tab.id"
                    >
                        <span>{{ tab.label }}</span>
                        <small>{{ tab.count }}</small>
                    </button>
                </div>
            </section>

            <section class="tracky-card tracky-projects__content">
                <div class="tracky-projects__empty" v-if="loading">{{ t('common.loading') }}</div>

                <template v-else-if="paginatedProjects.length">
                    <div class="tracky-projects-grid" v-if="viewMode === 'cards'">
                        <article class="tracky-project-summary-card" v-for="project in paginatedProjects" :key="project.id" @click="openProjectDetails(project)">
                            <div class="tracky-project-summary-card__head">
                                <span class="badge" :class="project.status === 'active' ? 'badge--active' : 'badge--archived'">
                                    {{ project.status === 'active' ? t('dashboard.active') : t('dashboard.archived') }}
                                </span>
                                <button
                                    type="button"
                                    class="tracky-card-menu-btn"
                                    v-if="canManageProjects"
                                    @click.stop="openEditProjectModal(project)"
                                >
                                    •••
                                </button>
                            </div>

                            <p class="tracky-project-summary-card__ref">{{ project.reference }}</p>
                            <h3>{{ project.name }}</h3>
                            <p class="tracky-project-meta">{{ project.municipality?.name || '-' }}</p>

                            <div class="tracky-project-progress-row">
                                <span>{{ t('projectsPage.progress') }}</span>
                                <strong>{{ project.progress }}%</strong>
                            </div>
                            <div class="tracky-progress">
                                <div class="tracky-progress__bar" :style="{ width: `${project.progress}%` }" />
                            </div>

                            <div class="tracky-project-summary-stats">
                                <div>
                                    <small>{{ t('projectsPage.reports') }}</small>
                                    <strong>{{ project.stats.total }}</strong>
                                </div>
                                <div>
                                    <small>{{ t('dashboard.approved') }}</small>
                                    <strong>{{ project.stats.approved }}</strong>
                                </div>
                                <div>
                                    <small>{{ t('dashboard.pending') }}</small>
                                    <strong>{{ project.stats.pending }}</strong>
                                </div>
                            </div>

                            <button
                                class="tracky-btn tracky-btn--soft"
                                type="button"
                                v-if="canOpenSubmissionWorklist"
                                @click.stop="openSubmissionPage(project)"
                            >
                                {{ t('projectsPage.goToSubmissions') }}
                            </button>
                        </article>
                    </div>

                    <div class="tracky-projects-table-wrap" v-else>
                        <table class="tracky-projects-table">
                            <thead>
                            <tr>
                                <th>{{ t('projectsPage.tableColumns.project') }}</th>
                                <th>{{ t('projectsPage.tableColumns.reference') }}</th>
                                <th>{{ t('projectsPage.tableColumns.approved') }}</th>
                                <th>{{ t('projectsPage.tableColumns.pending') }}</th>
                                <th>{{ t('projectsPage.tableColumns.progress') }}</th>
                                <th>{{ t('projectsPage.tableColumns.status') }}</th>
                                <th>{{ t('projectsPage.tableColumns.actions') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr v-for="project in paginatedProjects" :key="project.id" @click="openProjectDetails(project)">
                                <td>
                                    <strong>{{ project.name }}</strong>
                                    <p>{{ project.municipality?.name || '-' }}</p>
                                </td>
                                <td>{{ project.reference }}</td>
                                <td>{{ project.stats.approved }}</td>
                                <td>{{ project.stats.pending }}</td>
                                <td>
                                    <div class="tracky-project-progress-row">
                                        <small>{{ project.progress }}%</small>
                                    </div>
                                    <div class="tracky-project-inline-progress">
                                        <span :style="{ width: `${project.progress}%` }" />
                                    </div>
                                </td>
                                <td>
                                    <span class="badge" :class="project.status === 'active' ? 'badge--active' : 'badge--archived'">
                                        {{ project.status === 'active' ? t('dashboard.active') : t('dashboard.archived') }}
                                    </span>
                                </td>
                                <td>
                                    <div class="tracky-project-actions" @click.stop>
                                        <button class="tracky-btn tracky-btn--ghost" type="button" @click="openProjectDetails(project)">
                                            {{ t('projectsPage.viewDetails') }}
                                        </button>
                                        <button
                                            class="tracky-btn tracky-btn--ghost"
                                            type="button"
                                            v-if="canOpenSubmissionWorklist"
                                            @click="openSubmissionPage(project)"
                                        >
                                            {{ t('projectsPage.viewSubmission') }}
                                        </button>
                                        <button
                                            class="tracky-btn tracky-btn--ghost"
                                            type="button"
                                            v-if="canManageProjects"
                                            @click="openEditProjectModal(project)"
                                        >
                                            {{ t('projectsPage.editShort') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </template>

                <div class="tracky-projects__empty" v-else>
                    <h3>{{ t('projectsPage.noProjectsTitle') }}</h3>
                    <p>{{ t('projectsPage.noProjectsBody') }}</p>
                </div>
            </section>

            <footer class="tracky-projects__pagination" v-if="!loading && enrichedProjects.length">
                <p>{{ t('projectsPage.pageIndicator', { page: pagination.page, total: totalPages }) }}</p>
                <div class="tracky-page-buttons">
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.page <= 1" @click="goToPage(pagination.page - 1)">
                        Prev
                    </button>
                    <button
                        v-for="page in visiblePages"
                        :key="String(page)"
                        class="tracky-btn"
                        :class="typeof page === 'number' && page === pagination.page ? 'tracky-btn--primary' : 'tracky-btn--ghost'"
                        :disabled="typeof page !== 'number'"
                        @click="goToPage(page)"
                    >
                        {{ typeof page === 'number' ? page : '...' }}
                    </button>
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.page >= totalPages" @click="goToPage(pagination.page + 1)">
                        Next
                    </button>
                </div>
            </footer>

            <div class="tracky-project-modal-backdrop" v-if="projectDetailsModalOpen && selectedProject" @click.self="closeProjectDetails">
                <article class="tracky-project-modal">
                    <header class="tracky-project-modal__head">
                        <div>
                            <h3>{{ selectedProject.name }}</h3>
                            <p>{{ selectedProject.reference }}</p>
                        </div>
                        <div class="tracky-project-modal__head-actions">
                            <button
                                class="tracky-btn tracky-btn--soft"
                                type="button"
                                v-if="canOpenSubmissionWorklist"
                                @click="openSubmissionPage(selectedProject)"
                            >
                                {{ t('projectsPage.goToSubmissions') }}
                            </button>
                            <button
                                class="tracky-btn tracky-btn--ghost"
                                type="button"
                                v-if="canManageProjects"
                                @click="openEditProjectModal(selectedProject)"
                            >
                                {{ t('projectsPage.editProject') }}
                            </button>
                            <button class="tracky-btn tracky-btn--ghost" type="button" @click="closeProjectDetails">
                                {{ t('projectsPage.close') }}
                            </button>
                        </div>
                    </header>

                    <div class="tracky-project-modal__body">
                        <section class="tracky-project-modal__column">
                            <div class="tracky-project-modal__meta-grid">
                                <div>
                                    <span>{{ t('projectsPage.status') }}</span>
                                    <strong>
                                        <span class="badge" :class="selectedProject.status === 'active' ? 'badge--active' : 'badge--archived'">
                                            {{ selectedProject.status === 'active' ? t('dashboard.active') : t('dashboard.archived') }}
                                        </span>
                                    </strong>
                                </div>
                                <div>
                                    <span>{{ t('projectsPage.municipality') }}</span>
                                    <strong>{{ selectedProject.municipality?.name || '-' }}</strong>
                                </div>
                                <div>
                                    <span>{{ t('projectsPage.projectReference') }}</span>
                                    <strong>{{ selectedProject.reference }}</strong>
                                </div>
                                <div>
                                    <span>{{ t('projectsPage.lastUpdated') }}</span>
                                    <strong>{{ formatDateTime(selectedProject.last_update_at) }}</strong>
                                </div>
                            </div>

                            <div class="tracky-project-mini-map">
                                <strong>{{ t('projectsPage.coordinates') }}</strong>
                                <span>
                                    {{ selectedProject.latitude ?? '-' }}, {{ selectedProject.longitude ?? '-' }}
                                </span>
                            </div>

                            <div class="tracky-project-section">
                                <h4>{{ t('dashboard.projectDescription') }}</h4>
                                <p>{{ selectedProject.description || t('dashboard.noDescription') }}</p>
                            </div>

                            <div class="tracky-project-section">
                                <div class="tracky-project-progress-row">
                                    <h4>{{ t('projectsPage.progress') }}</h4>
                                    <strong>{{ selectedProject.progress }}%</strong>
                                </div>
                                <div class="tracky-progress">
                                    <div class="tracky-progress__bar" :style="{ width: `${selectedProject.progress}%` }" />
                                </div>
                            </div>
                        </section>

                        <section class="tracky-project-modal__column">
                            <div class="tracky-project-section">
                                <h4>{{ t('projectsPage.submissionStats') }}</h4>
                                <ul class="tracky-project-stats-list">
                                    <li>
                                        <span>{{ t('projectsPage.totalSubmissions') }}</span>
                                        <strong>{{ selectedProject.stats.total }}</strong>
                                    </li>
                                    <li>
                                        <span>{{ t('projectsPage.approvedSubmissions') }}</span>
                                        <strong>{{ selectedProject.stats.approved }}</strong>
                                    </li>
                                    <li>
                                        <span>{{ t('projectsPage.pendingSubmissions') }}</span>
                                        <strong>{{ selectedProject.stats.pending }}</strong>
                                    </li>
                                    <li>
                                        <span>{{ t('projectsPage.rejectedSubmissions') }}</span>
                                        <strong>{{ selectedProject.stats.rejected }}</strong>
                                    </li>
                                    <li>
                                        <span>{{ t('projectsPage.mediaAttachments') }}</span>
                                        <strong>{{ selectedProject.stats.mediaItems }}</strong>
                                    </li>
                                </ul>
                            </div>

                            <div class="tracky-project-section" v-if="canOpenSubmissionWorklist">
                                <button class="tracky-btn tracky-btn--primary" type="button" @click="openSubmissionPage(selectedProject)">
                                    {{ t('projectsPage.goToSubmissions') }}
                                </button>
                            </div>
                        </section>
                    </div>
                </article>
            </div>

            <div class="modal-backdrop" v-if="projectFormModalOpen" @click.self="closeProjectFormModal">
                <article class="modal-card tracky-form-modal">
                    <h3>{{ editingProjectId ? t('projectsPage.modalEditProject') : t('projectsPage.modalCreateProject') }}</h3>

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
