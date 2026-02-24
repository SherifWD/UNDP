<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppShell from '../components/AppShell.vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';

const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();

const municipalities = ref([]);
const projects = ref([]);
const loading = ref(false);
const error = ref('');

const editingProjectId = ref(null);

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

const filters = reactive({
    municipality_id: '',
    status: '',
});

const canManageMunicipalities = computed(() => auth.hasPermission('municipalities.manage'));
const canManageProjects = computed(() => auth.hasPermission('projects.manage'));

const filteredProjects = computed(() => {
    return projects.value.filter((project) => {
        if (filters.municipality_id && Number(project.municipality?.id) !== Number(filters.municipality_id)) {
            return false;
        }

        if (filters.status && project.status !== filters.status) {
            return false;
        }

        return true;
    });
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
        const [municipalityRes, projectRes] = await Promise.all([
            api.get('/municipalities'),
            api.get('/projects'),
        ]);

        municipalities.value = municipalityRes.data.data || [];
        projects.value = projectRes.data.data || [];
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

        ui.pushToast('Municipality saved successfully.');
        await loadData();
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to save municipality.';
    }
};

const saveProject = async () => {
    if (!canManageProjects.value) {
        return;
    }

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

        resetProjectForm();
        await loadData();
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to save project.';
    }
};

const startEditProject = (project) => {
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

onMounted(loadData);
</script>

<template>
    <AppShell>
        <section class="panel">
            <header class="panel__header">
                <h2>{{ t('projectsPage.title') }}</h2>
                <p class="panel__hint">{{ t('projectsPage.subtitle') }}</p>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>
            <p class="panel__hint" v-if="loading">{{ t('common.loading') }}</p>

            <section class="split-grid" v-if="canManageMunicipalities || canManageProjects">
                <article class="form-grid" v-if="canManageMunicipalities">
                    <h3>{{ t('projectsPage.createMunicipality') }}</h3>
                    <label class="field">
                        {{ t('projectsPage.municipalityNameEn') }}
                        <input v-model="municipalityForm.name_en" type="text" />
                    </label>
                    <label class="field">
                        {{ t('projectsPage.municipalityNameAr') }}
                        <input v-model="municipalityForm.name_ar" type="text" />
                    </label>
                    <label class="field">
                        {{ t('projectsPage.municipalityCode') }}
                        <input v-model="municipalityForm.code" type="text" />
                    </label>
                    <button class="btn btn--primary" type="button" @click="saveMunicipality">
                        {{ t('projectsPage.saveMunicipality') }}
                    </button>
                </article>

                <article class="form-grid" v-if="canManageProjects">
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
                        <input v-model="projectForm.name_en" type="text" />
                    </label>
                    <label class="field">
                        {{ t('projectsPage.projectNameAr') }}
                        <input v-model="projectForm.name_ar" type="text" />
                    </label>
                    <label class="field">
                        {{ t('projectsPage.description') }}
                        <textarea v-model="projectForm.description" rows="3" />
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
                            <input v-model="projectForm.latitude" type="number" step="any" />
                        </label>
                        <label class="field">
                            {{ t('projectsPage.longitude') }}
                            <input v-model="projectForm.longitude" type="number" step="any" />
                        </label>
                    </div>
                    <div class="inline-group">
                        <button class="btn btn--primary" type="button" @click="saveProject">
                            {{ editingProjectId ? t('projectsPage.updateProject') : t('projectsPage.saveProject') }}
                        </button>
                        <button class="btn btn--ghost" v-if="editingProjectId" type="button" @click="resetProjectForm">
                            {{ t('projectsPage.cancelEdit') }}
                        </button>
                    </div>
                </article>
            </section>

            <section class="split-grid">
                <article class="detail-block">
                    <header class="panel__header">
                        <h3>{{ t('projectsPage.municipalities') }}</h3>
                    </header>
                    <ul class="stat-list" v-if="municipalities.length">
                        <li v-for="municipality in municipalities" :key="municipality.id">
                            <span>{{ municipality.name }} ({{ municipality.code || '-' }})</span>
                        </li>
                    </ul>
                    <p class="panel__hint" v-else>{{ t('projectsPage.noMunicipalities') }}</p>
                </article>

                <article class="detail-block">
                    <header class="panel__header">
                        <h3>{{ t('projectsPage.projects') }}</h3>
                    </header>

                    <div class="toolbar">
                        <select v-model="filters.municipality_id">
                            <option value="">{{ t('projectsPage.allMunicipalities') }}</option>
                            <option v-for="municipality in municipalities" :key="municipality.id" :value="municipality.id">
                                {{ municipality.name }}
                            </option>
                        </select>
                        <select v-model="filters.status">
                            <option value="">{{ t('dashboard.allStatus') }}</option>
                            <option value="active">{{ t('dashboard.active') }}</option>
                            <option value="archived">{{ t('dashboard.archived') }}</option>
                        </select>
                    </div>

                    <div class="table-wrap" v-if="filteredProjects.length">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>{{ t('projectsPage.projects') }}</th>
                                <th>{{ t('projectsPage.municipality') }}</th>
                                <th>{{ t('projectsPage.status') }}</th>
                                <th>{{ t('projectsPage.updatedAt') }}</th>
                                <th v-if="canManageProjects"></th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr v-for="project in filteredProjects" :key="project.id">
                                <td>{{ project.id }}</td>
                                <td>{{ project.name }}</td>
                                <td>{{ project.municipality?.name || '-' }}</td>
                                <td>{{ project.status }}</td>
                                <td>{{ project.last_update_at ? new Date(project.last_update_at).toLocaleString() : '-' }}</td>
                                <td v-if="canManageProjects">
                                    <button class="btn btn--ghost" type="button" @click="startEditProject(project)">
                                        {{ t('projectsPage.editProject') }}
                                    </button>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="panel__hint" v-else>{{ t('projectsPage.noProjects') }}</p>
                </article>
            </section>
        </section>
    </AppShell>
</template>
