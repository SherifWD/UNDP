<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { setLocale } from '../i18n';

const { locale } = useI18n();

const isArabic = computed(() => locale.value === 'ar');
</script>

<template>
    <div class="lang-switch">
        <button
            class="lang-switch__btn"
            :class="{ 'lang-switch__btn--active': isArabic }"
            @click="setLocale('ar')"
        >
            العربية
        </button>
        <button
            class="lang-switch__btn"
            :class="{ 'lang-switch__btn--active': !isArabic }"
            @click="setLocale('en')"
        >
            English
        </button>
    </div>
</template>
