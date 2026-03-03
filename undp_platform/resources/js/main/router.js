import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './stores/auth';

const LoginView = () => import('./views/LoginView.vue');
const OtpView = () => import('./views/OtpView.vue');
const HomeView = () => import('./views/HomeView.vue');
const ProjectsView = () => import('./views/ProjectsView.vue');
const ProjectSubmissionsView = () => import('./views/ProjectSubmissionsView.vue');
const UsersView = () => import('./views/UsersView.vue');
const ValidationWorklistView = () => import('./views/ValidationWorklistView.vue');
const SubmissionDetailView = () => import('./views/SubmissionDetailView.vue');
const AuditLogView = () => import('./views/AuditLogView.vue');
const SettingsView = () => import('./views/SettingsView.vue');
const ReportsView = () => import('./views/ReportsView.vue');
const PartnerDashboardView = () => import('./views/PartnerDashboardView.vue');
const MunicipalOverviewView = () => import('./views/MunicipalOverviewView.vue');
const AccessDeniedView = () => import('./views/AccessDeniedView.vue');

const routes = [
    {
        path: '/login',
        name: 'login',
        component: LoginView,
        meta: { guestOnly: true },
    },
    {
        path: '/otp',
        name: 'otp',
        component: OtpView,
        meta: { guestOnly: true },
    },
    {
        path: '/',
        name: 'home',
        component: HomeView,
        meta: { requiresAuth: true },
    },
    {
        path: '/projects',
        name: 'projects',
        component: ProjectsView,
        meta: { requiresAuth: true, permission: 'projects.view' },
    },
    {
        path: '/projects/:id/submissions',
        name: 'project-submissions',
        component: ProjectSubmissionsView,
        meta: {
            requiresAuth: true,
            anyPermissions: ['submissions.view.own', 'submissions.view.municipality', 'submissions.view.all', 'submissions.view.approved_aggregated'],
        },
    },
    {
        path: '/users',
        name: 'users',
        component: UsersView,
        meta: { requiresAuth: true, permission: 'users.view' },
    },
    {
        path: '/validation',
        name: 'validation',
        component: ValidationWorklistView,
        meta: { requiresAuth: true, permission: 'submissions.validate' },
    },
    {
        path: '/submissions/:id',
        name: 'submission-detail',
        component: SubmissionDetailView,
        meta: {
            requiresAuth: true,
            anyPermissions: ['submissions.view.own', 'submissions.view.municipality', 'submissions.view.all', 'submissions.view.approved_aggregated'],
        },
    },
    {
        path: '/audit-logs',
        name: 'audit-logs',
        component: AuditLogView,
        meta: { requiresAuth: true, permission: 'audit.view' },
    },
    {
        path: '/settings',
        name: 'settings',
        component: SettingsView,
        meta: { requiresAuth: true },
    },
    {
        path: '/municipal-overview',
        name: 'municipal-overview',
        component: MunicipalOverviewView,
        meta: {
            requiresAuth: true,
            anyPermissions: ['dashboards.view.municipality', 'dashboards.view.system'],
        },
    },
    {
        path: '/reports',
        name: 'reports',
        component: ReportsView,
        meta: {
            requiresAuth: true,
            anyPermissions: ['dashboards.view.system', 'dashboards.view.municipality'],
        },
    },
    {
        path: '/partner-dashboard',
        name: 'partner-dashboard',
        component: PartnerDashboardView,
        meta: {
            requiresAuth: true,
            permission: 'dashboards.view.partner',
        },
    },
    {
        path: '/access-denied',
        name: 'access-denied',
        component: AccessDeniedView,
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: '/',
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();

    if (auth.token && !auth.user) {
        await auth.fetchMe();
    }

    if (to.meta.requiresAuth && !auth.isAuthenticated) {
        return { name: 'login' };
    }

    if (to.meta.guestOnly && auth.isAuthenticated) {
        return { name: 'home' };
    }

    if (to.meta.permission && !auth.hasPermission(to.meta.permission)) {
        return { name: 'access-denied' };
    }

    if (to.meta.anyPermissions && !to.meta.anyPermissions.some((permission) => auth.hasPermission(permission))) {
        return { name: 'access-denied' };
    }

    return true;
});

export default router;
