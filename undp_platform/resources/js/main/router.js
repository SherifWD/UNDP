import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from './stores/auth';
import LoginView from './views/LoginView.vue';
import OtpView from './views/OtpView.vue';
import HomeView from './views/HomeView.vue';
import ProjectsView from './views/ProjectsView.vue';
import UsersView from './views/UsersView.vue';
import ValidationWorklistView from './views/ValidationWorklistView.vue';
import SubmissionDetailView from './views/SubmissionDetailView.vue';
import AuditLogView from './views/AuditLogView.vue';
import ReportsView from './views/ReportsView.vue';
import PartnerDashboardView from './views/PartnerDashboardView.vue';
import MunicipalOverviewView from './views/MunicipalOverviewView.vue';
import AccessDeniedView from './views/AccessDeniedView.vue';

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
            anyPermissions: ['submissions.view.own', 'submissions.view.municipality', 'submissions.view.all'],
        },
    },
    {
        path: '/audit-logs',
        name: 'audit-logs',
        component: AuditLogView,
        meta: { requiresAuth: true, permission: 'audit.view' },
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
