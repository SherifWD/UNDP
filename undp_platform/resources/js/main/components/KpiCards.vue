<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    kpis: {
        type: Object,
        default: () => ({}),
    },
});

const { t } = useI18n();

const cardKeys = [
    'total_submissions',
    'approved',
    'under_review',
    'rework_requested',
    'rejected',
    'submitted',
    'queued',
    'draft',
    'pending_validation',
    'approval_rate_percent',
    'rejection_rate_percent',
    'approved_total',
    'municipalities_covered',
    'projects_covered',
    'approved_last_30_days',
    'approved_last_7_days',
    'total_actual_beneficiaries',
    'average_completion_percentage',
];

const cards = computed(() => cardKeys.map((key) => ({
    key,
    label: t(`kpis.${key}`),
})));
</script>

<template>
    <div class="kpi-grid">
        <article v-for="card in cards" :key="card.key" class="kpi-card" v-show="props.kpis[card.key] !== undefined">
            <h4>{{ card.label }}</h4>
            <p>{{ props.kpis[card.key] }}</p>
        </article>
    </div>
</template>
