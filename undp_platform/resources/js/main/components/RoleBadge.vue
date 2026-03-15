<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    role: {
        type: String,
        required: true,
    },
});

const { t } = useI18n();

const label = computed(() => t(`roles.${props.role}`));
</script>

<template>
    <span class="role-badge" :class="`role-badge--${props.role}`">
        {{ label !== `roles.${props.role}` ? label : props.role }}
    </span>
</template>
