<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppShell from '../components/AppShell.vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';

const { t } = useI18n();
const auth = useAuthStore();

const activeTab = ref('general');
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const success = ref('');

const tabs = computed(() => [
    { id: 'general', label: t('settingsPage.tabs.general') },
    { id: 'users-roles', label: t('settingsPage.tabs.usersRoles') },
    { id: 'workflow', label: t('settingsPage.tabs.workflow') },
    { id: 'security', label: t('settingsPage.tabs.security') },
]);

const sectionByTab = {
    general: 'general',
    'users-roles': 'users_roles',
    workflow: 'workflow',
    security: 'security',
};

const canManageSettings = computed(() => auth.hasPermission('workflow.manage'));

const clone = (value) => JSON.parse(JSON.stringify(value));

const defaultRoleDefinitions = () => ([
    {
        name: 'Super Admin',
        items: [
            { label: 'Manage all users', enabled: true },
            { label: 'Edit global settings', enabled: true },
            { label: 'Access audit logs', enabled: true },
            { label: 'Override workflow decisions', enabled: true },
        ],
    },
    {
        name: 'UNDP Admin',
        items: [
            { label: 'Review and validate submissions', enabled: true },
            { label: 'Request rework', enabled: true },
            { label: 'Approve or reject reports', enabled: true },
            { label: 'View analytics dashboard', enabled: true },
            { label: 'Access export & reporting tools', enabled: true },
        ],
    },
    {
        name: 'Municipal Validator',
        items: [
            { label: 'Review submissions within assigned scope', enabled: true },
            { label: 'Add comments', enabled: true },
            { label: 'Approve or request rework', enabled: true },
            { label: 'View project details (restricted)', enabled: true },
        ],
    },
    {
        name: 'Reporter (Community Agent)',
        items: [
            { label: 'Create and submit monitoring reports', enabled: true },
            { label: 'Upload media attachments', enabled: true },
            { label: 'View own submission history', enabled: true },
            { label: 'Respond to rework requests', enabled: true },
        ],
    },
    {
        name: 'Donor Observer',
        items: [
            { label: 'View project dashboards', enabled: true },
            { label: 'View approved submissions only', enabled: true },
            { label: 'Download reports', enabled: true },
            { label: 'No edit permissions', enabled: true },
        ],
    },
]);

const defaultPermissionMatrix = () => ([
    { name: 'Create Submission', reporter: true, validator: true, admin: true, super_admin: true },
    { name: 'Edit Before Approval', reporter: true, validator: true, admin: true, super_admin: true },
    { name: 'Approve / Reject', reporter: false, validator: true, admin: true, super_admin: true },
    { name: 'Manage Users', reporter: false, validator: false, admin: true, super_admin: true },
    { name: 'Access Audit Log', reporter: false, validator: true, admin: true, super_admin: true },
]);

const defaultGeneral = () => ({
    organization_name: 'UNDP Libya - Southern Region Monitoring Unit',
    organization_type: 'International Development Agency',
    system_environment: 'Production',
    primary_contact_email: 'monitoring@undp-libya.org',
    support_contact_email: 'support@undp-libya.org',
    default_language: 'English',
    default_timezone: 'UTC +2 (Tripoli)',
    default_date_format: 'DD MMM YYYY (e.g., 12 July 2026)',
    default_currency: 'USD (United States Dollar)',
    default_reporting_cycle: 'Weekly',
    project_statuses: ['Planned', 'In Progress', 'Not Yet Started', 'Completed'],
    risk_levels: ['Low', 'Medium', 'High'],
    default_progress_format: 'Percentage (0-100%)',
    submission_retention: '5 Years',
    audit_retention: '5 Years',
    archived_visibility: 'Admin Only',
    auto_archive_after: '12 Months of inactivity',
});

const defaultUsersRoles = () => ({
    role_definitions: defaultRoleDefinitions(),
    permission_matrix: defaultPermissionMatrix(),
});

const defaultWorkflow = () => ({
    workflow_mode: 'standard',
    default_submission_status: 'Submitted',
    auto_status_rule: 'approved',
    approval_requirement: 'validator',
    escalation_days: 5,
    escalation_enabled: false,
    comment_for_rework: false,
    comment_for_rejection: true,
    reporting_frequency: 'Weekly',
    deadline_day: 'Day',
    deadline_time: 'Time',
    deadline_timezone: 'Timezone',
    late_mark: false,
    late_notify_admin: false,
    late_notify_reporter: false,
    minimum_attachments: 2,
    require_photo: true,
    require_video: false,
    require_location_tag: false,
    allowed_jpg: true,
    allowed_png: false,
    allowed_mp4: false,
    allowed_pdf: false,
    risk_reporting_required: false,
    auto_flag_high_priority: true,
    notify_admin_immediately: true,
    require_additional_comment: true,
    email_on_new_submission: true,
    email_on_approval: true,
    email_on_rework: true,
    email_on_rejection: true,
    in_app_notifications: false,
    audit_track_status: true,
    audit_log_approval: true,
    audit_timestamp_actions: true,
});

const defaultSecurity = () => ({
    require_2fa_admin: true,
    enable_sso: false,
    minimum_length: 5,
    require_uppercase: false,
    require_numbers: false,
    require_special_chars: false,
    password_expiry_days: 90,
    rbac_enabled: true,
    ip_restriction_admin: true,
    log_login_activity: true,
    log_data_exports: true,
});

const roleNameKeyMap = {
    'Super Admin': 'superAdmin',
    'UNDP Admin': 'undpAdmin',
    'Municipal Validator': 'municipalValidator',
    'Reporter (Community Agent)': 'reporterCommunityAgent',
    'Donor Observer': 'donorObserver',
};

const roleItemKeyMap = {
    'Manage all users': 'manageAllUsers',
    'Edit global settings': 'editGlobalSettings',
    'Access audit logs': 'accessAuditLogs',
    'Override workflow decisions': 'overrideWorkflowDecisions',
    'Review and validate submissions': 'reviewValidateSubmissions',
    'Request rework': 'requestRework',
    'Approve or reject reports': 'approveRejectReports',
    'View analytics dashboard': 'viewAnalyticsDashboard',
    'Access export & reporting tools': 'accessExportReportingTools',
    'Review submissions within assigned scope': 'reviewSubmissionsAssignedScope',
    'Add comments': 'addComments',
    'Approve or request rework': 'approveOrRequestRework',
    'View project details (restricted)': 'viewProjectDetailsRestricted',
    'Create and submit monitoring reports': 'createSubmitMonitoringReports',
    'Upload media attachments': 'uploadMediaAttachments',
    'View own submission history': 'viewOwnSubmissionHistory',
    'Respond to rework requests': 'respondToReworkRequests',
    'View project dashboards': 'viewProjectDashboards',
    'View approved submissions only': 'viewApprovedSubmissionsOnly',
    'Download reports': 'downloadReports',
    'No edit permissions': 'noEditPermissions',
};

const permissionMatrixKeyMap = {
    'Create Submission': 'createSubmission',
    'Edit Before Approval': 'editBeforeApproval',
    'Approve / Reject': 'approveReject',
    'Manage Users': 'manageUsers',
    'Access Audit Log': 'accessAuditLog',
};

const listValueLabel = (value) => {
    const normalized = String(value || '').trim();
    const map = {
        Planned: 'statusLabels.planned',
        'In Progress': 'statusLabels.in_progress',
        'Not Yet Started': 'projectsPage.optionValues.not_started',
        Completed: 'statusLabels.completed',
        Low: 'dashboard.low',
        Medium: 'dashboard.medium',
        High: 'dashboard.high',
    };

    return map[normalized] ? t(map[normalized]) : normalized;
};

const roleDefinitionLabel = (value) => {
    const key = roleNameKeyMap[String(value || '').trim()];
    return key ? t(`settingsPage.roleNames.${key}`) : value || t('settingsPage.roleFallback');
};

const roleItemLabel = (value) => {
    const key = roleItemKeyMap[String(value || '').trim()];
    return key ? t(`settingsPage.roleItems.${key}`) : value || t('settingsPage.permissionFallback');
};

const permissionMatrixLabel = (value) => {
    const key = permissionMatrixKeyMap[String(value || '').trim()];
    return key ? t(`settingsPage.permissionMatrixRows.${key}`) : value || t('settingsPage.permissionFallback');
};

const normalizeStringList = (incoming, fallback) => {
    const values = Array.isArray(incoming) ? incoming : fallback;

    return values
        .map((item) => String(item ?? '').trim())
        .filter((item) => item.length > 0);
};

const normalizeRoleDefinitions = (incoming) => {
    const source = Array.isArray(incoming) && incoming.length ? incoming : defaultRoleDefinitions();

    return source.map((role) => ({
        name: String(role?.name ?? '').trim() || t('settingsPage.roleFallback'),
        items: Array.isArray(role?.items) && role.items.length
            ? role.items.map((item) => ({
                label: String(item?.label ?? '').trim() || t('settingsPage.permissionFallback'),
                enabled: Boolean(item?.enabled),
            }))
            : [{ label: t('settingsPage.permissionFallback'), enabled: false }],
    }));
};

const normalizePermissionMatrix = (incoming) => {
    const source = Array.isArray(incoming) && incoming.length ? incoming : defaultPermissionMatrix();

    return source.map((row) => ({
        name: String(row?.name ?? '').trim() || t('settingsPage.permissionFallback'),
        reporter: Boolean(row?.reporter),
        validator: Boolean(row?.validator),
        admin: Boolean(row?.admin),
        super_admin: Boolean(row?.super_admin),
    }));
};

const general = reactive(defaultGeneral());
const usersRoles = reactive(defaultUsersRoles());
const workflow = reactive(defaultWorkflow());
const security = reactive(defaultSecurity());

const savedState = ref({
    general: clone(general),
    users_roles: clone(usersRoles),
    workflow: clone(workflow),
    security: clone(security),
});

const syncReactive = (target, source) => {
    Object.keys(target).forEach((key) => {
        delete target[key];
    });

    Object.assign(target, clone(source));
};

const applyGeneral = (incoming = {}) => {
    const defaults = defaultGeneral();

    syncReactive(general, {
        ...defaults,
        ...clone(incoming),
        project_statuses: normalizeStringList(incoming.project_statuses, defaults.project_statuses),
        risk_levels: normalizeStringList(incoming.risk_levels, defaults.risk_levels),
    });
};

const applyUsersRoles = (incoming = {}) => {
    syncReactive(usersRoles, {
        role_definitions: normalizeRoleDefinitions(incoming.role_definitions),
        permission_matrix: normalizePermissionMatrix(incoming.permission_matrix),
    });
};

const applyWorkflow = (incoming = {}) => {
    syncReactive(workflow, {
        ...defaultWorkflow(),
        ...clone(incoming),
    });
};

const applySecurity = (incoming = {}) => {
    syncReactive(security, {
        ...defaultSecurity(),
        ...clone(incoming),
    });
};

const syncSavedState = () => {
    savedState.value = {
        general: clone(general),
        users_roles: clone(usersRoles),
        workflow: clone(workflow),
        security: clone(security),
    };
};

const applyPayload = (settings = {}) => {
    applyGeneral(settings.general || {});
    applyUsersRoles(settings.users_roles || {});
    applyWorkflow(settings.workflow || {});
    applySecurity(settings.security || {});
    syncSavedState();
};

const currentSectionKey = computed(() => sectionByTab[activeTab.value] || 'general');

const currentSectionPayload = () => {
    if (currentSectionKey.value === 'general') {
        return clone(general);
    }

    if (currentSectionKey.value === 'users_roles') {
        return clone(usersRoles);
    }

    if (currentSectionKey.value === 'workflow') {
        return clone(workflow);
    }

    return clone(security);
};

const isDirty = computed(() => {
    return JSON.stringify(currentSectionPayload()) !== JSON.stringify(savedState.value[currentSectionKey.value] || {});
});

const formatError = (err, fallback) => {
    const fieldErrors = err?.response?.data?.errors || {};
    const firstFieldMessage = Object.values(fieldErrors).flat()[0];

    return firstFieldMessage || err?.response?.data?.message || fallback;
};

const loadSettings = async () => {
    loading.value = true;
    error.value = '';

    try {
        const { data } = await api.get('/settings');
        applyPayload(data.settings || {});
    } catch (err) {
        error.value = formatError(err, t('settingsPage.unableToLoad'));
    } finally {
        loading.value = false;
    }
};

const saveActiveTab = async () => {
    if (!canManageSettings.value || saving.value || !isDirty.value) {
        return;
    }

    saving.value = true;
    error.value = '';
    success.value = '';

    try {
        const payload = {
            [currentSectionKey.value]: currentSectionPayload(),
        };

        const { data } = await api.put('/settings', payload);
        applyPayload(data.settings || {});
        success.value = data.message || t('settingsPage.savedSuccess');
    } catch (err) {
        error.value = formatError(err, t('settingsPage.unableToSave'));
    } finally {
        saving.value = false;
    }
};

const resetActiveTab = () => {
    success.value = '';
    error.value = '';

    if (currentSectionKey.value === 'general') {
        applyGeneral(savedState.value.general);
        return;
    }

    if (currentSectionKey.value === 'users_roles') {
        applyUsersRoles(savedState.value.users_roles);
        return;
    }

    if (currentSectionKey.value === 'workflow') {
        applyWorkflow(savedState.value.workflow);
        return;
    }

    applySecurity(savedState.value.security);
};

const addListValue = (field) => {
    if (!canManageSettings.value) {
        return;
    }

    const label = window.prompt(field === 'project_statuses'
        ? t('settingsPage.addProjectStatusPrompt')
        : t('settingsPage.addRiskLevelPrompt'));
    const normalized = String(label || '').trim();

    if (!normalized) {
        return;
    }

    general[field].push(normalized);
};

const editListValue = (field, index) => {
    if (!canManageSettings.value) {
        return;
    }

    const current = general[field][index] || '';
    const label = window.prompt(t('settingsPage.renameItemPrompt'), current);
    const normalized = String(label || '').trim();

    if (!normalized) {
        return;
    }

    general[field].splice(index, 1, normalized);
};

const removeListValue = (field, index) => {
    if (!canManageSettings.value || general[field].length <= 1) {
        return;
    }

    general[field].splice(index, 1);
};

onMounted(async () => {
    await loadSettings();
});
</script>

<template>
    <AppShell>
        <section class="tracky-settings-page">
            <header class="tracky-projects__head tracky-settings-head">
                <div>
                    <h2>{{ t('settingsPage.title') }}</h2>
                    
                </div>

                <div class="tracky-settings-head__actions">
                    <span class="tracky-settings-note" v-if="!canManageSettings">{{ t('settingsPage.viewOnly') }}</span>
                    <button
                        class="tracky-btn tracky-btn--ghost"
                        type="button"
                        :disabled="loading || saving || !isDirty"
                        @click="resetActiveTab"
                    >
                        {{ t('common.reset') }}
                    </button>
                    <button
                        class="tracky-btn tracky-btn--primary"
                        type="button"
                        :disabled="loading || saving || !canManageSettings || !isDirty"
                        @click="saveActiveTab"
                    >
                        {{ saving ? t('settingsPage.saving') : t('common.saveChanges') }}
                    </button>
                </div>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>
            <p class="tracky-success-note" v-else-if="success">{{ success }}</p>

            <nav class="tracky-settings-tabs">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    :class="{ active: activeTab === tab.id }"
                    @click="activeTab = tab.id"
                >
                    {{ tab.label }}
                </button>
            </nav>

            <section class="tracky-card tracky-settings-panel" v-if="activeTab === 'general'">
                <fieldset class="tracky-settings-fieldset" :disabled="loading || !canManageSettings">
                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.organizationProfile') }}</h3>
                        <div class="tracky-settings-fields">
                            <label class="field">{{ t('settingsPage.organizationName') }}<input v-model="general.organization_name" type="text"></label>
                            <label class="field">{{ t('settingsPage.organizationType') }}<input v-model="general.organization_type" type="text"></label>
                            <label class="field">{{ t('settingsPage.systemEnvironment') }}<input v-model="general.system_environment" type="text"></label>
                            <label class="field">{{ t('settingsPage.primaryContactEmail') }}<input v-model="general.primary_contact_email" type="email"></label>
                            <label class="field">{{ t('settingsPage.supportContactEmail') }}<input v-model="general.support_contact_email" type="email"></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.localizationRegional') }}</h3>
                        <div class="tracky-settings-fields">
                            <label class="field">{{ t('settingsPage.defaultLanguage') }}<select v-model="general.default_language"><option value="English">{{ t('settingsPage.english') }}</option><option value="Arabic">{{ t('settingsPage.arabic') }}</option></select></label>
                            <label class="field">{{ t('settingsPage.defaultTimeZone') }}<select v-model="general.default_timezone"><option value="UTC +2 (Tripoli)">{{ t('settingsPage.tripoliTimezone') }}</option><option value="UTC +0">{{ t('settingsPage.utcZero') }}</option></select></label>
                            <label class="field">{{ t('settingsPage.defaultDateFormat') }}<select v-model="general.default_date_format"><option value="DD MMM YYYY (e.g., 12 July 2026)">{{ t('settingsPage.dateFormatLong') }}</option><option value="YYYY-MM-DD">{{ t('settingsPage.dateFormatIso') }}</option></select></label>
                            <label class="field">{{ t('settingsPage.defaultCurrency') }}<select v-model="general.default_currency"><option value="USD (United States Dollar)">{{ t('settingsPage.usd') }}</option><option value="LYD (Libyan Dinar)">{{ t('settingsPage.lyd') }}</option></select></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.systemDefaults') }}</h3>
                        <div class="tracky-settings-fields">
                            <label class="field">{{ t('settingsPage.defaultReportingCycle') }}<select v-model="general.default_reporting_cycle"><option value="Weekly">{{ t('settingsPage.weekly') }}</option><option value="Monthly">{{ t('settingsPage.monthly') }}</option></select></label>

                            <div class="tracky-pill-editor">
                                <span>{{ t('settingsPage.defaultProjectStatusOptions') }}</span>
                                <div class="tracky-pill-editor__items">
                                    <div class="tracky-pill-editor__item" v-for="(item, index) in general.project_statuses" :key="`${item}-${index}`">
                                        <span>{{ listValueLabel(item) }}</span>
                                        <button type="button" @click="editListValue('project_statuses', index)">{{ t('projectsPage.editShort') }}</button>
                                        <button type="button" @click="removeListValue('project_statuses', index)">{{ t('common.delete') }}</button>
                                    </div>
                                </div>
                                <button class="tracky-pill-editor__add" type="button" @click="addListValue('project_statuses')">+</button>
                            </div>

                            <div class="tracky-pill-editor">
                                <span>{{ t('settingsPage.defaultRiskLevels') }}</span>
                                <div class="tracky-pill-editor__items">
                                    <div class="tracky-pill-editor__item" v-for="(item, index) in general.risk_levels" :key="`${item}-${index}`">
                                        <span>{{ listValueLabel(item) }}</span>
                                        <button type="button" @click="editListValue('risk_levels', index)">{{ t('projectsPage.editShort') }}</button>
                                        <button type="button" @click="removeListValue('risk_levels', index)">{{ t('common.delete') }}</button>
                                    </div>
                                </div>
                                <button class="tracky-pill-editor__add" type="button" @click="addListValue('risk_levels')">+</button>
                            </div>

                            <label class="field">{{ t('settingsPage.defaultProgressFormat') }}<select v-model="general.default_progress_format"><option value="Percentage (0-100%)">{{ t('settingsPage.percentage') }}</option><option value="Milestone Based">{{ t('settingsPage.milestoneBased') }}</option></select></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.dataRetentionArchiving') }}</h3>
                        <div class="tracky-settings-fields">
                            <label class="field">{{ t('settingsPage.submissionRetention') }}<select v-model="general.submission_retention"><option value="5 Years">{{ t('settingsPage.years5') }}</option><option value="7 Years">{{ t('settingsPage.years7') }}</option></select></label>
                            <label class="field">{{ t('settingsPage.auditRetention') }}<select v-model="general.audit_retention"><option value="5 Years">{{ t('settingsPage.years5') }}</option><option value="10 Years">{{ t('settingsPage.years10') }}</option></select></label>
                            <label class="field">{{ t('settingsPage.archivedProjectsVisibility') }}<select v-model="general.archived_visibility"><option value="Admin Only">{{ t('settingsPage.adminOnly') }}</option><option value="All Internal Users">{{ t('settingsPage.allInternalUsers') }}</option></select></label>
                            <label class="field">{{ t('settingsPage.autoArchiveAfter') }}<select v-model="general.auto_archive_after"><option value="12 Months of inactivity">{{ t('settingsPage.months12') }}</option><option value="24 Months of inactivity">{{ t('settingsPage.months24') }}</option></select></label>
                        </div>
                    </div>
                </fieldset>
            </section>

            <section class="tracky-card tracky-settings-panel" v-else-if="activeTab === 'users-roles'">
                <fieldset class="tracky-settings-fieldset" :disabled="loading || !canManageSettings">
                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.roleDefinition') }}</h3>
                        <div class="tracky-settings-role-grid">
                            <article class="tracky-settings-role-card" v-for="role in usersRoles.role_definitions" :key="role.name">
                                <h4>{{ roleDefinitionLabel(role.name) }}</h4>
                                <label class="tracky-checkline" v-for="item in role.items" :key="`${role.name}-${item.label}`">
                                    <input v-model="item.enabled" type="checkbox">
                                    <span>{{ roleItemLabel(item.label) }}</span>
                                </label>
                            </article>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.corePermissions') }}</h3>
                        <div class="tracky-projects-table-wrap">
                            <table class="tracky-projects-table">
                                <thead>
                                <tr>
                                    <th>{{ t('settingsPage.permission') }}</th>
                                    <th>{{ t('roles.reporter') }}</th>
                                    <th>{{ t('settingsPage.validator') }}</th>
                                    <th>{{ t('settingsPage.admin') }}</th>
                                    <th>{{ t('settingsPage.superAdmin') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr v-for="row in usersRoles.permission_matrix" :key="row.name">
                                    <td>{{ permissionMatrixLabel(row.name) }}</td>
                                    <td><input v-model="row.reporter" type="checkbox"></td>
                                    <td><input v-model="row.validator" type="checkbox"></td>
                                    <td><input v-model="row.admin" type="checkbox"></td>
                                    <td><input v-model="row.super_admin" type="checkbox"></td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </fieldset>
            </section>

            <section class="tracky-card tracky-settings-panel" v-else-if="activeTab === 'workflow'">
                <fieldset class="tracky-settings-fieldset" :disabled="loading || !canManageSettings">
                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.submissionWorkflow') }}</h3>
                        <div class="tracky-settings-fields">
                            <div class="tracky-settings-choice-group">
                                <span>{{ t('settingsPage.workflowMode') }}</span>
                                <label class="tracky-checkline"><input type="radio" value="standard" v-model="workflow.workflow_mode"><span>{{ t('settingsPage.standardReview') }}</span></label>
                                <label class="tracky-checkline"><input type="radio" value="multi" v-model="workflow.workflow_mode"><span>{{ t('settingsPage.multiLevelValidation') }}</span></label>
                                <label class="tracky-checkline"><input type="radio" value="direct" v-model="workflow.workflow_mode"><span>{{ t('settingsPage.directAdminReview') }}</span></label>
                            </div>
                            <label class="field">{{ t('settingsPage.defaultSubmissionStatus') }}<select v-model="workflow.default_submission_status"><option value="Submitted">{{ t('statusLabels.submitted') }}</option><option value="Under Review">{{ t('settingsPage.underReview') }}</option></select></label>
                            <div class="tracky-settings-choice-group">
                                <span>{{ t('settingsPage.autoStatusRules') }}</span>
                                <label class="tracky-checkline"><input type="radio" value="approved" v-model="workflow.auto_status_rule"><span>{{ t('statusLabels.approved') }}</span></label>
                                <label class="tracky-checkline"><input type="radio" value="rejected" v-model="workflow.auto_status_rule"><span>{{ t('statusLabels.rejected') }}</span></label>
                                <label class="tracky-checkline"><input type="radio" value="rework" v-model="workflow.auto_status_rule"><span>{{ t('submissionDetail.rework') }}</span></label>
                            </div>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.submissionWorkflow') }}</h3>
                        <div class="tracky-settings-fields">
                            <div class="tracky-settings-choice-group">
                                <span>{{ t('settingsPage.approvalRequirement') }}</span>
                                <label class="tracky-checkline"><input type="radio" value="validator" v-model="workflow.approval_requirement"><span>{{ t('settingsPage.atLeastOneValidator') }}</span></label>
                                <label class="tracky-checkline"><input type="radio" value="admin" v-model="workflow.approval_requirement"><span>{{ t('settingsPage.adminFinalApproval') }}</span></label>
                            </div>
                            <label class="field">{{ t('settingsPage.escalateAfter') }}<input v-model="workflow.escalation_days" type="number"></label>
                            <label class="tracky-toggle-line"><span>{{ t('settingsPage.enableEscalation') }}</span><input v-model="workflow.escalation_enabled" type="checkbox"></label>
                            <label class="tracky-checkline"><input v-model="workflow.comment_for_rework" type="checkbox"><span>{{ t('settingsPage.commentMandatoryRework') }}</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.comment_for_rejection" type="checkbox"><span>{{ t('settingsPage.commentMandatoryRejection') }}</span></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.reportingSchedule') }}</h3>
                        <div class="tracky-settings-fields">
                            <label class="field">{{ t('settingsPage.reportingFrequency') }}<select v-model="workflow.reporting_frequency"><option value="Weekly">{{ t('settingsPage.weekly') }}</option><option value="Monthly">{{ t('settingsPage.monthly') }}</option></select></label>
                            <div class="inline-group">
                                <label class="field">{{ t('settingsPage.day') }}<input v-model="workflow.deadline_day" type="text"></label>
                                <label class="field">{{ t('settingsPage.time') }}<input v-model="workflow.deadline_time" type="text"></label>
                                <label class="field">{{ t('settingsPage.timezone') }}<input v-model="workflow.deadline_timezone" type="text"></label>
                            </div>
                            <label class="tracky-checkline"><input v-model="workflow.late_mark" type="checkbox"><span>{{ t('settingsPage.markAsLate') }}</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.late_notify_admin" type="checkbox"><span>{{ t('settingsPage.sendNotificationAdmin') }}</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.late_notify_reporter" type="checkbox"><span>{{ t('settingsPage.sendReminderReporter') }}</span></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.evidenceRequirements') }}</h3>
                        <div class="tracky-settings-fields">
                            <label class="field">{{ t('settingsPage.filesPerSubmission') }}<input v-model="workflow.minimum_attachments" type="number"></label>
                            <label class="tracky-checkline"><input v-model="workflow.require_photo" type="checkbox"><span>{{ t('settingsPage.atLeastOnePhoto') }}</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.require_video" type="checkbox"><span>{{ t('settingsPage.videoRequired') }}</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.require_location_tag" type="checkbox"><span>{{ t('settingsPage.locationTagRequired') }}</span></label>
                            <div class="tracky-file-types">
                                <label class="tracky-checkline"><input v-model="workflow.allowed_jpg" type="checkbox"><span>JPG</span></label>
                                <label class="tracky-checkline"><input v-model="workflow.allowed_png" type="checkbox"><span>PNG</span></label>
                                <label class="tracky-checkline"><input v-model="workflow.allowed_mp4" type="checkbox"><span>MP4</span></label>
                                <label class="tracky-checkline"><input v-model="workflow.allowed_pdf" type="checkbox"><span>PDF</span></label>
                            </div>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.riskReporting') }}</h3>
                        <div class="tracky-settings-fields">
                            <label class="tracky-toggle-line"><span>{{ t('settingsPage.riskReportingRequired') }}</span><input v-model="workflow.risk_reporting_required" type="checkbox"></label>
                            <label class="tracky-checkline"><input v-model="workflow.auto_flag_high_priority" type="checkbox"><span>{{ t('settingsPage.autoFlagHighPriority') }}</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.notify_admin_immediately" type="checkbox"><span>{{ t('settingsPage.notifyAdminImmediately') }}</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.require_additional_comment" type="checkbox"><span>{{ t('settingsPage.requireAdditionalComment') }}</span></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.notificationsTracking') }}</h3>
                        <div class="tracky-settings-fields">
                            <label class="tracky-checkline"><input v-model="workflow.email_on_new_submission" type="checkbox"><span>{{ t('settingsPage.onNewSubmission') }}</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.email_on_approval" type="checkbox"><span>{{ t('settingsPage.onApproval') }}</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.email_on_rework" type="checkbox"><span>{{ t('settingsPage.onRework') }}</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.email_on_rejection" type="checkbox"><span>{{ t('settingsPage.onRejection') }}</span></label>
                            <label class="tracky-toggle-line"><span>{{ t('settingsPage.inAppNotifications') }}</span><input v-model="workflow.in_app_notifications" type="checkbox"></label>
                            <label class="tracky-checkline"><input v-model="workflow.audit_track_status" type="checkbox"><span>{{ t('settingsPage.trackStatusChanges') }}</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.audit_log_approval" type="checkbox"><span>{{ t('settingsPage.logApprovalHistory') }}</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.audit_timestamp_actions" type="checkbox"><span>{{ t('settingsPage.timestampAllActions') }}</span></label>
                        </div>
                    </div>
                </fieldset>
            </section>

            <section class="tracky-card tracky-settings-panel" v-else>
                <fieldset class="tracky-settings-fieldset" :disabled="loading || !canManageSettings">
                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.authenticationSettings') }}</h3>
                        <div class="tracky-settings-fields">
                            <label class="tracky-checkline"><input v-model="security.require_2fa_admin" type="checkbox"><span>{{ t('settingsPage.twoFactorRequiredAdmin') }}</span></label>
                            <label class="tracky-checkline"><input v-model="security.enable_sso" type="checkbox"><span>{{ t('settingsPage.ssoEnabled') }}</span></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.passwordPolicy') }}</h3>
                        <div class="tracky-settings-fields">
                            <label class="field">{{ t('settingsPage.minimumLength') }}<input v-model="security.minimum_length" type="number"></label>
                            <label class="tracky-toggle-line"><span>{{ t('settingsPage.requireUppercaseLetters') }}</span><input v-model="security.require_uppercase" type="checkbox"></label>
                            <label class="tracky-toggle-line"><span>{{ t('settingsPage.requireNumbers') }}</span><input v-model="security.require_numbers" type="checkbox"></label>
                            <label class="tracky-toggle-line"><span>{{ t('settingsPage.requireSpecialCharacters') }}</span><input v-model="security.require_special_chars" type="checkbox"></label>
                            <label class="field">{{ t('settingsPage.passwordExpiry') }}<input v-model="security.password_expiry_days" type="number"></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.accessControl') }}</h3>
                        <div class="tracky-settings-fields">
                            <label class="tracky-toggle-line"><span>{{ t('settingsPage.roleBasedAccessControl') }}</span><input v-model="security.rbac_enabled" type="checkbox"></label>
                            <label class="tracky-toggle-line"><span>{{ t('settingsPage.ipRestrictionAdminOnly') }}</span><input v-model="security.ip_restriction_admin" type="checkbox"></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>{{ t('settingsPage.auditMonitoring') }}</h3>
                        <div class="tracky-settings-fields">
                            <label class="tracky-toggle-line"><span>{{ t('settingsPage.logLoginActivity') }}</span><input v-model="security.log_login_activity" type="checkbox"></label>
                            <label class="tracky-toggle-line"><span>{{ t('settingsPage.logDataExports') }}</span><input v-model="security.log_data_exports" type="checkbox"></label>
                        </div>
                    </div>
                </fieldset>
            </section>
        </section>
    </AppShell>
</template>
