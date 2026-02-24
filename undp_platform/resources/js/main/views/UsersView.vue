<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import AppShell from '../components/AppShell.vue';
import RoleBadge from '../components/RoleBadge.vue';
import api from '../api';
import { useUiStore } from '../stores/ui';

const ui = useUiStore();

const users = ref([]);
const roles = ref([]);
const municipalities = ref([]);
const loading = ref(false);
const error = ref('');

const selectedUser = ref(null);
const selectedUserSnapshot = ref(null);

const createModalOpen = ref(false);
const roleChangeModalOpen = ref(false);
const statusModalOpen = ref(false);
const pendingStatusUser = ref(null);
const statusReason = ref('');

const filters = reactive({
    search: '',
    role: '',
    status: '',
    municipality_id: '',
});

const sort = reactive({
    by: 'created_at',
    dir: 'desc',
});

const pagination = reactive({
    current_page: 1,
    last_page: 1,
    per_page: 15,
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
});

const editForm = reactive({
    name: '',
    email: '',
    role: '',
    municipality_id: '',
    preferred_locale: 'ar',
});

const createErrors = reactive({});
const editErrors = reactive({});

const roleMap = computed(() => {
    return roles.value.reduce((acc, role) => {
        acc[role.value] = role;
        return acc;
    }, {});
});

const createRoleDescription = computed(() => roleMap.value[createForm.role]?.description || '');
const editRoleDescription = computed(() => roleMap.value[editForm.role]?.description || '');

const roleChangeSummary = computed(() => {
    if (!selectedUser.value) {
        return null;
    }

    return {
        from: roleMap.value[selectedUser.value.role]?.label || selectedUser.value.role,
        to: roleMap.value[editForm.role]?.label || editForm.role,
    };
});

const usersExportUrl = computed(() => {
    const params = new URLSearchParams({
        type: 'users',
        search: filters.search || '',
        role: filters.role || '',
        status: filters.status || '',
        municipality_id: filters.municipality_id || '',
        sort_by: sort.by,
        sort_dir: sort.dir,
    });

    return `/api/exports/csv?${params.toString()}`;
});

const canCreate = computed(() => {
    const digits = createForm.phone.replace(/\D/g, '');
    return Boolean(createForm.name.trim() && digits.length >= 6 && createForm.role);
});

const hasEditChanges = computed(() => {
    if (!selectedUserSnapshot.value) {
        return false;
    }

    return JSON.stringify(editForm) !== JSON.stringify(selectedUserSnapshot.value);
});

const visiblePages = computed(() => {
    const pages = [];
    const start = Math.max(1, pagination.current_page - 2);
    const end = Math.min(pagination.last_page, pagination.current_page + 2);

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    return pages;
});

const sortIndicator = (column) => {
    if (sort.by !== column) {
        return '';
    }

    return sort.dir === 'asc' ? 'ASC' : 'DESC';
};

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
        const params = {
            ...filters,
            page,
            per_page: pagination.per_page,
            sort_by: sort.by,
            sort_dir: sort.dir,
        };

        const { data } = await api.get('/users', { params });
        users.value = data.data || [];
        pagination.current_page = data.current_page || 1;
        pagination.last_page = data.last_page || 1;
        pagination.total = data.total || users.value.length;
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load users.';
    } finally {
        loading.value = false;
    }
};

const applyFilters = async () => {
    await loadUsers(1);
};

const goToPage = async (page) => {
    if (page < 1 || page > pagination.last_page || page === pagination.current_page) {
        return;
    }

    await loadUsers(page);
};

const toggleSort = async (column) => {
    if (sort.by === column) {
        sort.dir = sort.dir === 'asc' ? 'desc' : 'asc';
    } else {
        sort.by = column;
        sort.dir = 'asc';
    }

    await loadUsers(1);
};

const openCreateModal = () => {
    createModalOpen.value = true;
    error.value = '';
    resetValidationState(createErrors);
};

const closeCreateModal = () => {
    createModalOpen.value = false;
};

const createUser = async () => {
    resetValidationState(createErrors);

    try {
        await api.post('/users', {
            ...createForm,
            municipality_id: createForm.municipality_id || null,
            phone: createForm.phone.replace(/\D/g, ''),
        });

        Object.assign(createForm, {
            name: '',
            email: '',
            country_code: '+218',
            phone: '',
            role: 'reporter',
            municipality_id: '',
            preferred_locale: 'ar',
        });

        ui.pushToast('User created successfully.');
        createModalOpen.value = false;
        await loadUsers(1);
    } catch (err) {
        if (err.response?.status === 422) {
            applyValidationErrors(createErrors, err);
        }
        error.value = err.response?.data?.message || 'Unable to create user.';
    }
};

const startEdit = (user) => {
    selectedUser.value = user;
    error.value = '';
    resetValidationState(editErrors);

    Object.assign(editForm, {
        name: user.name || '',
        email: user.email || '',
        role: user.role,
        municipality_id: user.municipality?.id || '',
        preferred_locale: user.preferred_locale || 'ar',
    });

    selectedUserSnapshot.value = { ...editForm };
};

const closeEditDrawer = () => {
    selectedUser.value = null;
    selectedUserSnapshot.value = null;
    roleChangeModalOpen.value = false;
    resetValidationState(editErrors);
};

const submitUserUpdate = async (confirmRoleChange = false) => {
    if (!selectedUser.value || !hasEditChanges.value) {
        return;
    }

    resetValidationState(editErrors);

    try {
        await api.put(`/users/${selectedUser.value.id}`, {
            ...editForm,
            municipality_id: editForm.municipality_id || null,
            confirm_role_change: confirmRoleChange,
        });

        ui.pushToast('User updated successfully.');
        closeEditDrawer();
        await loadUsers(pagination.current_page);
    } catch (err) {
        if (err.response?.status === 422) {
            applyValidationErrors(editErrors, err);
        }
        error.value = err.response?.data?.message || 'Unable to update user.';
    }
};

const updateUser = async () => {
    if (!selectedUser.value || !hasEditChanges.value) {
        return;
    }

    if (editForm.role !== selectedUser.value.role) {
        roleChangeModalOpen.value = true;
        return;
    }

    await submitUserUpdate(false);
};

const confirmRoleChange = async () => {
    roleChangeModalOpen.value = false;
    await submitUserUpdate(true);
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

        ui.pushToast(`User ${nextStatus === 'disabled' ? 'disabled' : 'enabled'} successfully.`);
        closeStatusModal();
        await loadUsers(pagination.current_page);
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to update status.';
    }
};

onMounted(async () => {
    await loadLookups();
    await loadUsers();
});
</script>

<template>
    <AppShell>
        <section class="panel">
            <header class="panel__header">
                <h2>User Management</h2>
                <p class="panel__hint">Manage users, roles, and account status centrally.</p>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <div class="toolbar">
                <input v-model="filters.search" placeholder="Search by name, email, or phone">
                <select v-model="filters.role">
                    <option value="">All roles</option>
                    <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option>
                </select>
                <select v-model="filters.status">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="disabled">Disabled</option>
                </select>
                <select v-model="filters.municipality_id">
                    <option value="">All municipalities</option>
                    <option v-for="municipality in municipalities" :key="municipality.id" :value="municipality.id">
                        {{ municipality.name }}
                    </option>
                </select>
                <button class="btn btn--primary" @click="applyFilters">Apply</button>
                <button class="btn btn--ghost" @click="openCreateModal">Create User</button>
                <a class="btn btn--ghost" :href="usersExportUrl">Export CSV</a>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th><button class="table-sort-btn" @click="toggleSort('name')">Name {{ sortIndicator('name') }}</button></th>
                        <th><button class="table-sort-btn" @click="toggleSort('phone')">Phone {{ sortIndicator('phone') }}</button></th>
                        <th><button class="table-sort-btn" @click="toggleSort('email')">Email {{ sortIndicator('email') }}</button></th>
                        <th><button class="table-sort-btn" @click="toggleSort('role')">Role {{ sortIndicator('role') }}</button></th>
                        <th>Municipality</th>
                        <th><button class="table-sort-btn" @click="toggleSort('status')">Status {{ sortIndicator('status') }}</button></th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-if="loading">
                        <td colspan="7">Loading...</td>
                    </tr>
                    <tr v-else-if="!users.length">
                        <td colspan="7">No users found.</td>
                    </tr>
                    <tr v-for="user in users" :key="user.id" :class="{ 'row-disabled': user.status === 'disabled' }">
                        <td>{{ user.name }}</td>
                        <td>{{ user.phone_e164 }}</td>
                        <td>{{ user.email || '-' }}</td>
                        <td><RoleBadge :role="user.role" /></td>
                        <td>{{ user.municipality?.name || '-' }}</td>
                        <td>
                            <span class="status-pill" :class="`status-pill--${user.status}`">{{ user.status }}</span>
                        </td>
                        <td>
                            <button class="btn btn--ghost" @click="startEdit(user)">Edit</button>
                            <button class="btn btn--ghost" @click="openStatusModal(user)">
                                {{ user.status === 'active' ? 'Disable' : 'Enable' }}
                            </button>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar">
                <button class="btn btn--ghost" :disabled="pagination.current_page <= 1" @click="goToPage(pagination.current_page - 1)">Prev</button>
                <button
                    v-for="page in visiblePages"
                    :key="page"
                    class="btn"
                    :class="page === pagination.current_page ? 'btn--primary' : 'btn--ghost'"
                    @click="goToPage(page)"
                >
                    {{ page }}
                </button>
                <button class="btn btn--ghost" :disabled="pagination.current_page >= pagination.last_page" @click="goToPage(pagination.current_page + 1)">Next</button>
                <span class="pagination-meta">Total: {{ pagination.total }}</span>
            </div>
        </section>

        <aside class="user-edit-drawer" v-if="selectedUser">
            <header class="user-edit-drawer__head">
                <h3>Edit User #{{ selectedUser.id }}</h3>
                <button class="btn btn--ghost" @click="closeEditDrawer">Close</button>
            </header>

            <div class="form-grid">
                <label class="field">
                    Name *
                    <input v-model="editForm.name" placeholder="Full name">
                    <span class="field-error" v-if="editErrors.name">{{ editErrors.name }}</span>
                </label>

                <label class="field">
                    Email
                    <input v-model="editForm.email" placeholder="Email address">
                    <span class="field-error" v-if="editErrors.email">{{ editErrors.email }}</span>
                </label>

                <label class="field">
                    Role *
                    <select v-model="editForm.role">
                        <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option>
                    </select>
                    <span class="panel__hint" v-if="editRoleDescription">{{ editRoleDescription }}</span>
                    <span class="field-error" v-if="editErrors.role">{{ editErrors.role }}</span>
                </label>

                <label class="field">
                    Municipality
                    <select v-model="editForm.municipality_id">
                        <option value="">No municipality</option>
                        <option v-for="municipality in municipalities" :key="municipality.id" :value="municipality.id">
                            {{ municipality.name }}
                        </option>
                    </select>
                    <span class="field-error" v-if="editErrors.municipality_id">{{ editErrors.municipality_id }}</span>
                </label>

                <label class="field">
                    Preferred language
                    <select v-model="editForm.preferred_locale">
                        <option value="ar">Arabic</option>
                        <option value="en">English</option>
                    </select>
                </label>

                <p class="panel__hint" v-if="selectedUser.role !== editForm.role">
                    Role change: <strong>{{ roleChangeSummary?.from }}</strong> -> <strong>{{ roleChangeSummary?.to }}</strong>
                </p>

                <div class="inline-group">
                    <button class="btn btn--primary" :disabled="!hasEditChanges" @click="updateUser">Save Changes</button>
                    <button class="btn btn--ghost" @click="closeEditDrawer">Cancel</button>
                </div>
            </div>
        </aside>

        <div class="modal-backdrop" v-if="createModalOpen">
            <div class="modal-card">
                <h3>Create User</h3>

                <label class="field">
                    Name *
                    <input v-model="createForm.name" placeholder="Full name">
                    <span class="field-error" v-if="createErrors.name">{{ createErrors.name }}</span>
                </label>

                <label class="field">
                    Email
                    <input v-model="createForm.email" placeholder="Email address">
                    <span class="field-error" v-if="createErrors.email">{{ createErrors.email }}</span>
                </label>

                <div class="inline-group">
                    <label class="field">
                        Country code *
                        <input v-model="createForm.country_code" placeholder="+218">
                    </label>
                    <label class="field">
                        Phone *
                        <input v-model="createForm.phone" placeholder="Phone number">
                        <span class="field-error" v-if="createErrors.phone">{{ createErrors.phone }}</span>
                    </label>
                </div>

                <label class="field">
                    Role *
                    <select v-model="createForm.role">
                        <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option>
                    </select>
                    <span class="panel__hint" v-if="createRoleDescription">{{ createRoleDescription }}</span>
                    <span class="field-error" v-if="createErrors.role">{{ createErrors.role }}</span>
                </label>

                <label class="field">
                    Municipality
                    <select v-model="createForm.municipality_id">
                        <option value="">No municipality</option>
                        <option v-for="municipality in municipalities" :key="municipality.id" :value="municipality.id">
                            {{ municipality.name }}
                        </option>
                    </select>
                    <span class="field-error" v-if="createErrors.municipality_id">{{ createErrors.municipality_id }}</span>
                </label>

                <label class="field">
                    Preferred language
                    <select v-model="createForm.preferred_locale">
                        <option value="ar">Arabic</option>
                        <option value="en">English</option>
                    </select>
                </label>

                <div class="inline-group">
                    <button class="btn btn--primary" :disabled="!canCreate" @click="createUser">Send Invite</button>
                    <button class="btn btn--ghost" @click="closeCreateModal">Cancel</button>
                </div>
            </div>
        </div>

        <div class="modal-backdrop" v-if="roleChangeModalOpen">
            <div class="modal-card">
                <h3>Confirm Role Change</h3>
                <p class="panel__hint">Please confirm this role update before saving.</p>
                <p><strong>Current role:</strong> {{ roleChangeSummary?.from }}</p>
                <p><strong>New role:</strong> {{ roleChangeSummary?.to }}</p>
                <div class="inline-group">
                    <button class="btn btn--warn" @click="confirmRoleChange">Confirm</button>
                    <button class="btn btn--ghost" @click="roleChangeModalOpen = false">Cancel</button>
                </div>
            </div>
        </div>

        <div class="modal-backdrop" v-if="statusModalOpen">
            <div class="modal-card">
                <h3>
                    {{ pendingStatusUser?.status === 'active' ? 'Disable user account' : 'Enable user account' }}
                </h3>
                <p class="panel__hint" v-if="pendingStatusUser">
                    User: {{ pendingStatusUser.name }} ({{ pendingStatusUser.phone_e164 }})
                </p>

                <label class="field" v-if="pendingStatusUser?.status === 'active'">
                    Disable reason (optional)
                    <input v-model="statusReason" placeholder="Reason for disable">
                </label>

                <div class="inline-group">
                    <button class="btn btn--warn" @click="confirmStatusChange">Confirm</button>
                    <button class="btn btn--ghost" @click="closeStatusModal">Cancel</button>
                </div>
            </div>
        </div>
    </AppShell>
</template>
