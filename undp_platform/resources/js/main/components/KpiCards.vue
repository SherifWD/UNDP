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
    { key: 'submitted', label: 'Submitted' },
    { key: 'queued', label: 'Queued' },
    { key: 'draft', label: 'Draft' },
    { key: 'pending_validation', label: 'Pending Validation' },
    { key: 'approval_rate_percent', label: 'Approval Rate %' },
    { key: 'rejection_rate_percent', label: 'Rejection Rate %' },
    { key: 'approved_total', label: 'Approved Total' },
    { key: 'municipalities_covered', label: 'Municipalities Covered' },
    { key: 'projects_covered', label: 'Projects Covered' },
    { key: 'approved_last_30_days', label: 'Approved (30 Days)' },
    { key: 'approved_last_7_days', label: 'Approved (7 Days)' },
    { key: 'total_actual_beneficiaries', label: 'Actual Beneficiaries' },
    { key: 'average_completion_percentage', label: 'Avg Completion %' },
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
