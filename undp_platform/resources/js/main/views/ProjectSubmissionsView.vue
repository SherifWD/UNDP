<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import AppShell from '../components/AppShell.vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const ui = useUiStore();
const { t } = useI18n();

const loading = ref(false);
const exporting = ref(false);
const error = ref('');
const project = ref(null);
const submissions = ref([]);

const filters = reactive({
    search: '',
    status: '',
});

const pagination = reactive({
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
});

let searchTimer = null;

const canExportCsv = computed(() => auth.hasPermission('reports.export.csv'));
const canExportPdf = computed(() => auth.hasPermission('reports.export.pdf'));

const submissionRows = computed(() => submissions.value || []);

const coveragePercent = computed(() => {
    const total = Number(project.value?.stats?.total_submissions || 0);
    const media = Number(project.value?.stats?.media_attachments || 0);

    if (total <= 0) {
        return 0;
    }

    return Math.max(0, Math.min(100, Math.round((media / total) * 100)));
});

const statusBreakdown = computed(() => {
    const total = Number(project.value?.stats?.total_submissions || 0) || 1;
    const approved = Number(project.value?.stats?.approved_submissions || 0);
    const pending = Number(project.value?.stats?.pending_submissions || 0);
    const rejected = Number(project.value?.stats?.rejected_submissions || 0);

    return {
        approved,
        pending,
        rejected,
        approvedPercent: Math.round((approved / total) * 100),
        pendingPercent: Math.round((pending / total) * 100),
        rejectedPercent: Math.round((rejected / total) * 100),
    };
});

const visiblePages = computed(() => {
    const pages = [];
    const current = pagination.current_page;
    const last = pagination.last_page;

    for (let page = Math.max(1, current - 2); page <= Math.min(last, current + 2); page += 1) {
        pages.push(page);
    }

    return pages;
});

const mediaCount = (submission) => {
    const mediaAssets = Array.isArray(submission?.media_assets) ? submission.media_assets.length : 0;
    const mediaFallback = Array.isArray(submission?.media) ? submission.media.length : 0;
    return mediaAssets || mediaFallback;
};

const goBack = () => {
    router.push({ name: 'projects' });
};

const loadProject = async () => {
    const { data } = await api.get(`/projects/${route.params.id}`);
    project.value = data.project;
};

const loadSubmissions = async (page = pagination.current_page) => {
    const { data } = await api.get('/submissions', {
        params: {
            project_id: route.params.id,
            page,
            per_page: pagination.per_page,
            search: filters.search || undefined,
            status: filters.status || undefined,
        },
    });

    submissions.value = data.data || [];
    pagination.current_page = data.current_page || 1;
    pagination.last_page = data.last_page || 1;
    pagination.total = data.total || submissions.value.length;
};

const loadPage = async (page = pagination.current_page) => {
    loading.value = true;
    error.value = '';

    try {
        await Promise.all([
            loadProject(),
            loadSubmissions(page),
        ]);
    } catch (err) {
        error.value = err.response?.data?.message || t('projectSubmissions.unableToLoad');
        project.value = null;
        submissions.value = [];
    } finally {
        loading.value = false;
    }
};

const loadSubmissionPage = async (page = pagination.current_page) => {
    loading.value = true;
    error.value = '';

    try {
        await loadSubmissions(page);
    } catch (err) {
        error.value = err.response?.data?.message || t('projectSubmissions.unableToLoad');
        submissions.value = [];
    } finally {
        loading.value = false;
    }
};

const openSubmission = (submission) => {
    router.push({
        name: 'submission-detail',
        params: {
            id: submission.id,
        },
    });
};

const goToPage = async (page) => {
    if (page < 1 || page > pagination.last_page || page === pagination.current_page) {
        return;
    }

    await loadSubmissionPage(page);
};

const fileNameFromResponse = (header, fallback) => {
    const match = /filename="?([^";]+)"?/i.exec(header || '');
    return match?.[1] || fallback;
};

const downloadBlob = (response, fallbackName) => {
    const blob = new Blob([response.data]);
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = fileNameFromResponse(response.headers['content-disposition'], fallbackName);
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
};

const exportCsv = async () => {
    if (!canExportCsv.value) {
        return;
    }

    exporting.value = true;

    try {
        const response = await api.get('/exports/csv', {
            params: {
                type: 'submissions',
                project_id: route.params.id,
            },
            responseType: 'blob',
        });

        downloadBlob(response, `project-${route.params.id}-submissions.csv`);
    } catch (err) {
        ui.pushToast(err.response?.data?.message || t('projectSubmissions.unableToExportCsv'), 'error');
    } finally {
        exporting.value = false;
    }
};

const exportPdf = async () => {
    if (!canExportPdf.value) {
        return;
    }

    exporting.value = true;

    try {
        const response = await api.get('/exports/pdf', {
            params: {
                project_id: route.params.id,
            },
            responseType: 'blob',
        });

        downloadBlob(response, `project-${route.params.id}-summary.pdf`);
    } catch (err) {
        ui.pushToast(err.response?.data?.message || t('projectSubmissions.unableToExportPdf'), 'error');
    } finally {
        exporting.value = false;
    }
};

watch(() => route.params.id, async () => {
    await loadPage(1);
});

watch(() => filters.status, async () => {
    await loadSubmissionPage(1);
});

watch(() => filters.search, () => {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(() => {
        loadSubmissionPage(1);
    }, 280);
});

onMounted(async () => {
    await loadPage(1);
});

onBeforeUnmount(() => {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }
});
</script>

<template>
    <AppShell>
        <section class="tracky-project-flow">
            <header class="tracky-project-flow__head">
                <div class="tracky-project-flow__title">
                    <button class="tracky-btn tracky-btn--ghost" type="button" @click="goBack">←</button>
                    <div>
                        <h2>{{ project?.name || t('projectSubmissions.projectFallback') }}</h2>
                        <p>{{ project ? `ID:${project.id}` : t('projectSubmissions.loadingProject') }}</p>
                        <p v-if="project" class="tracky-subtle">
                            {{ t('projectSubmissions.projectStatus') }}:
                            <span class="badge" :class="project.status === 'active' ? 'badge--active' : 'badge--archived'">
                                {{ project.status === 'active' ? t('statusLabels.active') : t('statusLabels.archived') }}
                            </span>
                        </p>
                    </div>
                </div>

                <div class="tracky-project-flow__actions">
                    <select v-model="filters.status">
                        <option value="">{{ t('projectSubmissions.allStatus') }}</option>
                        <option value="submitted">{{ t('statusLabels.submitted') }}</option>
                        <option value="under_review">{{ t('statusLabels.under_review') }}</option>
                        <option value="approved">{{ t('statusLabels.approved') }}</option>
                        <option value="rework_requested">{{ t('statusLabels.rework_requested') }}</option>
                        <option value="rejected">{{ t('statusLabels.rejected') }}</option>
                    </select>
                    <input v-model="filters.search" type="text" :placeholder="t('projectSubmissions.searchPlaceholder')">
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="exporting || !canExportCsv" @click="exportCsv">{{ t('projectSubmissions.exportCsv') }}</button>
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="exporting || !canExportPdf" @click="exportPdf">{{ t('projectSubmissions.exportPdf') }}</button>
                </div>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <section class="tracky-project-kpis" v-if="project">
                <article class="tracky-card tracky-kpi-panel">
                    <h3>{{ t('projectSubmissions.submissionsOverview') }}</h3>
                    <p class="tracky-kpi-panel__value">{{ project.stats?.total_submissions || 0 }}</p>
                    <div class="tracky-kpi-panel__bars">
                        <span :style="{ width: `${statusBreakdown.approvedPercent}%`, background: '#2B8AF0' }"></span>
                        <span :style="{ width: `${statusBreakdown.pendingPercent}%`, background: '#233AA8' }"></span>
                        <span :style="{ width: `${statusBreakdown.rejectedPercent}%`, background: '#7F1A8E' }"></span>
                    </div>
                    <div class="tracky-kpi-panel__legend">
                        <span>{{ t('projectSubmissions.submissionsOverviewLegendApproved', { count: project.stats?.approved_submissions || 0 }) }}</span>
                        <span>{{ t('projectSubmissions.submissionsOverviewLegendPending', { count: project.stats?.pending_submissions || 0 }) }}</span>
                        <span>{{ t('projectSubmissions.submissionsOverviewLegendRejected', { count: project.stats?.rejected_submissions || 0 }) }}</span>
                    </div>
                    <span class="badge badge--active">{{ t('projectSubmissions.activeReporters', { count: project.stats?.active_reporters || 0 }) }}</span>
                </article>

                <article class="tracky-card tracky-kpi-panel">
                    <h3>{{ t('projectSubmissions.pendingActions') }}</h3>
                    <p class="tracky-kpi-panel__value">{{ project.stats?.pending_submissions || 0 }}</p>
                    <div class="tracky-progress">
                        <div class="tracky-progress__bar" :style="{ width: `${Math.min(100, (project.stats?.pending_submissions || 0) * 8)}%` }"></div>
                    </div>
                    <p class="tracky-subtle">{{ t('projectSubmissions.pendingActionsHint') }}</p>
                </article>

                <article class="tracky-card tracky-kpi-panel">
                    <h3>{{ t('projectSubmissions.evidenceCoverage') }}</h3>
                    <p class="tracky-kpi-panel__value">{{ coveragePercent }}%</p>
                    <p class="tracky-subtle">{{ t('projectSubmissions.evidenceCoverageHint', { count: project.stats?.media_attachments || 0 }) }}</p>
                </article>
            </section>

            <section class="tracky-card tracky-project-flow__table-wrap">
                <div class="tracky-projects__empty" v-if="loading">{{ t('projectSubmissions.loadingSubmissions') }}</div>

                <table class="tracky-projects-table" v-else-if="submissionRows.length">
                    <thead>
                    <tr>
                        <th>{{ t('common.reference') }}</th>
                        <th>{{ t('projectSubmissions.reportType') }}</th>
                        <th>{{ t('projectSubmissions.region') }}</th>
                        <th>{{ t('projectSubmissions.mediaAttachments') }}</th>
                        <th>{{ t('common.status') }}</th>
                        <th>{{ t('common.actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr
                        v-for="submission in submissionRows"
                        :key="submission.id"
                        tabindex="0"
                        @click="openSubmission(submission)"
                        @keydown.enter.prevent="openSubmission(submission)"
                        @keydown.space.prevent="openSubmission(submission)"
                    >
                        <td>{{ submission.reporter?.name || `#${submission.id}` }}</td>
                        <td>{{ submission.title }}</td>
                        <td>{{ submission.municipality?.name || '-' }}</td>
                        <td>{{ t('projectSubmissions.attachmentsCount', { count: mediaCount(submission) }) }}</td>
                        <td>
                            <span class="badge" :class="submission.status === 'approved' ? 'badge--active' : 'badge--archived'">
                                {{ submission.status_label }}
                            </span>
                        </td>
                        <td>
                            <button class="tracky-btn tracky-btn--ghost" type="button" @click.stop="openSubmission(submission)">
                                {{ t('projectSubmissions.viewDetails') }}
                            </button>
                        </td>
                    </tr>
                    </tbody>
                </table>

                <div class="tracky-projects__empty" v-else>
                    <h3>{{ t('projectSubmissions.noSubmissionsTitle') }}</h3>
                    <p>{{ t('projectSubmissions.noSubmissionsBody') }}</p>
                </div>
            </section>

            <footer class="tracky-projects__pagination" v-if="!loading && pagination.last_page > 1">
                <p>{{ t('common.page', { page: pagination.current_page, total: pagination.last_page }) }}</p>
                <div class="tracky-page-buttons">
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.current_page <= 1" @click="goToPage(pagination.current_page - 1)">{{ t('common.previous') }}</button>
                    <button
                        v-for="page in visiblePages"
                        :key="page"
                        class="tracky-btn"
                        :class="page === pagination.current_page ? 'tracky-btn--primary' : 'tracky-btn--ghost'"
                        @click="goToPage(page)"
                    >
                        {{ page }}
                    </button>
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.current_page >= pagination.last_page" @click="goToPage(pagination.current_page + 1)">{{ t('common.next') }}</button>
                </div>
            </footer>
        </section>
    </AppShell>
</template>
