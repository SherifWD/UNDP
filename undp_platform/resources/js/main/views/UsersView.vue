<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppShell from '../components/AppShell.vue';
import api from '../api';
import { useUiStore } from '../stores/ui';

const { t } = useI18n();
const ui = useUiStore();

const users = ref([]);
const roles = ref([]);
const municipalities = ref([]);
const loading = ref(false);
const error = ref('');

const createModalOpen = ref(false);
const permissionsModalOpen = ref(false);
const statusModalOpen = ref(false);
const selectedUser = ref(null);
const pendingStatusUser = ref(null);
const statusReason = ref('');

const pagination = reactive({
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
});

const createForm = reactive({
    name: '',
    email: '',
    country_code: '+218',
    phone: '',
    role: 'reporter',
    municipality_id: '',
    preferred_locale: 'ar',
    organization: '',
});

const editForm = reactive({
    name: '',
    email: '',
    country_code: '+218',
    phone: '',
    role: 'reporter',
    municipality_id: '',
    preferred_locale: 'ar',
});

const createErrors = reactive({});
const editErrors = reactive({});

const reporterRoles = ['reporter', 'municipal_focal_point'];

const roleMap = computed(() => {
    return roles.value.reduce((acc, role) => {
        acc[role.value] = role;
        return acc;
    }, {});
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

const reporterCount = computed(() => users.value.filter((user) => reporterRoles.includes(user.role)).length);
const totalUsers = computed(() => Number(pagination.total || users.value.length));

const donutBackground = computed(() => {
    const total = Math.max(totalUsers.value, 1);
    const ratio = Math.max(0, Math.min(1, reporterCount.value / total));
    const angle = ratio * 360;
    return `conic-gradient(#1f6feb 0deg ${angle}deg, #20b15a ${angle}deg ${Math.min(360, angle + 56)}deg, #d7dce8 ${Math.min(360, angle + 56)}deg 360deg)`;
});

const municipalityBreakdown = computed(() => {
    const counts = new Map();

    users.value
        .filter((user) => reporterRoles.includes(user.role))
        .forEach((user) => {
            const key = user.municipality?.name || t('roles.unassigned');
            counts.set(key, (counts.get(key) || 0) + 1);
        });

    return [...counts.entries()]
        .sort((a, b) => b[1] - a[1])
        .slice(0, 4)
        .map(([name, total]) => ({ name, total }));
});

const roleGroups = computed(() => {
    const buckets = {
        admin: 0,
        reporter: 0,
        donor: 0,
        other: 0,
    };

    users.value.forEach((user) => {
        if (['undp_admin'].includes(user.role)) {
            buckets.admin += 1;
            return;
        }

        if (reporterRoles.includes(user.role)) {
            buckets.reporter += 1;
            return;
        }

        if (['partner_donor_viewer'].includes(user.role)) {
            buckets.donor += 1;
            return;
        }

        buckets.other += 1;
    });

    return buckets;
});

const roleGroupBars = computed(() => {
    const total = Math.max(users.value.length, 1);

    return [
        { label: t('usersPage.roleBuckets.admin'), color: '#2b8af0', value: roleGroups.value.admin },
        { label: t('usersPage.roleBuckets.reporter'), color: '#233aa8', value: roleGroups.value.reporter },
        { label: t('usersPage.roleBuckets.donor'), color: '#ea6a35', value: roleGroups.value.donor },
        { label: t('usersPage.roleBuckets.other'), color: '#7f1a8e', value: roleGroups.value.other },
    ].map((item) => ({
        ...item,
        width: `${Math.max(item.value ? 8 : 0, Math.round((item.value / total) * 100))}%`,
    }));
});

const permissionPreviewItems = [
    { label: t('usersPage.permissionPreview.validateSubmissions'), match: ['submissions.validate', 'submissions.approve', 'submissions.reject'] },
    { label: t('usersPage.permissionPreview.viewAuditLogs'), match: ['audit.view'] },
    { label: t('usersPage.permissionPreview.accessKpiDashboard'), match: ['dashboards.view'] },
    { label: t('usersPage.permissionPreview.exportSystemReports'), match: ['reports.export'] },
];

const currentRolePermissions = computed(() => roleMap.value[editForm.role]?.permissions || []);

const permissionPreview = computed(() => {
    return permissionPreviewItems.map((item) => ({
        ...item,
        enabled: item.match.some((token) => currentRolePermissions.value.some((permission) => permission.includes(token))),
    }));
});

const roleLabel = (role) => t(`roles.${role}`, roleMap.value[role]?.label || role || '-');
const userTypeLabel = (user) => (reporterRoles.includes(user.role) ? t('usersPage.typeReporter') : t('usersPage.typeManagement'));
const userTypeBadgeClass = (user) => (reporterRoles.includes(user.role) ? 'badge--active' : 'badge--medium');

const resetValidationState = (target) => {
    Object.keys(target).forEach((key) => {
        delete target[key];
    });
};

const applyValidationErrors = (target, err) => {
    resetValidationState(target);

    const fieldErrors = err?.response?.data?.errors || {};
    Object.entries(fieldErrors).forEach(([field, messages]) => {
        target[field] = Array.isArray(messages) ? messages[0] : String(messages);
    });
};

const loadLookups = async () => {
    const [rolesRes, municipalitiesRes] = await Promise.all([
        api.get('/roles'),
        api.get('/municipalities'),
    ]);

    roles.value = rolesRes.data.data || [];
    municipalities.value = municipalitiesRes.data.data || [];
};

const loadUsers = async (page = pagination.current_page) => {
    loading.value = true;
    error.value = '';

    try {
        const { data } = await api.get('/users', {
            params: {
                page,
                per_page: pagination.per_page,
            },
        });

        users.value = data.data || [];
        pagination.current_page = data.current_page || 1;
        pagination.last_page = data.last_page || 1;
        pagination.total = data.total || users.value.length;
    } catch (err) {
        error.value = err.response?.data?.message || t('usersPage.unableToLoad');
        users.value = [];
        pagination.current_page = 1;
        pagination.last_page = 1;
        pagination.total = 0;
    } finally {
        loading.value = false;
    }
};

const resetCreateForm = () => {
    Object.assign(createForm, {
        name: '',
        email: '',
        country_code: '+218',
        phone: '',
        role: 'reporter',
        municipality_id: '',
        preferred_locale: 'ar',
        organization: '',
    });
    resetValidationState(createErrors);
};

const openCreateModal = () => {
    resetCreateForm();
    createModalOpen.value = true;
    error.value = '';
};

const closeCreateModal = () => {
    createModalOpen.value = false;
};

const createUser = async () => {
    resetValidationState(createErrors);

    try {
        await api.post('/users', {
            name: createForm.name,
            email: createForm.email || null,
            country_code: createForm.country_code,
            phone: createForm.phone.replace(/\D/g, ''),
            role: createForm.role,
            municipality_id: createForm.municipality_id || null,
            preferred_locale: createForm.preferred_locale,
        });

        ui.pushToast(t('usersPage.createdSuccess'));
        createModalOpen.value = false;
        await loadUsers(1);
    } catch (err) {
        if (err.response?.status === 422) {
            applyValidationErrors(createErrors, err);
        }
        error.value = err.response?.data?.message || t('usersPage.unableToCreate');
    }
};

const openPermissionsModal = (user) => {
    selectedUser.value = user;
    error.value = '';
    resetValidationState(editErrors);

    Object.assign(editForm, {
        name: user.name || '',
        email: user.email || '',
        country_code: user.country_code || '+218',
        phone: user.phone || '',
        role: user.role,
        municipality_id: user.municipality?.id || '',
        preferred_locale: user.preferred_locale || 'ar',
    });

    permissionsModalOpen.value = true;
};

const closePermissionsModal = () => {
    permissionsModalOpen.value = false;
    selectedUser.value = null;
    resetValidationState(editErrors);
};

const savePermissions = async () => {
    if (!selectedUser.value) {
        return;
    }

    resetValidationState(editErrors);

    try {
        await api.put(`/users/${selectedUser.value.id}`, {
            name: editForm.name,
            email: editForm.email || null,
            country_code: editForm.country_code,
            phone: editForm.phone.replace(/\D/g, ''),
            role: editForm.role,
            municipality_id: editForm.municipality_id || null,
            preferred_locale: editForm.preferred_locale,
            confirm_role_change: true,
        });

        ui.pushToast(t('usersPage.updatedSuccess'));
        closePermissionsModal();
        await loadUsers(pagination.current_page);
    } catch (err) {
        if (err.response?.status === 422) {
            applyValidationErrors(editErrors, err);
        }
        error.value = err.response?.data?.message || t('usersPage.unableToUpdate');
    }
};

const openStatusModal = (user) => {
    pendingStatusUser.value = user;
    statusReason.value = '';
    statusModalOpen.value = true;
};

const closeStatusModal = () => {
    statusModalOpen.value = false;
    pendingStatusUser.value = null;
    statusReason.value = '';
};

const confirmStatusChange = async () => {
    if (!pendingStatusUser.value) {
        return;
    }

    const nextStatus = pendingStatusUser.value.status === 'active' ? 'disabled' : 'active';

    try {
        await api.patch(`/users/${pendingStatusUser.value.id}/status`, {
            status: nextStatus,
            reason: nextStatus === 'disabled' ? (statusReason.value || null) : null,
        });

        ui.pushToast(nextStatus === 'disabled' ? t('usersPage.statusDisabledSuccess') : t('usersPage.statusEnabledSuccess'));
        closeStatusModal();
        await loadUsers(pagination.current_page);
    } catch (err) {
        error.value = err.response?.data?.message || t('usersPage.unableToUpdateStatus');
    }
};

const goToPage = async (page) => {
    if (typeof page !== 'number' || page < 1 || page > pagination.last_page || page === pagination.current_page) {
        return;
    }

    await loadUsers(page);
};

onMounted(async () => {
    await loadLookups();
    await loadUsers(1);
});
</script>

<template>
    <AppShell>
        <section class="tracky-users-page">
            <header class="tracky-projects__head">
                <div>
                    <h2>{{ t('usersPage.title') }}</h2>
                </div>
                <div class="tracky-projects__head-actions">
                    <button class="tracky-btn tracky-btn--primary" type="button" @click="openCreateModal">
                        <span>+</span>
                        <span>{{ t('usersPage.addUser') }}</span>
                    </button>
                </div>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <section class="tracky-card tracky-users-summary">
                <div class="tracky-users-summary__donut-block">
                    <div class="tracky-users-ring" :style="{ background: donutBackground }">
                        <div class="tracky-users-ring__center">
                            <strong>{{ reporterCount }}</strong>
                            <span>{{ t('usersPage.typeReporter') }}</span>
                        </div>
                    </div>
                </div>

                <div class="tracky-users-summary__municipalities">
                    <h3>{{ t('usersPage.reporterMunicipality') }}</h3>
                    <template v-if="municipalityBreakdown.length">
                        <div class="tracky-users-summary__municipality-row" v-for="row in municipalityBreakdown" :key="row.name">
                            <span>{{ row.name }}</span>
                            <strong>{{ row.total }}</strong>
                        </div>
                    </template>
                    <p class="tracky-subtle" v-else>{{ t('usersPage.noReporterMunicipality') }}</p>
                </div>

                <div class="tracky-users-summary__classification">
                    <h3>{{ t('usersPage.userClassification') }}</h3>
                    <p class="tracky-figure">{{ totalUsers }}</p>
                    <div class="tracky-beneficiary-bars">
                        <span v-for="segment in roleGroupBars" :key="segment.label" :style="{ width: segment.width, background: segment.color }" />
                    </div>
                    <div class="tracky-users-legend">
                        <span v-for="segment in roleGroupBars" :key="`${segment.label}-legend`">
                            <i :style="{ background: segment.color }" />{{ segment.label }}
                        </span>
                    </div>
                </div>
            </section>

            <section class="tracky-card tracky-users-table-card">
                <div class="tracky-projects__empty" v-if="loading">{{ t('common.loadingUsers') }}</div>

                <div class="tracky-projects-table-wrap" v-else-if="users.length">
                    <table class="tracky-projects-table">
                        <thead>
                        <tr>
                            <th>{{ t('nav.users') }}</th>
                            <th>{{ t('common.email') }}</th>
                            <th>{{ t('common.phone') }}</th>
                            <th>{{ t('usersPage.userType') }}</th>
                            <th>{{ t('common.role') }}</th>
                            <th>{{ t('usersPage.permissions') }}</th>
                            <th>{{ t('common.actions') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="user in users" :key="user.id" :class="{ 'row-disabled': user.status === 'disabled' }">
                            <td>
                                <strong>{{ user.name }}</strong>
                            </td>
                            <td>{{ user.email || '-' }}</td>
                            <td>{{ user.phone_e164 }}</td>
                            <td>
                                <span class="badge" :class="userTypeBadgeClass(user)">{{ userTypeLabel(user) }}</span>
                            </td>
                            <td>{{ roleLabel(user.role) }}</td>
                            <td>
                                <button class="tracky-btn tracky-btn--ghost tracky-btn--link" type="button" @click="openPermissionsModal(user)">
                                    {{ t('usersPage.setPermissions') }}
                                </button>
                            </td>
                            <td>
                                <div class="tracky-project-actions">
                                    <button
                                        class="tracky-btn tracky-btn--ghost"
                                        type="button"
                                        :disabled="user.status === 'active'"
                                        @click="openStatusModal(user)"
                                    >
                                        {{ user.status === 'active' ? t('common.active') : t('common.enable') }}
                                    </button>
                                    <button
                                        class="tracky-btn tracky-btn--ghost"
                                        type="button"
                                        :disabled="user.status === 'disabled'"
                                        @click="openStatusModal(user)"
                                    >
                                        {{ t('common.disable') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>

                <div class="tracky-projects__empty" v-else>
                    <h3>{{ t('usersPage.noUsers') }}</h3>
                    <p>{{ t('usersPage.noUsersBody') }}</p>
                </div>
            </section>

            <footer class="tracky-projects__pagination" v-if="!loading && pagination.last_page > 1">
                <p>{{ t('common.page', { page: pagination.current_page, total: pagination.last_page }) }}</p>
                <div class="tracky-page-buttons">
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.current_page <= 1" @click="goToPage(pagination.current_page - 1)">{{ t('common.previous') }}</button>
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
                    <button class="tracky-btn tracky-btn--ghost" type="button" :disabled="pagination.current_page >= pagination.last_page" @click="goToPage(pagination.current_page + 1)">{{ t('common.next') }}</button>
                </div>
            </footer>

            <div class="tracky-project-modal-backdrop" v-if="permissionsModalOpen" @click.self="closePermissionsModal">
                <article class="tracky-user-modal">
                    <header class="tracky-project-modal__head">
                        <div>
                            <h3>{{ t('usersPage.setPermissions') }}</h3>
                        </div>
                        <button class="tracky-btn tracky-btn--ghost" type="button" @click="closePermissionsModal">{{ t('common.close') }}</button>
                    </header>

                    <div class="tracky-user-modal__body">
                        <label class="field">
                            {{ t('usersPage.username') }}
                            <input v-model="editForm.name" type="text" placeholder="e.g. johndoe">
                            <span class="field-error" v-if="editErrors.name">{{ editErrors.name }}</span>
                        </label>

                        <label class="field">
                            {{ t('usersPage.emailAddress') }}
                            <input v-model="editForm.email" type="email" placeholder="john.doe@email.com">
                            <span class="field-error" v-if="editErrors.email">{{ editErrors.email }}</span>
                        </label>

                        <div class="inline-group">
                            <label class="field">
                                {{ t('usersPage.countryCode') }}
                                <input v-model="editForm.country_code" type="text" placeholder="+218">
                            </label>
                            <label class="field">
                                {{ t('usersPage.phoneNumber') }}
                                <input v-model="editForm.phone" type="text" placeholder="91 000 0000">
                                <span class="field-error" v-if="editErrors.phone">{{ editErrors.phone }}</span>
                            </label>
                        </div>

                        <div class="inline-group">
                            <label class="field">
                                {{ t('usersPage.setRole') }}
                                <select v-model="editForm.role">
                                    <option v-for="role in roles" :key="role.value" :value="role.value">{{ roleLabel(role.value) }}</option>
                                </select>
                                <span class="field-error" v-if="editErrors.role">{{ editErrors.role }}</span>
                            </label>

                            <label class="field">
                                {{ t('common.municipality') }}
                                <select v-model="editForm.municipality_id">
                                    <option value="">{{ t('common.noMunicipality') }}</option>
                                    <option v-for="municipality in municipalities" :key="municipality.id" :value="municipality.id">
                                        {{ municipality.name }}
                                    </option>
                                </select>
                                <span class="field-error" v-if="editErrors.municipality_id">{{ editErrors.municipality_id }}</span>
                            </label>
                        </div>

                        <div class="tracky-user-access-grid">
                            <label v-for="permission in permissionPreview" :key="permission.label" class="tracky-checkline tracky-checkline--disabled">
                                <input type="checkbox" :checked="permission.enabled" disabled>
                                <span>{{ permission.label }}</span>
                            </label>
                        </div>
                    </div>

                    <footer class="tracky-user-modal__footer">
                        <button class="tracky-btn tracky-btn--primary" type="button" @click="savePermissions">{{ t('common.saveChanges') }}</button>
                        <button class="tracky-btn tracky-btn--ghost" type="button" @click="closePermissionsModal">{{ t('common.cancel') }}</button>
                    </footer>
                </article>
            </div>

            <div class="tracky-project-modal-backdrop" v-if="createModalOpen" @click.self="closeCreateModal">
                <article class="tracky-user-modal">
                    <header class="tracky-project-modal__head">
                        <div>
                            <h3>{{ t('usersPage.addUser') }}</h3>
                            <p>{{ t('usersPage.createHint') }}</p>
                        </div>
                        <button class="tracky-btn tracky-btn--ghost" type="button" @click="closeCreateModal">{{ t('common.close') }}</button>
                    </header>

                    <div class="tracky-user-modal__body">
                        <label class="field">
                            {{ t('usersPage.fullName') }}
                            <input v-model="createForm.name" type="text" placeholder="e.g. John Doe">
                            <span class="field-error" v-if="createErrors.name">{{ createErrors.name }}</span>
                        </label>

                        <label class="field">
                            {{ t('usersPage.emailAddress') }}
                            <input v-model="createForm.email" type="email" placeholder="john.doe@email.com">
                            <span class="field-error" v-if="createErrors.email">{{ createErrors.email }}</span>
                        </label>

                        <div class="inline-group">
                            <label class="field">
                                {{ t('usersPage.countryCode') }}
                                <input v-model="createForm.country_code" type="text" placeholder="+218">
                            </label>
                            <label class="field">
                                {{ t('usersPage.phoneNumber') }}
                                <input v-model="createForm.phone" type="text" placeholder="91 000 0000">
                                <span class="field-error" v-if="createErrors.phone">{{ createErrors.phone }}</span>
                            </label>
                        </div>

                        <label class="field">
                            {{ t('usersPage.userRole') }}
                            <select v-model="createForm.role">
                                <option v-for="role in roles" :key="role.value" :value="role.value">{{ roleLabel(role.value) }}</option>
                            </select>
                            <span class="field-error" v-if="createErrors.role">{{ createErrors.role }}</span>
                        </label>

                        <label class="field">
                            {{ t('usersPage.organization') }}
                            <input v-model="createForm.organization" type="text" placeholder="e.g. UNDP Libya, Alkufraa Municipality">
                        </label>

                        <label class="field">
                            {{ t('usersPage.assignedRegion') }}
                            <select v-model="createForm.municipality_id">
                                <option value="">{{ t('common.select') }}</option>
                                <option v-for="municipality in municipalities" :key="municipality.id" :value="municipality.id">
                                    {{ municipality.name }}
                                </option>
                            </select>
                            <span class="field-error" v-if="createErrors.municipality_id">{{ createErrors.municipality_id }}</span>
                        </label>
                    </div>

                    <footer class="tracky-user-modal__footer">
                        <button class="tracky-btn tracky-btn--primary" type="button" @click="createUser">{{ t('common.create') }}</button>
                        <button class="tracky-btn tracky-btn--ghost" type="button" @click="closeCreateModal">{{ t('common.cancel') }}</button>
                    </footer>
                </article>
            </div>

            <div class="tracky-project-modal-backdrop" v-if="statusModalOpen" @click.self="closeStatusModal">
                <article class="tracky-user-modal tracky-user-modal--compact">
                    <header class="tracky-project-modal__head">
                        <div>
                            <h3>{{ pendingStatusUser?.status === 'active' ? t('usersPage.disableAccount') : t('usersPage.enableAccount') }}</h3>
                            <p v-if="pendingStatusUser">{{ pendingStatusUser.name }}</p>
                        </div>
                        <button class="tracky-btn tracky-btn--ghost" type="button" @click="closeStatusModal">{{ t('common.close') }}</button>
                    </header>

                    <div class="tracky-user-modal__body">
                        <label class="field" v-if="pendingStatusUser?.status === 'active'">
                            {{ t('usersPage.disableReasonOptional') }}
                            <input v-model="statusReason" type="text" :placeholder="t('usersPage.disableReasonPlaceholder')">
                        </label>
                        <p class="tracky-subtle" v-else>{{ t('usersPage.accessRestored') }}</p>
                    </div>

                    <footer class="tracky-user-modal__footer">
                        <button class="tracky-btn tracky-btn--primary" type="button" @click="confirmStatusChange">{{ t('common.confirm') }}</button>
                        <button class="tracky-btn tracky-btn--ghost" type="button" @click="closeStatusModal">{{ t('common.cancel') }}</button>
                    </footer>
                </article>
            </div>
        </section>
    </AppShell>
</template>
