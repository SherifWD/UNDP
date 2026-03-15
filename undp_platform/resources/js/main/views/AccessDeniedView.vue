<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <main class="auth-page">
        <section class="auth-card">
            <h1>{{ t('accessDenied.title') }}</h1>
            <p>{{ t('accessDenied.body') }}</p>
            <router-link class="btn btn--primary" :to="{ name: 'home' }">{{ t('accessDenied.backHome') }}</router-link>
        </section>
    </main>
</template>
