<script setup>
const props = defineProps({
    role: {
        type: String,
        required: true,
    },
});

const roleLabels = {
    reporter: 'Reporter',
    municipal_focal_point: 'Municipal Focal Point',
    undp_admin: 'UNDP Admin',
    partner_donor_viewer: 'Partner/Donor Viewer',
    auditor: 'Auditor',
};
</script>

<template>
    <span class="role-badge" :class="`role-badge--${props.role}`">
        {{ roleLabels[props.role] || props.role }}
    </span>
</template>
