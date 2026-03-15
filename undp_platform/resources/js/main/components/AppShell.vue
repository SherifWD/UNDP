<script setup>
import { computed, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import LanguageSwitch from './LanguageSwitch.vue';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const showNotifications = ref(false);
const notificationQuery = ref('');
const notificationTab = ref('all');

const NAV_ICON_PATHS = {
    dashboard: 'M4 4h7v7H4zm9 0h7v7h-7zM4 13h7v7H4zm9 4h7v3h-7z',
    submission: 'M7 4h7l4 4v12H7z M14 4v4h4',
    users: 'M12 12a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm-5 8v-1a5 5 0 0 1 10 0v1',
    audit: 'M6 4h12v16H6z M9 8h6 M9 12h6 M9 16h4',
    municipal: 'M4 20h16 M6 20V8h12v12 M10 20v-5h4v5',
    reports: 'M5 18V9 M12 18V5 M19 18v-7',
    partner: 'M4 18h16 M6 15l4-4 3 2 5-6',
    settings: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm0-4v2m0 12v2m8-8h-2M6 12H4m11 5-1.5-1.5M8.5 8.5 7 7m8 0-1.5 1.5M8.5 15.5 7 17',
};

const navItems = computed(() => {
    const all = [
        { name: 'home', label: t('nav.home'), icon: 'dashboard', group: 'main' },
        { name: 'projects', label: t('nav.projects'), permission: 'projects.view', icon: 'reports', group: 'main' },
        { name: 'validation', label: t('nav.submissions'), permission: 'submissions.validate', icon: 'submission', group: 'main' },
        {
            name: 'reports',
            label: t('nav.reports'),
            anyPermissions: ['dashboards.view.system', 'dashboards.view.municipality'],
            icon: 'reports',
            group: 'main',
        },
        {
            name: 'municipal-overview',
            label: t('nav.municipal'),
            permission: 'dashboards.view.municipality',
            icon: 'municipal',
            group: 'main',
        },
        { name: 'partner-dashboard', label: t('nav.partner'), permission: 'dashboards.view.partner', icon: 'partner', group: 'main' },
        { name: 'users', label: t('nav.users'), permission: 'users.view', icon: 'users', group: 'main' },
        { name: 'audit-logs', label: t('nav.audit'), permission: 'audit.view', icon: 'audit', group: 'main' },
    ];

    return all.filter((item) => {
        if (item.roles && !item.roles.includes(auth.role)) return false;
        if (item.permission) return auth.hasPermission(item.permission);
        if (item.anyPermissions) return item.anyPermissions.some((permission) => auth.hasPermission(permission));
        return true;
    });
});

const mainNavItems = computed(() => navItems.value.filter((item) => item.group === 'main'));

const currentPageLabel = computed(() => {
    const routeLabels = {
        home: t('nav.home'),
        projects: t('nav.projects'),
        'project-submissions': t('nav.projects'),
        validation: t('nav.submissions'),
        'submission-detail': t('nav.submissions'),
        users: t('nav.users'),
        'audit-logs': t('nav.audit'),
        settings: t('shell.settings'),
        reports: t('nav.reports'),
        'municipal-overview': t('nav.municipal'),
        'partner-dashboard': t('nav.partner'),
    };
    return routeLabels[route.name] || t('nav.home');
});

const firstName = computed(() => {
    const fullName = auth.user?.name || 'User';
    return String(fullName).split(' ')[0];
});

const logout = async () => {
    await auth.logout();
    await router.push({ name: 'login' });
};

const closeNotifications = () => {
    showNotifications.value = false;
};

const iconPath = (name) => NAV_ICON_PATHS[name] || NAV_ICON_PATHS.dashboard;
</script>

<template>
    <div class="shell shell--tracky">
        <aside class="shell__sidebar">
            <header class="shell__brand">
                <!-- <div class="shell__brand-icon">
                    <span class="shell__brand-shape" aria-hidden="true" />
                </div> -->
                <div class="shell__brand-icon">
                <h1>RASD</h1>
                </div>
                <button class="shell__collapse-btn" type="button" aria-label="Toggle sidebar">
                    <span class="shell__collapse-icon" aria-hidden="true" />
                </button>
            </header>

            <section class="shell__nav-group">
                <p class="shell__group-label">{{ t('shell.main') }}</p>
                <nav class="shell__nav">
                    <RouterLink
                        v-for="item in mainNavItems"
                        :key="item.name"
                        :to="{ name: item.name }"
                        class="shell__nav-link"
                        active-class="shell__nav-link--active"
                    >
                        <span class="shell__nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24">
                                <path :d="iconPath(item.icon)" />
                            </svg>
                        </span>
                        <span>{{ item.label }}</span>
                    </RouterLink>
                </nav>
            </section>

            <section class="shell__nav-group">
                <p class="shell__group-label">{{ t('shell.other') }}</p>
                <RouterLink
                    :to="{ name: 'settings' }"
                    class="shell__other-link"
                    active-class="shell__nav-link--active"
                >
                    <span class="shell__nav-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path :d="iconPath('settings')" />
                        </svg>
                    </span>
                    <span>{{ t('shell.settings') }}</span>
                </RouterLink>
            </section>

            <footer class="shell__profile">
                <div class="shell__profile-avatar">{{ firstName.charAt(0).toUpperCase() }}</div>
                <div class="shell__profile-meta">
                    <p class="shell__profile-name" :title="auth.user?.name || 'User'">{{ auth.user?.name || 'User' }}</p>
                    <p class="shell__profile-email" :title="auth.user?.email || auth.user?.phone_e164 || ''">{{ auth.user?.email || auth.user?.phone_e164 || '' }}</p>
                </div>
                <button class="shell__logout-btn" type="button" @click="logout" aria-label="Logout">
                    <span class="shell__logout-icon" aria-hidden="true" />
                </button>
            </footer>
        </aside>

        <main class="shell__main">
            <header class="shell__header">
                <div class="shell__crumb">
                    <span class="shell__crumb-icon" aria-hidden="true" />
                    <span>{{ currentPageLabel }}</span>
                </div>

                <div class="shell__header-actions">
                    <LanguageSwitch />
                    <button class="shell__notify-btn" type="button" @click="showNotifications = !showNotifications" aria-label="Notifications">
                        <span class="shell__bell-icon" aria-hidden="true" />
                        <span class="shell__notify-dot" aria-hidden="true" />
                    </button>
                </div>
            </header>

            <section class="shell__content">
                <slot />
            </section>

            <button
                v-if="showNotifications"
                class="shell__drawer-backdrop"
                type="button"
                aria-label="Close notifications"
                @click="closeNotifications"
            />

            <aside class="shell__notification-drawer" v-if="showNotifications" @click.stop>
                <div class="shell__notification-head">
                    <input v-model="notificationQuery" :placeholder="t('shell.notificationsSearch')">
                    <div class="shell__notification-actions">
                        <button type="button" class="notif-btn notif-btn--accept">OK</button>
                        <button type="button" class="notif-btn notif-btn--delete">DEL</button>
                    </div>
                </div>
                <div class="shell__notification-tabs">
                    <button type="button" :class="{ active: notificationTab === 'all' }" @click="notificationTab = 'all'">{{ t('shell.notificationTabs.all') }}</button>
                    <button type="button" :class="{ active: notificationTab === 'submissions' }" @click="notificationTab = 'submissions'">{{ t('shell.notificationTabs.submissions') }}</button>
                    <button type="button" :class="{ active: notificationTab === 'workflow' }" @click="notificationTab = 'workflow'">{{ t('shell.notificationTabs.workflow') }}</button>
                    <button type="button" :class="{ active: notificationTab === 'users' }" @click="notificationTab = 'users'">{{ t('shell.notificationTabs.users') }}</button>
                    <button type="button" :class="{ active: notificationTab === 'security' }" @click="notificationTab = 'security'">{{ t('shell.notificationTabs.security') }}</button>
                </div>
                <div class="shell__notification-empty">
                    <div class="shell__notification-icon" aria-hidden="true">
                        <span class="shell__bell-icon" />
                    </div>
                    <h4>{{ t('shell.notificationsEmptyTitle') }}</h4>
                    <p>{{ t('shell.notificationsEmptyBody') }}</p>
                </div>
            </aside>
        </main>
    </div>
</template>
