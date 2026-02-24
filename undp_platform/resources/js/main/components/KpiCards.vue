<script setup>
const props = defineProps({
    kpis: {
        type: Object,
        default: () => ({}),
    },
});

const cards = [
    { key: 'total_submissions', label: 'Total Submissions' },
    { key: 'approved', label: 'Approved' },
    { key: 'under_review', label: 'Under Review' },
    { key: 'rework_requested', label: 'Rework Requested' },
    { key: 'rejected', label: 'Rejected' },
    { key: 'approved_total', label: 'Approved Total' },
    { key: 'municipalities_covered', label: 'Municipalities Covered' },
    { key: 'projects_covered', label: 'Projects Covered' },
];
</script>

<template>
    <div class="kpi-grid">
        <article v-for="card in cards" :key="card.key" class="kpi-card" v-show="props.kpis[card.key] !== undefined">
            <h4>{{ card.label }}</h4>
            <p>{{ props.kpis[card.key] }}</p>
        </article>
    </div>
</template>
