<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AppShell from '../components/AppShell.vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();

const activeTab = ref('general');
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const success = ref('');

const tabs = [
    { id: 'general', label: 'General' },
    { id: 'users-roles', label: 'Users & Roles' },
    { id: 'workflow', label: 'Reporting & Workflow' },
    { id: 'security', label: 'Security' },
];

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

const normalizeStringList = (incoming, fallback) => {
    const values = Array.isArray(incoming) ? incoming : fallback;

    return values
        .map((item) => String(item ?? '').trim())
        .filter((item) => item.length > 0);
};

const normalizeRoleDefinitions = (incoming) => {
    const source = Array.isArray(incoming) && incoming.length ? incoming : defaultRoleDefinitions();

    return source.map((role) => ({
        name: String(role?.name ?? '').trim() || 'Role',
        items: Array.isArray(role?.items) && role.items.length
            ? role.items.map((item) => ({
                label: String(item?.label ?? '').trim() || 'Permission',
                enabled: Boolean(item?.enabled),
            }))
            : [{ label: 'Permission', enabled: false }],
    }));
};

const normalizePermissionMatrix = (incoming) => {
    const source = Array.isArray(incoming) && incoming.length ? incoming : defaultPermissionMatrix();

    return source.map((row) => ({
        name: String(row?.name ?? '').trim() || 'Permission',
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
        error.value = formatError(err, 'Unable to load settings.');
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
        success.value = data.message || 'Settings saved successfully.';
    } catch (err) {
        error.value = formatError(err, 'Unable to save settings.');
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

    const label = window.prompt(`Add ${field === 'project_statuses' ? 'project status' : 'risk level'}`);
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
    const label = window.prompt('Rename item', current);
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
                    <h2>Settings</h2>
                    <p class="tracky-settings-note">
                        Manage platform defaults, workflow rules, and security policies.
                    </p>
                </div>

                <div class="tracky-settings-head__actions">
                    <span class="tracky-settings-note" v-if="!canManageSettings">View only</span>
                    <button
                        class="tracky-btn tracky-btn--ghost"
                        type="button"
                        :disabled="loading || saving || !isDirty"
                        @click="resetActiveTab"
                    >
                        Reset
                    </button>
                    <button
                        class="tracky-btn tracky-btn--primary"
                        type="button"
                        :disabled="loading || saving || !canManageSettings || !isDirty"
                        @click="saveActiveTab"
                    >
                        {{ saving ? 'Saving...' : 'Save Changes' }}
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
                        <h3>Organization Profile</h3>
                        <div class="tracky-settings-fields">
                            <label class="field">Organization Name<input v-model="general.organization_name" type="text"></label>
                            <label class="field">Organization Type<input v-model="general.organization_type" type="text"></label>
                            <label class="field">System Environment<input v-model="general.system_environment" type="text"></label>
                            <label class="field">Primary Contact Email<input v-model="general.primary_contact_email" type="email"></label>
                            <label class="field">Support Contact Email<input v-model="general.support_contact_email" type="email"></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>Localization & Regional Settings</h3>
                        <div class="tracky-settings-fields">
                            <label class="field">Default Language<select v-model="general.default_language"><option>English</option><option>Arabic</option></select></label>
                            <label class="field">Default Time Zone<select v-model="general.default_timezone"><option>UTC +2 (Tripoli)</option><option>UTC +0</option></select></label>
                            <label class="field">Default Date Format<select v-model="general.default_date_format"><option>DD MMM YYYY (e.g., 12 July 2026)</option><option>YYYY-MM-DD</option></select></label>
                            <label class="field">Default Currency<select v-model="general.default_currency"><option>USD (United States Dollar)</option><option>LYD (Libyan Dinar)</option></select></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>System Defaults</h3>
                        <div class="tracky-settings-fields">
                            <label class="field">Default Reporting Cycle<select v-model="general.default_reporting_cycle"><option>Weekly</option><option>Monthly</option></select></label>

                            <div class="tracky-pill-editor">
                                <span>Default Project Status Options</span>
                                <div class="tracky-pill-editor__items">
                                    <div class="tracky-pill-editor__item" v-for="(item, index) in general.project_statuses" :key="`${item}-${index}`">
                                        <span>{{ item }}</span>
                                        <button type="button" @click="editListValue('project_statuses', index)">Edit</button>
                                        <button type="button" @click="removeListValue('project_statuses', index)">Del</button>
                                    </div>
                                </div>
                                <button class="tracky-pill-editor__add" type="button" @click="addListValue('project_statuses')">+</button>
                            </div>

                            <div class="tracky-pill-editor">
                                <span>Default Risk Levels</span>
                                <div class="tracky-pill-editor__items">
                                    <div class="tracky-pill-editor__item" v-for="(item, index) in general.risk_levels" :key="`${item}-${index}`">
                                        <span>{{ item }}</span>
                                        <button type="button" @click="editListValue('risk_levels', index)">Edit</button>
                                        <button type="button" @click="removeListValue('risk_levels', index)">Del</button>
                                    </div>
                                </div>
                                <button class="tracky-pill-editor__add" type="button" @click="addListValue('risk_levels')">+</button>
                            </div>

                            <label class="field">Default Progress Format<select v-model="general.default_progress_format"><option>Percentage (0-100%)</option><option>Milestone Based</option></select></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>Data Retention & Archiving</h3>
                        <div class="tracky-settings-fields">
                            <label class="field">Submission Data Retention Period<select v-model="general.submission_retention"><option>5 Years</option><option>7 Years</option></select></label>
                            <label class="field">Audit Log Retention Period<select v-model="general.audit_retention"><option>5 Years</option><option>10 Years</option></select></label>
                            <label class="field">Archived Projects Visibility<select v-model="general.archived_visibility"><option>Admin Only</option><option>All Internal Users</option></select></label>
                            <label class="field">Auto-Archive Completed Projects After<select v-model="general.auto_archive_after"><option>12 Months of inactivity</option><option>24 Months of inactivity</option></select></label>
                        </div>
                    </div>
                </fieldset>
            </section>

            <section class="tracky-card tracky-settings-panel" v-else-if="activeTab === 'users-roles'">
                <fieldset class="tracky-settings-fieldset" :disabled="loading || !canManageSettings">
                    <div class="tracky-settings-grid">
                        <h3>Role Definition</h3>
                        <div class="tracky-settings-role-grid">
                            <article class="tracky-settings-role-card" v-for="role in usersRoles.role_definitions" :key="role.name">
                                <h4>{{ role.name }}</h4>
                                <label class="tracky-checkline" v-for="item in role.items" :key="`${role.name}-${item.label}`">
                                    <input v-model="item.enabled" type="checkbox">
                                    <span>{{ item.label }}</span>
                                </label>
                            </article>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>Core Permissions</h3>
                        <div class="tracky-projects-table-wrap">
                            <table class="tracky-projects-table">
                                <thead>
                                <tr>
                                    <th>Permission</th>
                                    <th>Reporter</th>
                                    <th>Validator</th>
                                    <th>Admin</th>
                                    <th>Super Admin</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr v-for="row in usersRoles.permission_matrix" :key="row.name">
                                    <td>{{ row.name }}</td>
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
                        <h3>Submission Workflow</h3>
                        <div class="tracky-settings-fields">
                            <div class="tracky-settings-choice-group">
                                <span>Workflow Mode</span>
                                <label class="tracky-checkline"><input type="radio" value="standard" v-model="workflow.workflow_mode"><span>Standard Review</span></label>
                                <label class="tracky-checkline"><input type="radio" value="multi" v-model="workflow.workflow_mode"><span>Multi-Level Validation</span></label>
                                <label class="tracky-checkline"><input type="radio" value="direct" v-model="workflow.workflow_mode"><span>Direct Admin Review</span></label>
                            </div>
                            <label class="field">Default Submission Status<select v-model="workflow.default_submission_status"><option>Submitted</option><option>Under Review</option></select></label>
                            <div class="tracky-settings-choice-group">
                                <span>Auto Status Rules</span>
                                <label class="tracky-checkline"><input type="radio" value="approved" v-model="workflow.auto_status_rule"><span>Approved</span></label>
                                <label class="tracky-checkline"><input type="radio" value="rejected" v-model="workflow.auto_status_rule"><span>Rejected</span></label>
                                <label class="tracky-checkline"><input type="radio" value="rework" v-model="workflow.auto_status_rule"><span>Rework</span></label>
                            </div>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>Submission Workflow</h3>
                        <div class="tracky-settings-fields">
                            <div class="tracky-settings-choice-group">
                                <span>Approval Requirement</span>
                                <label class="tracky-checkline"><input type="radio" value="validator" v-model="workflow.approval_requirement"><span>At least 1 Validator required</span></label>
                                <label class="tracky-checkline"><input type="radio" value="admin" v-model="workflow.approval_requirement"><span>Admin Final Approval required</span></label>
                            </div>
                            <label class="field">Escalate after<input v-model="workflow.escalation_days" type="number"></label>
                            <label class="tracky-toggle-line"><span>Enable Escalation</span><input v-model="workflow.escalation_enabled" type="checkbox"></label>
                            <label class="tracky-checkline"><input v-model="workflow.comment_for_rework" type="checkbox"><span>Comment mandatory for Rework</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.comment_for_rejection" type="checkbox"><span>Comment mandatory for Rejection</span></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>Comment mandatory for Rejection</h3>
                        <div class="tracky-settings-fields">
                            <label class="field">Reporting Frequency<select v-model="workflow.reporting_frequency"><option>Weekly</option><option>Monthly</option></select></label>
                            <div class="inline-group">
                                <label class="field">Day<input v-model="workflow.deadline_day" type="text"></label>
                                <label class="field">Time<input v-model="workflow.deadline_time" type="text"></label>
                                <label class="field">Timezone<input v-model="workflow.deadline_timezone" type="text"></label>
                            </div>
                            <label class="tracky-checkline"><input v-model="workflow.late_mark" type="checkbox"><span>Mark as Late</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.late_notify_admin" type="checkbox"><span>Send notification to Admin</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.late_notify_reporter" type="checkbox"><span>Send reminder to Reporter</span></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>Evidence Requirements</h3>
                        <div class="tracky-settings-fields">
                            <label class="field">Files per submission<input v-model="workflow.minimum_attachments" type="number"></label>
                            <label class="tracky-checkline"><input v-model="workflow.require_photo" type="checkbox"><span>At least 1 Photo required</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.require_video" type="checkbox"><span>Video required</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.require_location_tag" type="checkbox"><span>Location Tag required</span></label>
                            <div class="tracky-file-types">
                                <label class="tracky-checkline"><input v-model="workflow.allowed_jpg" type="checkbox"><span>JPG</span></label>
                                <label class="tracky-checkline"><input v-model="workflow.allowed_png" type="checkbox"><span>PNG</span></label>
                                <label class="tracky-checkline"><input v-model="workflow.allowed_mp4" type="checkbox"><span>MP4</span></label>
                                <label class="tracky-checkline"><input v-model="workflow.allowed_pdf" type="checkbox"><span>PDF</span></label>
                            </div>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>Risk Reporting</h3>
                        <div class="tracky-settings-fields">
                            <label class="tracky-toggle-line"><span>Risk Reporting Required</span><input v-model="workflow.risk_reporting_required" type="checkbox"></label>
                            <label class="tracky-checkline"><input v-model="workflow.auto_flag_high_priority" type="checkbox"><span>Auto-flag as High Priority</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.notify_admin_immediately" type="checkbox"><span>Notify Admin immediately</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.require_additional_comment" type="checkbox"><span>Require additional comment</span></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>Notifications & Tracking</h3>
                        <div class="tracky-settings-fields">
                            <label class="tracky-checkline"><input v-model="workflow.email_on_new_submission" type="checkbox"><span>On New Submission</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.email_on_approval" type="checkbox"><span>On Approval</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.email_on_rework" type="checkbox"><span>On Rework</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.email_on_rejection" type="checkbox"><span>On Rejection</span></label>
                            <label class="tracky-toggle-line"><span>In-App Notifications</span><input v-model="workflow.in_app_notifications" type="checkbox"></label>
                            <label class="tracky-checkline"><input v-model="workflow.audit_track_status" type="checkbox"><span>Track status changes</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.audit_log_approval" type="checkbox"><span>Log approval history</span></label>
                            <label class="tracky-checkline"><input v-model="workflow.audit_timestamp_actions" type="checkbox"><span>Timestamp all actions</span></label>
                        </div>
                    </div>
                </fieldset>
            </section>

            <section class="tracky-card tracky-settings-panel" v-else>
                <fieldset class="tracky-settings-fieldset" :disabled="loading || !canManageSettings">
                    <div class="tracky-settings-grid">
                        <h3>Authentication Settings</h3>
                        <div class="tracky-settings-fields">
                            <label class="tracky-checkline"><input v-model="security.require_2fa_admin" type="checkbox"><span>Two-Factor Authentication (2FA) Required for Admin</span></label>
                            <label class="tracky-checkline"><input v-model="security.enable_sso" type="checkbox"><span>Single Sign-On (SSO) Enabled</span></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>Password Policy</h3>
                        <div class="tracky-settings-fields">
                            <label class="field">Minimum Length<input v-model="security.minimum_length" type="number"></label>
                            <label class="tracky-toggle-line"><span>Require Uppercase Letters</span><input v-model="security.require_uppercase" type="checkbox"></label>
                            <label class="tracky-toggle-line"><span>Require Numbers</span><input v-model="security.require_numbers" type="checkbox"></label>
                            <label class="tracky-toggle-line"><span>Require Special Characters</span><input v-model="security.require_special_chars" type="checkbox"></label>
                            <label class="field">Password Expiry<input v-model="security.password_expiry_days" type="number"></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>Access Control</h3>
                        <div class="tracky-settings-fields">
                            <label class="tracky-toggle-line"><span>Role-Based Access Control (RBAC)</span><input v-model="security.rbac_enabled" type="checkbox"></label>
                            <label class="tracky-toggle-line"><span>IP Restriction (Admin Only)</span><input v-model="security.ip_restriction_admin" type="checkbox"></label>
                        </div>
                    </div>

                    <div class="tracky-settings-grid">
                        <h3>Audit & Monitoring</h3>
                        <div class="tracky-settings-fields">
                            <label class="tracky-toggle-line"><span>Log Login Activity</span><input v-model="security.log_login_activity" type="checkbox"></label>
                            <label class="tracky-toggle-line"><span>Log Data Exports (CSV/PDF)</span><input v-model="security.log_data_exports" type="checkbox"></label>
                        </div>
                    </div>
                </fieldset>
            </section>
        </section>
    </AppShell>
</template>
