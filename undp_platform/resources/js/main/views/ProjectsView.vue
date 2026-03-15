<script setup>
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
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
const projectDetails = ref(null);
const projectReporters = ref([]);
const projectMediaAttachments = ref([]);
const projectFundingRequests = ref([]);
const availableReporters = ref([]);

const projectOptionSets = reactive({
    execution_statuses: [],
    project_categories: [],
    execution_models: [],
    development_goal_areas: [],
    visibility_options: [],
    lifecycle_statuses: [],
});

const loading = ref(false);
const detailLoading = ref(false);
const optionsLoading = ref(false);
const saving = ref(false);
const deleting = ref(false);
const error = ref('');
const projectFundingRequestsError = ref('');
const fundingRequestError = ref('');

const activeMunicipalityTab = ref('all');
const projectDetailsModalOpen = ref(false);
const projectFormModalOpen = ref(false);
const municipalityModalOpen = ref(false);
const editingProjectId = ref(null);
const selectedReporterId = ref('');
const fundingRequestModalOpen = ref(false);
const fundingRequestSubmitting = ref(false);
const fundingRequestTargetProject = ref(null);
const projectFundingRequestsLoading = ref(false);

const fundingReviewNotes = reactive({});
const fundingRequestForm = reactive({
    amount: '',
    reason: '',
});

const detailMapEl = ref(null);
const formMapEl = ref(null);

let searchTimer = null;
let detailMap = null;
let detailMarker = null;
let formMap = null;
let formMarker = null;

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
    project_code: '',
    description: '',
    status: 'active',
    execution_status: 'in_progress',
    project_category: 'Infrastructure - Public Safety',
    region_label: '',
    location_label: '',
    implementing_partner: '',
    program_lead: 'UNDP Libya',
    development_goal_area: 'Public Safety',
    execution_model: 'Government-led implementation with donor support',
    start_date: '',
    end_date: '',
    latitude: '',
    longitude: '',
    objectives_text: '',
    hard_components_text: '',
    soft_components_text: '',
    funding_budget: '',
    funding_sources_text: '',
    funding_types_text: '',
    progress_percent: 0,
    visibility: 'Internal - Admin & Authorized Stakeholders',
    contacts_text: '',
    assigned_reporter_ids: [],
});

const fallbackOptionSets = {
    execution_statuses: [
        { value: 'not_started', label: 'Not Started' },
        { value: 'planned', label: 'Planned' },
        { value: 'in_progress', label: 'In Progress' },
        { value: 'completed', label: 'Completed' },
    ],
    project_categories: [
        { value: 'Infrastructure - Public Safety', label: 'Infrastructure - Public Safety' },
        { value: 'Water / Sanitation', label: 'Water / Sanitation' },
        { value: 'Health Services', label: 'Health Services' },
        { value: 'Education Rehabilitation', label: 'Education Rehabilitation' },
        { value: 'Governance / Capacity Building', label: 'Governance / Capacity Building' },
        { value: 'Economic Recovery', label: 'Economic Recovery' },
    ],
    execution_models: [
        { value: 'Government-led implementation with donor support', label: 'Government-led implementation with donor support' },
        { value: 'Direct implementation by municipal contractor', label: 'Direct implementation by municipal contractor' },
        { value: 'NGO-led delivery partner model', label: 'NGO-led delivery partner model' },
        { value: 'Mixed implementation model', label: 'Mixed implementation model' },
    ],
    development_goal_areas: [
        { value: 'Public Safety', label: 'Public Safety' },
        { value: 'Primary Healthcare', label: 'Primary Healthcare' },
        { value: 'Education Access', label: 'Education Access' },
        { value: 'Water Access', label: 'Water Access' },
        { value: 'Community Resilience', label: 'Community Resilience' },
        { value: 'Local Governance', label: 'Local Governance' },
    ],
    visibility_options: [
        { value: 'Internal - Admin & Authorized Stakeholders', label: 'Internal - Admin & Authorized Stakeholders' },
        { value: 'Municipality-only internal', label: 'Municipality-only internal' },
        { value: 'Shared with donors (summary only)', label: 'Shared with donors (summary only)' },
    ],
    lifecycle_statuses: [
        { value: 'active', label: 'Active' },
        { value: 'archived', label: 'Archived' },
    ],
};

const staticProjectOptionKeyMap = {
    'Not Started': 'not_started',
    Planned: 'planned',
    'In Progress': 'in_progress',
    Completed: 'completed',
    'Infrastructure - Public Safety': 'infrastructure_public_safety',
    'Water / Sanitation': 'water_sanitation',
    'Health Services': 'health_services',
    'Education Rehabilitation': 'education_rehabilitation',
    'Governance / Capacity Building': 'governance_capacity_building',
    'Economic Recovery': 'economic_recovery',
    'Government-led implementation with donor support': 'government_led_donor_support',
    'Direct implementation by municipal contractor': 'direct_municipal_contractor',
    'NGO-led delivery partner model': 'ngo_delivery_partner',
    'Mixed implementation model': 'mixed_model',
    'Public Safety': 'public_safety',
    'Primary Healthcare': 'primary_healthcare',
    'Education Access': 'education_access',
    'Water Access': 'water_access',
    'Community Resilience': 'community_resilience',
    'Local Governance': 'local_governance',
    'Internal - Admin & Authorized Stakeholders': 'internal_authorized',
    'Municipality-only internal': 'municipality_internal',
    'Shared with donors (summary only)': 'donor_summary_only',
};

const canManageMunicipalities = computed(() => auth.hasPermission('municipalities.manage'));
const canManageProjects = computed(() => auth.hasPermission('projects.manage'));
const canRequestFunding = computed(() => auth.hasPermission('funding_requests.create'));
const canReviewFundingRequests = computed(() => auth.hasPermission('funding_requests.review'));
const canViewFundingRequests = computed(() => canRequestFunding.value || canReviewFundingRequests.value);
const donorFundingMode = computed(() => canRequestFunding.value && !canManageProjects.value && !canReviewFundingRequests.value);
const canViewProjectSubmissions = computed(() => (
    auth.hasPermission('submissions.view.own')
    || auth.hasPermission('submissions.view.municipality')
    || auth.hasPermission('submissions.view.all')
    || auth.hasPermission('submissions.view.approved_aggregated')
));

const projectModalTitle = computed(() => (
    editingProjectId.value ? t('projectsPage.editProject') : t('projectsPage.createProject')
));

const reporterDirectory = computed(() => {
    const map = new Map();

    [
        ...availableReporters.value,
        ...projectReporters.value,
        ...(projectDetails.value?.assigned_reporters || []),
    ].forEach((reporter) => {
        map.set(Number(reporter.id), reporter);
    });

    return map;
});

const assignedReporterRecords = computed(() => projectForm.assigned_reporter_ids
    .map((id) => reporterDirectory.value.get(Number(id)) || {
        id,
        name: `${t('roles.reporter')} #${id}`,
        email: '-',
    }));

const availableReporterChoices = computed(() => {
    const selected = new Set(projectForm.assigned_reporter_ids.map((id) => Number(id)));

    return availableReporters.value.filter((reporter) => !selected.has(Number(reporter.id)));
});

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

const normalizeOptionList = (list, fallback = []) => {
    if (Array.isArray(list) && list.length) {
        return list.map((item) => ({
            value: item?.value ?? item,
            label: item?.label ?? item,
        }));
    }

    return fallback;
};

const applyFallbackOptionSets = () => {
    Object.entries(fallbackOptionSets).forEach(([key, value]) => {
        projectOptionSets[key] = value;
    });
};

const projectReference = (project) => project?.code || `PRJ-${String(project?.id || '').padStart(3, '0')}`;
const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : '-');
const formatDateTime = (value) => (value ? new Date(value).toLocaleString() : '-');
const formatCurrency = (value) => `USD ${Number(value || 0).toLocaleString()}`;
const projectOptionLabel = (value) => {
    const normalized = String(value || '').trim();
    if (!normalized) {
        return '-';
    }

    if (['active', 'archived', 'approved', 'declined', 'pending', 'planned', 'completed', 'not_started', 'in_progress'].includes(normalized)) {
        return t(`statusLabels.${normalized}`, normalized.replaceAll('_', ' '));
    }

    const staticKey = staticProjectOptionKeyMap[normalized];
    return staticKey ? t(`projectsPage.optionValues.${staticKey}`) : normalized;
};
const linesToArray = (value) => String(value || '')
    .split('\n')
    .map((row) => row.trim())
    .filter(Boolean);
const arrayToLines = (value) => (Array.isArray(value) ? value.join('\n') : '');

const executionStatusClass = (status) => {
    if (status === 'completed') return 'tracky-status-chip--success';
    if (status === 'in_progress') return 'tracky-status-chip--info';
    if (status === 'planned') return 'tracky-status-chip--warning';
    return 'tracky-status-chip--muted';
};

const lifecycleBadgeClass = (status) => (status === 'active' ? 'badge--active' : 'badge--archived');
const fundingBadgeClass = (status) => (
    status === 'approved'
        ? 'status-pill--active'
        : status === 'declined'
            ? 'status-pill--disabled'
            : ''
);
const canRequestFundingForProject = (project) => canRequestFunding.value && project?.status === 'active';
const fundingRequestButtonClass = (project) => (
    canRequestFundingForProject(project) && donorFundingMode.value
        ? 'tracky-btn--primary'
        : 'tracky-btn--ghost'
);

const openProjectSubmissions = (project) => {
    if (! project || ! canViewProjectSubmissions.value) {
        return;
    }

    router.push({
        name: 'project-submissions',
        params: {
            id: String(project.id),
        },
    });
};

const openFundingRequestModal = (project) => {
    if (! project || ! canRequestFunding.value) {
        return;
    }

    fundingRequestTargetProject.value = project;
    fundingRequestError.value = '';
    fundingRequestForm.amount = '';
    fundingRequestForm.reason = '';
    fundingRequestModalOpen.value = true;
};

const closeFundingRequestModal = () => {
    fundingRequestModalOpen.value = false;
    fundingRequestTargetProject.value = null;
    fundingRequestError.value = '';
    fundingRequestForm.amount = '';
    fundingRequestForm.reason = '';
};

const loadProjectFundingRequests = async (projectId) => {
    if (! canViewFundingRequests.value || ! projectId) {
        projectFundingRequests.value = [];
        return;
    }

    projectFundingRequestsLoading.value = true;
    projectFundingRequestsError.value = '';

    try {
        const { data } = await api.get('/funding-requests', {
            params: {
                project_id: projectId,
                per_page: 100,
                sort_by: 'created_at',
                sort_dir: 'desc',
            },
        });

        projectFundingRequests.value = data.data || [];
    } catch (err) {
        projectFundingRequests.value = [];
        projectFundingRequestsError.value = err.response?.data?.message || t('projectsPage.unableToLoadFundingRequests');
    } finally {
        projectFundingRequestsLoading.value = false;
    }
};

const submitFundingRequest = async () => {
    if (! canRequestFunding.value || ! fundingRequestTargetProject.value) {
        return;
    }

    fundingRequestError.value = '';
    const amount = Number(fundingRequestForm.amount);

    if (! Number.isFinite(amount) || amount <= 0) {
        fundingRequestError.value = t('projectsPage.fundingAmountRequired');
        return;
    }

    fundingRequestSubmitting.value = true;

    try {
        await api.post('/funding-requests', {
            project_id: fundingRequestTargetProject.value.id,
            amount,
            reason: fundingRequestForm.reason || null,
        });

        ui.pushToast(t('projectsPage.fundingRequestSubmitted'));
        const currentProjectId = projectDetails.value?.id;
        closeFundingRequestModal();

        await loadProjects(pagination.current_page);

        if (currentProjectId) {
            await Promise.all([
                loadProjectFundingRequests(currentProjectId),
                fetchProjectDetails(currentProjectId),
            ]);
        }
    } catch (err) {
        fundingRequestError.value = err.response?.data?.message || t('projectsPage.unableToSubmitFundingRequest');
    } finally {
        fundingRequestSubmitting.value = false;
    }
};

const reviewFundingRequest = async (fundingRequest, decision) => {
    if (! canReviewFundingRequests.value || ! fundingRequest?.id) {
        return;
    }

    const note = String(fundingReviewNotes[fundingRequest.id] || '').trim();

    if (! note) {
        projectFundingRequestsError.value = t('projectsPage.fundingReviewReasonRequired');
        return;
    }

    projectFundingRequestsError.value = '';

    try {
        if (decision === 'approve') {
            await api.post(`/funding-requests/${fundingRequest.id}/approve`, {
                review_comment: note,
            });
            ui.pushToast(t('projectsPage.fundingApproved'));
        } else {
            await api.post(`/funding-requests/${fundingRequest.id}/decline`, {
                review_comment: note,
            });
            ui.pushToast(t('projectsPage.fundingDeclined'));
        }

        fundingReviewNotes[fundingRequest.id] = '';

        await loadProjects(pagination.current_page);

        if (projectDetails.value?.id) {
            await Promise.all([
                loadProjectFundingRequests(projectDetails.value.id),
                fetchProjectDetails(projectDetails.value.id),
            ]);
        }
    } catch (err) {
        projectFundingRequestsError.value = err.response?.data?.message || t('projectsPage.unableToReviewFundingRequest');
    }
};

const setMunicipalityTab = async (tabId) => {
    activeMunicipalityTab.value = tabId;
    filters.municipality_id = tabId === 'all' ? '' : String(tabId);
    await loadProjects(1);
};

const loadMunicipalities = async () => {
    try {
        const { data } = await api.get('/municipalities');
        municipalities.value = data.data || [];
    } catch {
        municipalities.value = [];
    }
};

const loadProjectOptions = async (municipalityId = null) => {
    if (! canManageProjects.value) {
        applyFallbackOptionSets();
        availableReporters.value = [];
        return;
    }

    optionsLoading.value = true;

    try {
        const { data } = await api.get('/projects/options', {
            params: {
                municipality_id: municipalityId || undefined,
            },
        });

        const optionSets = data.option_sets || {};

        projectOptionSets.execution_statuses = normalizeOptionList(optionSets.execution_statuses, fallbackOptionSets.execution_statuses);
        projectOptionSets.project_categories = normalizeOptionList(optionSets.project_categories, fallbackOptionSets.project_categories);
        projectOptionSets.execution_models = normalizeOptionList(optionSets.execution_models, fallbackOptionSets.execution_models);
        projectOptionSets.development_goal_areas = normalizeOptionList(optionSets.development_goal_areas, fallbackOptionSets.development_goal_areas);
        projectOptionSets.visibility_options = normalizeOptionList(optionSets.visibility_options, fallbackOptionSets.visibility_options);
        projectOptionSets.lifecycle_statuses = normalizeOptionList(optionSets.lifecycle_statuses, fallbackOptionSets.lifecycle_statuses);
        availableReporters.value = data.available_reporters || [];
    } catch {
        applyFallbackOptionSets();
        availableReporters.value = [];
    } finally {
        optionsLoading.value = false;
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
        error.value = err.response?.data?.message || t('projectsPage.unableToLoadProjects');
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
        projectDetails.value = data.project || null;
        projectReporters.value = data.reporters || [];
        projectMediaAttachments.value = data.media_attachments || [];
    } catch (err) {
        error.value = err.response?.data?.message || t('projectsPage.unableToLoadProjectDetails');
        projectDetails.value = null;
        projectReporters.value = [];
        projectMediaAttachments.value = [];
        throw err;
    } finally {
        detailLoading.value = false;
    }
};

const resetProjectForm = () => {
    Object.assign(projectForm, {
        municipality_id: '',
        name_en: '',
        name_ar: '',
        project_code: '',
        description: '',
        status: 'active',
        execution_status: 'in_progress',
        project_category: 'Infrastructure - Public Safety',
        region_label: '',
        location_label: formatLocationLabel(defaultCenter[0], defaultCenter[1]),
        implementing_partner: '',
        program_lead: 'UNDP Libya',
        development_goal_area: 'Public Safety',
        execution_model: 'Government-led implementation with donor support',
        start_date: '',
        end_date: '',
        latitude: defaultCenter[0],
        longitude: defaultCenter[1],
        objectives_text: '',
        hard_components_text: '',
        soft_components_text: '',
        funding_budget: '',
        funding_sources_text: '',
        funding_types_text: '',
        progress_percent: 0,
        visibility: 'Internal - Admin & Authorized Stakeholders',
        contacts_text: '',
        assigned_reporter_ids: [],
    });

    editingProjectId.value = null;
    selectedReporterId.value = '';
};

const populateProjectForm = (project) => {
    if (! project) {
        return;
    }

    editingProjectId.value = project.id;
    selectedReporterId.value = '';

    Object.assign(projectForm, {
        municipality_id: project.municipality?.id || '',
        name_en: project.name_en || '',
        name_ar: project.name_ar || '',
        project_code: project.code || '',
        description: project.description || '',
        status: project.status || 'active',
        execution_status: project.execution_status || 'in_progress',
        project_category: project.project_category || 'Infrastructure - Public Safety',
        region_label: project.region_label || '',
        location_label: project.location_label || '',
        implementing_partner: project.implementing_partner || '',
        program_lead: project.program_lead || 'UNDP Libya',
        development_goal_area: project.development_goal_area || 'Public Safety',
        execution_model: project.execution_model || 'Government-led implementation with donor support',
        start_date: project.start_date || '',
        end_date: project.end_date || '',
        latitude: project.latitude ?? '',
        longitude: project.longitude ?? '',
        objectives_text: arrayToLines(project.objectives),
        hard_components_text: arrayToLines(project.hard_components),
        soft_components_text: arrayToLines(project.soft_components),
        funding_budget: project.funding_budget ?? '',
        funding_sources_text: arrayToLines(project.funding_sources),
        funding_types_text: arrayToLines(project.funding_types),
        progress_percent: Number(project.progress_percent || 0),
        visibility: project.visibility || 'Internal - Admin & Authorized Stakeholders',
        contacts_text: arrayToLines(project.contacts),
        assigned_reporter_ids: (project.assigned_reporters || projectReporters.value || []).map((reporter) => Number(reporter.id)),
    });
};

const openProjectDetails = async (project) => {
    if (! project) {
        return;
    }

    projectFormModalOpen.value = false;
    projectDetailsModalOpen.value = true;

    try {
        await Promise.all([
            fetchProjectDetails(project.id),
            loadProjectFundingRequests(project.id),
        ]);
        await ensureDetailMap();
    } catch {
        projectDetailsModalOpen.value = false;
    }
};

const closeProjectDetails = () => {
    projectDetailsModalOpen.value = false;
    projectFundingRequests.value = [];
    projectFundingRequestsError.value = '';
};

const openCreateProjectModal = async () => {
    resetProjectForm();
    projectDetailsModalOpen.value = false;
    await loadProjectOptions();
    projectFormModalOpen.value = true;
    await ensureFormMap();
};

const openEditProjectModal = async (project) => {
    if (! project || ! canManageProjects.value) {
        return;
    }

    projectDetailsModalOpen.value = false;

    try {
        await fetchProjectDetails(project.id);
        populateProjectForm(projectDetails.value);
        await loadProjectOptions(projectForm.municipality_id || null);
        projectFormModalOpen.value = true;
        await ensureFormMap();
    } catch {
        projectFormModalOpen.value = false;
    }
};

const closeProjectFormModal = () => {
    projectFormModalOpen.value = false;
    resetProjectForm();
};

const saveMunicipality = async () => {
    if (! canManageMunicipalities.value) {
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
        ui.pushToast(t('projectsPage.municipalitySaved'));
        await loadMunicipalities();
        await loadProjects(1);
    } catch (err) {
        error.value = err.response?.data?.message || t('projectsPage.unableToSaveMunicipality');
    } finally {
        saving.value = false;
    }
};

const addAssignedReporter = () => {
    const reporterId = Number(selectedReporterId.value);

    if (! reporterId || projectForm.assigned_reporter_ids.includes(reporterId)) {
        return;
    }

    projectForm.assigned_reporter_ids = [...projectForm.assigned_reporter_ids, reporterId];
    selectedReporterId.value = '';
};

const removeAssignedReporter = (reporterId) => {
    projectForm.assigned_reporter_ids = projectForm.assigned_reporter_ids.filter((id) => Number(id) !== Number(reporterId));
};

const saveProject = async () => {
    if (! canManageProjects.value) {
        return;
    }

    saving.value = true;
    error.value = '';

    const payload = {
        municipality_id: projectForm.municipality_id ? Number(projectForm.municipality_id) : null,
        name_en: projectForm.name_en,
        name_ar: projectForm.name_ar,
        project_code: projectForm.project_code || null,
        description: projectForm.description || null,
        status: projectForm.status,
        execution_status: projectForm.execution_status,
        project_category: projectForm.project_category || null,
        region_label: projectForm.region_label || null,
        location_label: projectForm.location_label || null,
        implementing_partner: projectForm.implementing_partner || null,
        program_lead: projectForm.program_lead || null,
        development_goal_area: projectForm.development_goal_area || null,
        execution_model: projectForm.execution_model || null,
        start_date: projectForm.start_date || null,
        end_date: projectForm.end_date || null,
        latitude: projectForm.latitude === '' ? null : Number(projectForm.latitude),
        longitude: projectForm.longitude === '' ? null : Number(projectForm.longitude),
        objectives: linesToArray(projectForm.objectives_text),
        hard_components: linesToArray(projectForm.hard_components_text),
        soft_components: linesToArray(projectForm.soft_components_text),
        funding_budget: projectForm.funding_budget === '' ? null : Number(projectForm.funding_budget),
        funding_sources: linesToArray(projectForm.funding_sources_text),
        funding_types: linesToArray(projectForm.funding_types_text),
        progress_percent: Number(projectForm.progress_percent || 0),
        visibility: projectForm.visibility || null,
        contacts: linesToArray(projectForm.contacts_text),
        assigned_reporter_ids: projectForm.assigned_reporter_ids.map((id) => Number(id)),
    };

    try {
        const response = editingProjectId.value
            ? await api.put(`/projects/${editingProjectId.value}`, payload)
            : await api.post('/projects', payload);

        ui.pushToast(editingProjectId.value ? t('projectsPage.projectUpdated') : t('projectsPage.projectCreated'));

        const savedProject = response.data.project || null;
        projectDetails.value = savedProject;
        projectReporters.value = response.data.reporters || savedProject?.assigned_reporters || [];
        projectMediaAttachments.value = [];

        projectFormModalOpen.value = false;
        resetProjectForm();
        await loadProjects(pagination.current_page);

        if (savedProject) {
            projectDetailsModalOpen.value = true;
            await fetchProjectDetails(savedProject.id);
            await ensureDetailMap();
        }
    } catch (err) {
        error.value = err.response?.data?.message || t('projectsPage.unableToSaveProject');
    } finally {
        saving.value = false;
    }
};

const deleteProject = async () => {
    if (! projectDetails.value || ! canManageProjects.value) {
        return;
    }

    const confirmed = window.confirm(t('projectsPage.deleteProjectConfirm', { name: projectDetails.value.name }));

    if (! confirmed) {
        return;
    }

    deleting.value = true;
    error.value = '';

    try {
        await api.delete(`/projects/${projectDetails.value.id}`);
        ui.pushToast(t('projectsPage.projectDeleted'));
        projectDetailsModalOpen.value = false;
        projectDetails.value = null;
        projectReporters.value = [];
        projectMediaAttachments.value = [];
        await loadProjects(1);
    } catch (err) {
        error.value = err.response?.data?.message || t('projectsPage.unableToDeleteProject');
    } finally {
        deleting.value = false;
    }
};

const goToPage = async (page) => {
    if (typeof page !== 'number' || page === pagination.current_page || page < 1 || page > pagination.last_page) {
        return;
    }

    await loadProjects(page);
};

const defaultCenter = [26.3351, 17.2283];

const formatLocationLabel = (latitude, longitude) => `Selected location (${latitude}, ${longitude})`;

const resolvedLatLng = (latitude, longitude) => {
    const lat = Number(latitude);
    const lng = Number(longitude);

    if (Number.isFinite(lat) && Number.isFinite(lng)) {
        return [lat, lng];
    }

    return defaultCenter;
};

const ensureTileLayer = (map) => {
    if (map.__trackyTilesBound) {
        return;
    }

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    map.__trackyTilesBound = true;
};

const syncDetailMap = async () => {
    if (! projectDetailsModalOpen.value || ! projectDetails.value) {
        return;
    }

    await nextTick();

    if (! detailMapEl.value) {
        return;
    }

    if (! detailMap) {
        detailMap = L.map(detailMapEl.value, {
            zoomControl: false,
            attributionControl: false,
            scrollWheelZoom: false,
        });
        ensureTileLayer(detailMap);
    }

    const [lat, lng] = resolvedLatLng(projectDetails.value.latitude, projectDetails.value.longitude);

    detailMap.setView([lat, lng], Number.isFinite(Number(projectDetails.value.latitude)) ? 13 : 6);

    if (! detailMarker) {
        detailMarker = L.circleMarker([lat, lng], {
            radius: 10,
            color: '#1730df',
            fillColor: '#1730df',
            fillOpacity: 0.24,
            weight: 3,
        }).addTo(detailMap);
    } else {
        detailMarker.setLatLng([lat, lng]);
    }

    detailMap.invalidateSize();
};

const syncFormMap = async () => {
    if (! projectFormModalOpen.value) {
        return;
    }

    await nextTick();

    if (! formMapEl.value) {
        return;
    }

    if (! formMap) {
        formMap = L.map(formMapEl.value, {
            zoomControl: true,
            attributionControl: false,
        });
        ensureTileLayer(formMap);
        formMap.on('click', (event) => {
            projectForm.latitude = Number(event.latlng.lat.toFixed(6));
            projectForm.longitude = Number(event.latlng.lng.toFixed(6));

            projectForm.location_label = formatLocationLabel(projectForm.latitude, projectForm.longitude);

            syncFormMap();
        });
    }

    const [lat, lng] = resolvedLatLng(projectForm.latitude, projectForm.longitude);
    const hasPoint = Number.isFinite(Number(projectForm.latitude)) && Number.isFinite(Number(projectForm.longitude));

    formMap.setView([lat, lng], hasPoint ? 13 : 6);

    if (! formMarker) {
        formMarker = L.circleMarker([lat, lng], {
            radius: 10,
            color: '#1730df',
            fillColor: '#1730df',
            fillOpacity: 0.24,
            weight: 3,
        }).addTo(formMap);
    } else {
        formMarker.setLatLng([lat, lng]);
    }

    formMap.invalidateSize();
};

const ensureDetailMap = async () => {
    await syncDetailMap();
};

const ensureFormMap = async () => {
    await syncFormMap();
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

watch(() => projectForm.municipality_id, async (value) => {
    if (! projectFormModalOpen.value) {
        return;
    }

    await loadProjectOptions(value || null);

    const allowed = new Set(availableReporters.value.map((reporter) => Number(reporter.id)));
    projectForm.assigned_reporter_ids = projectForm.assigned_reporter_ids.filter((id) => allowed.has(Number(id)));

    const selectedMunicipality = municipalities.value.find((municipality) => Number(municipality.id) === Number(value));

    if (selectedMunicipality && ! projectForm.region_label) {
        projectForm.region_label = `Region / ${selectedMunicipality.name}`;
    }

    if (selectedMunicipality && ! projectForm.implementing_partner) {
        projectForm.implementing_partner = selectedMunicipality.name;
    }
});

watch(() => [projectForm.latitude, projectForm.longitude], async () => {
    if (projectFormModalOpen.value) {
        await syncFormMap();
    }
});

watch(() => [projectDetails.value?.latitude, projectDetails.value?.longitude], async () => {
    if (projectDetailsModalOpen.value) {
        await syncDetailMap();
    }
});

watch(projectDetailsModalOpen, async (isOpen) => {
    if (isOpen) {
        await syncDetailMap();
        return;
    }

    if (detailMap) {
        detailMap.remove();
        detailMap = null;
        detailMarker = null;
    }
});

watch(projectFormModalOpen, async (isOpen) => {
    if (isOpen) {
        await syncFormMap();
        return;
    }

    if (formMap) {
        formMap.remove();
        formMap = null;
        formMarker = null;
    }
});

onMounted(async () => {
    applyFallbackOptionSets();
    await loadMunicipalities();

    if (canManageProjects.value) {
        await loadProjectOptions();
    }

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

    if (detailMap) {
        detailMap.remove();
        detailMap = null;
        detailMarker = null;
    }

    if (formMap) {
        formMap.remove();
        formMap = null;
        formMarker = null;
    }
});
</script>

<template>
    <AppShell>
        <section class="tracky-projects">
            <header class="tracky-projects__head">
                <div>
                    <h2>{{ t('projectsPage.title') }}</h2>
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
                    <table class="tracky-projects-table tracky-projects-table--rich">
                        <thead>
                        <tr>
                            <th>{{ t('common.project') }}</th>
                            <th>{{ t('common.reference') }}</th>
                            <th>{{ t('projectsPage.municipality') }}</th>
                            <th>{{ t('projectsPage.progress') }}</th>
                            <th>{{ t('projectsPage.assignedReporters') }}</th>
                            <th>{{ t('projectsPage.execution') }}</th>
                            <th v-if="canViewFundingRequests">{{ t('projectsPage.fundingRequests') }}</th>
                            <th>{{ t('projectsPage.tableColumns.actions') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="project in projects" :key="project.id" @click="openProjectDetails(project)">
                            <td>
                                <strong>{{ project.name }}</strong>
                                <p>{{ project.project_category }}</p>
                            </td>
                            <td>
                                <strong>{{ projectReference(project) }}</strong>
                                <p>{{ project.location_label || '-' }}</p>
                            </td>
                            <td>
                                <strong>{{ project.municipality?.name || '-' }}</strong>
                                <p>{{ project.region_label || '-' }}</p>
                            </td>
                            <td>
                                <div class="tracky-project-progress-row">
                                    <small>{{ project.progress_percent || 0 }}%</small>
                                    <small>{{ t('projectsPage.approvedCount', { count: project.stats?.approved_submissions || 0 }) }}</small>
                                </div>
                                <div class="tracky-project-inline-progress">
                                    <span :style="{ width: `${project.progress_percent || 0}%` }" />
                                </div>
                            </td>
                            <td>
                                <strong>{{ project.assigned_reporters_count || 0 }}</strong>
                                <p>{{ t('projectsPage.activeCount', { count: project.stats?.active_reporters || project.assigned_reporters_count || 0 }) }}</p>
                            </td>
                            <td>
                                <span class="tracky-status-chip" :class="executionStatusClass(project.execution_status)">
                                    {{ projectOptionLabel(project.execution_status) }}
                                </span>
                                <p>
                                    <span class="badge" :class="lifecycleBadgeClass(project.status)">
                                        {{ project.status === 'active' ? t('dashboard.active') : t('dashboard.archived') }}
                                    </span>
                                </p>
                            </td>
                            <td v-if="canViewFundingRequests">
                                <template v-if="project.funding_requests_summary">
                                    <strong>{{ formatCurrency(project.funding_requests_summary.total_requested_amount) }}</strong>
                                    <p>
                                        {{ t('projectsPage.requestsSummary', { count: project.funding_requests_summary.total_requests || 0 }) }}
                                        <span v-if="project.funding_requests_summary.pending_requests">
                                            | {{ t('projectsPage.pendingCount', { count: project.funding_requests_summary.pending_requests }) }}
                                        </span>
                                    </p>
                                    <p v-if="project.funding_requests_summary.latest_requested_at">
                                        {{ t('projectsPage.lastRequest', { date: formatDate(project.funding_requests_summary.latest_requested_at) }) }}
                                    </p>
                                </template>
                                <p v-else>{{ t('projectsPage.noRequestsYet') }}</p>
                                <button
                                    v-if="canRequestFundingForProject(project)"
                                    class="tracky-btn"
                                    :class="fundingRequestButtonClass(project)"
                                    type="button"
                                    @click.stop="openFundingRequestModal(project)"
                                >
                                    {{ t('projectsPage.requestToFund') }}
                                </button>
                            </td>
                            <td>
                                <div class="tracky-project-actions" @click.stop>
                                    <button
                                        class="tracky-btn tracky-btn--ghost"
                                        type="button"
                                        v-if="canViewProjectSubmissions"
                                        @click="openProjectSubmissions(project)"
                                    >
                                        {{ t('projectsPage.viewSubmission') }}
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
                    <p>{{ t('projectsPage.noProjectsHint') }}</p>
                </div>
            </section>

            <footer class="tracky-projects__pagination" v-if="!loading && pagination.last_page > 1">
                <p>{{ t('common.page', { page: pagination.current_page, total: pagination.last_page }) }}</p>
                <div class="tracky-page-buttons">
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.current_page <= 1" @click="goToPage(pagination.current_page - 1)">
                        {{ t('common.previous') }}
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
                        {{ t('common.next') }}
                    </button>
                </div>
            </footer>

            <div class="tracky-project-modal-backdrop" v-if="projectDetailsModalOpen" @click.self="closeProjectDetails">
                <article class="tracky-project-modal">
                    <header class="tracky-project-modal__head">
                        <div>
                            <h3>{{ detailLoading ? t('projectsPage.loadingProject') : projectDetails?.name || t('projectsPage.projectDetails') }}</h3>
                            <p v-if="projectDetails">{{ projectReference(projectDetails) }}</p>
                        </div>
                        <div class="tracky-project-modal__head-actions">
                            <button
                                class="tracky-btn tracky-btn--soft"
                                type="button"
                                v-if="projectDetails && canViewProjectSubmissions"
                                @click="openProjectSubmissions(projectDetails)"
                            >
                                {{ t('projectsPage.goToSubmissions') }}
                            </button>
                            <button
                                class="tracky-btn"
                                :class="fundingRequestButtonClass(projectDetails)"
                                type="button"
                                v-if="projectDetails && canRequestFundingForProject(projectDetails)"
                                @click="openFundingRequestModal(projectDetails)"
                            >
                                {{ t('projectsPage.requestToFund') }}
                            </button>
                            <button
                                class="tracky-btn tracky-btn--ghost"
                                type="button"
                                v-if="projectDetails && canManageProjects"
                                @click="openEditProjectModal(projectDetails)"
                            >
                                {{ t('projectsPage.editProject') }}
                            </button>
                            <button
                                class="tracky-btn tracky-btn--danger"
                                type="button"
                                v-if="projectDetails && canManageProjects"
                                :disabled="deleting"
                                @click="deleteProject"
                            >
                                {{ t('projectsPage.deleteProject') }}
                            </button>
                            <button class="tracky-btn tracky-btn--ghost" type="button" @click="closeProjectDetails">{{ t('common.close') }}</button>
                        </div>
                    </header>

                    <div class="tracky-project-modal__body tracky-project-modal__body--rich" v-if="projectDetails && !detailLoading">
                        <section class="tracky-project-modal__column">
                            <div class="tracky-project-view-grid">
                                <div>
                                    <span>{{ t('projectsPage.projectStatus') }}</span>
                                    <strong>
                                        <span class="tracky-status-chip" :class="executionStatusClass(projectDetails.execution_status)">
                                            {{ projectOptionLabel(projectDetails.execution_status) }}
                                        </span>
                                    </strong>
                                </div>
                                <div>
                                    <span>{{ t('projectsPage.projectCategory') }}</span>
                                    <strong>{{ projectOptionLabel(projectDetails.project_category) }}</strong>
                                </div>
                                <div>
                                    <span>{{ t('projectsPage.regionMunicipality') }}</span>
                                    <strong>{{ projectDetails.region_label }}</strong>
                                </div>
                                <div>
                                    <span>{{ t('projectsPage.projectLocation') }}</span>
                                    <strong>{{ projectDetails.location_label }}</strong>
                                </div>
                            </div>

                            <div class="tracky-project-map-card">
                                <div ref="detailMapEl" class="tracky-project-map-canvas" />
                            </div>

                            <div class="tracky-project-section">
                                <h4>{{ t('projectsPage.projectDescription') }}</h4>
                                <p>{{ projectDetails.description || t('dashboard.noDescription') }}</p>
                            </div>

                            <div class="tracky-project-section">
                                <h4>{{ t('projectsPage.projectObjectives') }}</h4>
                                <ul class="tracky-detail-bullet-list">
                                    <li v-for="(objective, index) in projectDetails.objectives" :key="`objective-${index}`">{{ objective }}</li>
                                    <li v-if="!projectDetails.objectives?.length">{{ t('projectsPage.noObjectives') }}</li>
                                </ul>
                            </div>

                            <div class="tracky-project-section">
                                <div class="tracky-project-progress-row">
                                    <h4>{{ t('projectsPage.progress') }}</h4>
                                    <strong>{{ projectDetails.progress_percent || 0 }}%</strong>
                                </div>
                                <div class="tracky-progress">
                                    <div class="tracky-progress__bar" :style="{ width: `${projectDetails.progress_percent || 0}%` }" />
                                </div>
                            </div>

                            <div class="tracky-project-section">
                                <h4>{{ t('projectsPage.projectComponents') }}</h4>
                                <div class="tracky-project-split-card">
                                    <div>
                                        <strong>{{ t('projectsPage.hardComponents') }}</strong>
                                        <ul class="tracky-detail-bullet-list">
                                            <li v-for="(item, index) in projectDetails.hard_components" :key="`hard-${index}`">{{ item }}</li>
                                            <li v-if="!projectDetails.hard_components?.length">{{ t('projectsPage.noHardComponents') }}</li>
                                        </ul>
                                    </div>
                                    <div>
                                        <strong>{{ t('projectsPage.softComponents') }}</strong>
                                        <ul class="tracky-detail-bullet-list">
                                            <li v-for="(item, index) in projectDetails.soft_components" :key="`soft-${index}`">{{ item }}</li>
                                            <li v-if="!projectDetails.soft_components?.length">{{ t('projectsPage.noSoftComponents') }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="tracky-project-modal__column">
                            <div class="tracky-project-section tracky-project-section--no-divider">
                                <h4>{{ t('projectsPage.implementingPartnerTitle') }}</h4>
                                <ul class="tracky-project-stats-list">
                                    <li><span>{{ t('projectsPage.implementingPartner') }}</span><strong>{{ projectDetails.implementing_partner || '-' }}</strong></li>
                                    <li><span>{{ t('projectsPage.programLead') }}</span><strong>{{ projectDetails.program_lead || '-' }}</strong></li>
                                    <li><span>{{ t('projectsPage.developmentGoalArea') }}</span><strong>{{ projectOptionLabel(projectDetails.development_goal_area) }}</strong></li>
                                    <li><span>{{ t('projectsPage.executionModel') }}</span><strong>{{ projectOptionLabel(projectDetails.execution_model) }}</strong></li>
                                    <li><span>{{ t('projectsPage.startEnd') }}</span><strong>{{ projectDetails.date_range_label || '-' }}</strong></li>
                                    <li><span>{{ t('projectsPage.duration') }}</span><strong>{{ projectDetails.duration_label || '-' }}</strong></li>
                                </ul>
                            </div>

                            <div class="tracky-project-section">
                                <h4>{{ t('projectsPage.mediaAttachments') }}</h4>
                                <div class="tracky-project-media-grid">
                                    <div class="tracky-project-media-tile" v-for="asset in projectMediaAttachments" :key="asset.id">
                                        <strong>{{ asset.media_type === 'video' ? t('common.video') : t('common.image') }}</strong>
                                        <small>#{{ asset.id }}</small>
                                        <span>{{ t(`statusLabels.${asset.status}`, asset.status) }}</span>
                                    </div>
                                    <div class="tracky-project-media-empty" v-if="!projectMediaAttachments.length">
                                        {{ t('projectsPage.noUploadedMediaYet') }}
                                    </div>
                                </div>
                            </div>

                            <div class="tracky-project-section">
                                <h4>{{ t('projectsPage.fundingInformation') }}</h4>
                                <div class="tracky-project-funding-card">
                                    <div>
                                        <span>{{ t('projectsPage.totalApprovedBudget') }}</span>
                                        <strong>{{ projectDetails.funding_budget_label || formatCurrency(projectDetails.funding_budget) }}</strong>
                                    </div>
                                    <div>
                                        <span>{{ t('projectsPage.fundingSources') }}</span>
                                        <ul class="tracky-detail-bullet-list">
                                            <li v-for="(item, index) in projectDetails.funding_sources" :key="`source-${index}`">{{ item }}</li>
                                            <li v-if="!projectDetails.funding_sources?.length">{{ t('projectsPage.noFundingSources') }}</li>
                                        </ul>
                                    </div>
                                    <div>
                                        <span>{{ t('projectsPage.fundingType') }}</span>
                                        <ul class="tracky-detail-bullet-list">
                                            <li v-for="(item, index) in projectDetails.funding_types" :key="`type-${index}`">{{ item }}</li>
                                            <li v-if="!projectDetails.funding_types?.length">{{ t('projectsPage.noFundingTypes') }}</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="tracky-project-section" v-if="canViewFundingRequests">
                                <h4>{{ t('projectsPage.projectFundingRequests') }}</h4>
                                <p class="field-error" v-if="projectFundingRequestsError">{{ projectFundingRequestsError }}</p>
                                <p class="panel__hint" v-if="canRequestFunding">
                                    {{ t('projectsPage.donorFundingHint') }}
                                </p>

                                <div
                                    v-if="projectDetails?.funding_requests_summary"
                                    class="tracky-project-funding-summary"
                                >
                                    <article class="tracky-project-funding-summary__card">
                                        <span>{{ t('projectsPage.totalRequested') }}</span>
                                        <strong>{{ formatCurrency(projectDetails.funding_requests_summary.total_requested_amount) }}</strong>
                                    </article>
                                    <article class="tracky-project-funding-summary__card">
                                        <span>{{ t('projectsPage.totalRequests') }}</span>
                                        <strong>{{ projectDetails.funding_requests_summary.total_requests || 0 }}</strong>
                                    </article>
                                    <article class="tracky-project-funding-summary__card">
                                        <span>{{ t('projectsPage.pendingReview') }}</span>
                                        <strong>{{ projectDetails.funding_requests_summary.pending_requests || 0 }}</strong>
                                    </article>
                                    <article class="tracky-project-funding-summary__card">
                                        <span>{{ t('projectsPage.approvedDeclined') }}</span>
                                        <strong>
                                            {{ projectDetails.funding_requests_summary.approved_requests || 0 }}
                                            /
                                            {{ projectDetails.funding_requests_summary.declined_requests || 0 }}
                                        </strong>
                                    </article>
                                </div>

                                <div class="inline-group" v-if="projectDetails && canRequestFundingForProject(projectDetails)">
                                    <button class="btn btn--primary" type="button" @click="openFundingRequestModal(projectDetails)">
                                        {{ t('projectsPage.requestToFundThisProject') }}
                                    </button>
                                </div>

                                <div class="table-wrap">
                                    <table class="table" v-if="!projectFundingRequestsLoading && projectFundingRequests.length">
                                        <thead>
                                        <tr>
                                            <th>{{ t('common.id') }}</th>
                                            <th>{{ t('dashboard.donors') }}</th>
                                            <th>{{ t('common.amount') }}</th>
                                            <th>{{ t('common.status') }}</th>
                                            <th>{{ t('reportsPage.requestReason') }}</th>
                                            <th>{{ t('reportsPage.reviewReason') }}</th>
                                            <th>{{ t('reportsPage.requested') }}</th>
                                            <th v-if="canReviewFundingRequests">{{ t('common.actions') }}</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <tr v-for="requestRow in projectFundingRequests" :key="requestRow.id">
                                            <td>#{{ requestRow.id }}</td>
                                            <td>{{ requestRow.donor?.name || '-' }}</td>
                                            <td>{{ requestRow.currency }} {{ Number(requestRow.amount || 0).toLocaleString() }}</td>
                                            <td>
                                                <span
                                                    class="status-pill"
                                                    :class="fundingBadgeClass(requestRow.status)"
                                                >
                                                    {{ t(`statusLabels.${requestRow.status}`, requestRow.status_label) }}
                                                </span>
                                            </td>
                                            <td>{{ requestRow.reason || '-' }}</td>
                                            <td>{{ requestRow.review_comment || '-' }}</td>
                                            <td>{{ formatDateTime(requestRow.created_at) }}</td>
                                            <td v-if="canReviewFundingRequests">
                                                <div v-if="requestRow.status === 'pending'" class="inline-group">
                                                    <input
                                                        v-model="fundingReviewNotes[requestRow.id]"
                                                        type="text"
                                                        :placeholder="t('reportsPage.reviewReason')"
                                                    >
                                                    <button class="btn btn--primary" type="button" @click="reviewFundingRequest(requestRow, 'approve')">
                                                        {{ t('submissionDetail.approve') }}
                                                    </button>
                                                    <button class="btn btn--danger" type="button" @click="reviewFundingRequest(requestRow, 'decline')">
                                                        {{ t('submissionDetail.reject') }}
                                                    </button>
                                                </div>
                                                <span v-else>{{ t('common.reviewed') }}</span>
                                            </td>
                                        </tr>
                                        </tbody>
                                    </table>
                                    <p class="panel__hint" v-else-if="projectFundingRequestsLoading">{{ t('reportsPage.loadingFundingRequests') }}</p>
                                    <p class="panel__hint" v-else>{{ t('projectsPage.noFundingRequests') }}</p>
                                </div>
                            </div>

                            <div class="tracky-project-section">
                                <h4>{{ t('projectsPage.shareProject', { name: projectDetails.name }) }}</h4>
                                <div class="tracky-share-list">
                                    <div class="tracky-share-row" v-for="reporter in projectReporters" :key="reporter.id">
                                        <div>
                                            <strong>{{ reporter.name }}</strong>
                                            <p>{{ reporter.email || '-' }}</p>
                                        </div>
                                        <span class="tracky-share-badge">{{ t('projectsPage.assigned') }}</span>
                                    </div>
                                    <div class="tracky-project-media-empty" v-if="!projectReporters.length">
                                        {{ t('projectsPage.noReportersAssigned') }}
                                    </div>
                                </div>
                            </div>

                            <div class="tracky-project-section">
                                <h4>{{ t('projectsPage.administrativeMetadata') }}</h4>
                                <ul class="tracky-project-stats-list">
                                    <li><span>{{ t('projectsPage.createdBy') }}</span><strong>{{ projectDetails.created_by_label || '-' }}</strong></li>
                                    <li><span>{{ t('projectsPage.lastUpdated') }}</span><strong>{{ formatDate(projectDetails.updated_at || projectDetails.last_update_at) }}</strong></li>
                                    <li><span>{{ t('projectsPage.visibility') }}</span><strong>{{ projectOptionLabel(projectDetails.visibility) }}</strong></li>
                                </ul>
                            </div>
                        </section>
                    </div>

                    <div class="tracky-projects__empty" v-else-if="detailLoading">{{ t('projectsPage.loadingProjectDetails') }}</div>
                </article>
            </div>

            <div class="tracky-project-modal-backdrop" v-if="projectFormModalOpen" @click.self="closeProjectFormModal">
                <article class="tracky-project-modal tracky-project-modal--editor">
                    <header class="tracky-project-modal__head">
                        <div>
                            <h3>{{ projectModalTitle }}</h3>
                            <p>{{ editingProjectId ? t('projectsPage.formEditHint') : t('projectsPage.formCreateHint') }}</p>
                        </div>
                        <div class="tracky-project-modal__head-actions">
                            <button class="tracky-btn tracky-btn--primary" type="button" :disabled="saving" @click="saveProject">
                                {{ t('common.save') }}
                            </button>
                            <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="saving" @click="closeProjectFormModal">
                                {{ t('common.cancel') }}
                            </button>
                        </div>
                    </header>

                    <div class="tracky-project-modal__body tracky-project-modal__body--editor">
                        <section class="tracky-project-modal__column">
                            <div class="tracky-editor-grid">
                                <label class="field">
                                    {{ t('projectsPage.projectNameEn') }}
                                    <input v-model="projectForm.name_en" type="text">
                                </label>
                                <label class="field">
                                    {{ t('projectsPage.projectNameAr') }}
                                    <input v-model="projectForm.name_ar" type="text">
                                </label>
                                <label class="field">
                                    {{ t('projectsPage.projectReference') }}
                                    <input v-model="projectForm.project_code" type="text" placeholder="PRJ-ALK-001">
                                </label>
                                <label class="field">
                                    {{ t('projectsPage.recordStatus') }}
                                    <select v-model="projectForm.status">
                                        <option v-for="option in projectOptionSets.lifecycle_statuses" :key="option.value" :value="option.value">
                                            {{ projectOptionLabel(option.value || option.label) }}
                                        </option>
                                    </select>
                                </label>
                                <label class="field">
                                    {{ t('projectsPage.projectStatus') }}
                                    <select v-model="projectForm.execution_status">
                                        <option v-for="option in projectOptionSets.execution_statuses" :key="option.value" :value="option.value">
                                            {{ projectOptionLabel(option.value || option.label) }}
                                        </option>
                                    </select>
                                </label>
                                <label class="field">
                                    {{ t('projectsPage.projectCategory') }}
                                    <select v-model="projectForm.project_category">
                                        <option v-for="option in projectOptionSets.project_categories" :key="option.value" :value="option.value">
                                            {{ projectOptionLabel(option.value || option.label) }}
                                        </option>
                                    </select>
                                </label>
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
                                    {{ t('projectsPage.regionMunicipality') }}
                                    <input v-model="projectForm.region_label" type="text" :placeholder="t('projectsPage.regionMunicipalityPlaceholder')">
                                </label>
                                <label class="field field--span-2">
                                    {{ t('projectsPage.projectLocationLabel') }}
                                    <input
                                        :value="projectForm.location_label"
                                        type="text"
                                        :placeholder="t('projectsPage.projectLocationPlaceholder')"
                                        readonly
                                    >
                                </label>
                            </div>

                            <div class="tracky-project-map-card tracky-project-map-card--editable">
                                <div ref="formMapEl" class="tracky-project-map-canvas tracky-project-map-canvas--editor" />
                                <div class="tracky-project-coordinates">
                                    <span>{{ t('projectsPage.latitude') }}: <strong>{{ projectForm.latitude === '' ? '-' : projectForm.latitude }}</strong></span>
                                    <span>{{ t('projectsPage.longitude') }}: <strong>{{ projectForm.longitude === '' ? '-' : projectForm.longitude }}</strong></span>
                                </div>
                            </div>

                            <label class="field">
                                {{ t('projectsPage.projectDescription') }}
                                <textarea v-model="projectForm.description" rows="4"></textarea>
                            </label>

                            <label class="field">
                                {{ t('projectsPage.projectObjectives') }}
                                <textarea v-model="projectForm.objectives_text" rows="4" :placeholder="t('projectsPage.objectivesPlaceholder')"></textarea>
                            </label>

                            <div class="tracky-editor-grid">
                                <label class="field">
                                    {{ t('projectsPage.hardComponents') }}
                                    <textarea v-model="projectForm.hard_components_text" rows="5" :placeholder="t('projectsPage.hardComponentsPlaceholder')"></textarea>
                                </label>
                                <label class="field">
                                    {{ t('projectsPage.softComponents') }}
                                    <textarea v-model="projectForm.soft_components_text" rows="5" :placeholder="t('projectsPage.softComponentsPlaceholder')"></textarea>
                                </label>
                            </div>

                            <label class="field">
                                Progress
                                <div class="tracky-progress-input">
                                    <input v-model="projectForm.progress_percent" type="range" min="0" max="100">
                                    <strong>{{ Number(projectForm.progress_percent || 0) }}%</strong>
                                </div>
                            </label>
                        </section>

                        <section class="tracky-project-modal__column">
                            <div class="tracky-editor-grid">
                                <label class="field">
                                    {{ t('projectsPage.implementingPartner') }}
                                    <input v-model="projectForm.implementing_partner" type="text">
                                </label>
                                <label class="field">
                                    {{ t('projectsPage.programLead') }}
                                    <input v-model="projectForm.program_lead" type="text">
                                </label>
                                <label class="field">
                                    {{ t('projectsPage.developmentGoalArea') }}
                                    <select v-model="projectForm.development_goal_area">
                                        <option v-for="option in projectOptionSets.development_goal_areas" :key="option.value" :value="option.value">
                                            {{ projectOptionLabel(option.value || option.label) }}
                                        </option>
                                    </select>
                                </label>
                                <label class="field">
                                    {{ t('projectsPage.executionModel') }}
                                    <select v-model="projectForm.execution_model">
                                        <option v-for="option in projectOptionSets.execution_models" :key="option.value" :value="option.value">
                                            {{ projectOptionLabel(option.value || option.label) }}
                                        </option>
                                    </select>
                                </label>
                                <label class="field">
                                    {{ t('projectsPage.startDate') }}
                                    <input v-model="projectForm.start_date" type="date">
                                </label>
                                <label class="field">
                                    {{ t('projectsPage.endDate') }}
                                    <input v-model="projectForm.end_date" type="date">
                                </label>
                            </div>

                            <label class="field">
                                {{ t('projectsPage.totalApprovedBudget') }}
                                <input v-model="projectForm.funding_budget" type="number" min="0" step="1">
                            </label>

                            <div class="tracky-editor-grid">
                                <label class="field">
                                    {{ t('projectsPage.fundingSources') }}
                                    <textarea v-model="projectForm.funding_sources_text" rows="4" :placeholder="t('projectsPage.fundingSourcesPlaceholder')"></textarea>
                                </label>
                                <label class="field">
                                    {{ t('projectsPage.fundingType') }}
                                    <textarea v-model="projectForm.funding_types_text" rows="4" :placeholder="t('projectsPage.fundingTypesPlaceholder')"></textarea>
                                </label>
                            </div>

                            <label class="field">
                                {{ t('projectsPage.contactNumbers') }}
                                <textarea v-model="projectForm.contacts_text" rows="3" :placeholder="t('projectsPage.contactsPlaceholder')"></textarea>
                            </label>

                            <label class="field">
                                {{ t('projectsPage.visibility') }}
                                <select v-model="projectForm.visibility">
                                    <option v-for="option in projectOptionSets.visibility_options" :key="option.value" :value="option.value">
                                        {{ projectOptionLabel(option.value || option.label) }}
                                    </option>
                                </select>
                            </label>

                            <section class="tracky-project-section">
                                <h4>{{ t('projectsPage.shareThisProject', { name: projectForm.name_en || t('projectsPage.projectLabel') }) }}</h4>
                                <div class="tracky-assign-row">
                                    <select v-model="selectedReporterId" :disabled="optionsLoading || !availableReporterChoices.length">
                                        <option value="">{{ t('projectsPage.addUsers') }}</option>
                                        <option v-for="reporter in availableReporterChoices" :key="reporter.id" :value="reporter.id">
                                            {{ reporter.name }}{{ reporter.email ? ` (${reporter.email})` : '' }}
                                        </option>
                                    </select>
                                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="!selectedReporterId" @click="addAssignedReporter">
                                        {{ t('projectsPage.invite') }}
                                    </button>
                                </div>
                                <div class="tracky-share-list">
                                    <div class="tracky-share-row" v-for="reporter in assignedReporterRecords" :key="reporter.id">
                                        <div>
                                            <strong>{{ reporter.name }}</strong>
                                            <p>{{ reporter.email || '-' }}</p>
                                        </div>
                                        <button class="tracky-icon-btn" type="button" @click="removeAssignedReporter(reporter.id)">
                                            {{ t('projectsPage.remove') }}
                                        </button>
                                    </div>
                                    <div class="tracky-project-media-empty" v-if="!assignedReporterRecords.length">
                                        {{ t('projectsPage.noReportersAssignedYet') }}
                                    </div>
                                </div>
                            </section>
                        </section>
                    </div>
                </article>
            </div>

            <div class="modal-backdrop" v-if="fundingRequestModalOpen" @click.self="closeFundingRequestModal">
                <article class="modal-card tracky-form-modal">
                    <h3>{{ t('projectsPage.requestProjectFunding') }}</h3>
                    <p class="panel__hint">
                        {{ t('projectsPage.projectLabel') }}: <strong>{{ fundingRequestTargetProject?.name || '-' }}</strong>
                    </p>
                    <p class="field-error" v-if="fundingRequestError">{{ fundingRequestError }}</p>

                    <label class="field">
                        {{ t('projectsPage.fundingAmountUsd') }}
                        <input v-model="fundingRequestForm.amount" type="number" min="1" step="0.01" :placeholder="t('projectsPage.fundingAmountPlaceholder')">
                    </label>

                    <label class="field">
                        {{ t('projectsPage.reasonOptional') }}
                        <textarea v-model="fundingRequestForm.reason" rows="4" :placeholder="t('projectsPage.fundingReasonPlaceholder')"></textarea>
                    </label>

                    <div class="inline-group">
                        <button class="btn btn--primary" type="button" :disabled="fundingRequestSubmitting" @click="submitFundingRequest">
                            {{ t('projectsPage.submitFundingRequest') }}
                        </button>
                        <button class="btn btn--ghost" type="button" :disabled="fundingRequestSubmitting" @click="closeFundingRequestModal">
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
