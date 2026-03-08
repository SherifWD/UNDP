<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import AppShell from '../components/AppShell.vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';

const route = useRoute();
const auth = useAuthStore();
const ui = useUiStore();

const submission = ref(null);
const timeline = ref([]);
const selectedTimelineEventId = ref(null);
const reasons = ref([]);
const loading = ref(false);
const actionLoading = ref(false);
const error = ref('');
const detailsModalOpen = ref(false);

const mediaPreview = reactive({
    loading: false,
    assetId: null,
    url: '',
    mediaType: '',
});

const actionModal = reactive({
    visible: false,
    action: '',
});

const actionForm = reactive({
    reason_code: '',
    comment: '',
});

const canValidate = computed(() => auth.hasPermission('submissions.validate'));

const actionLabel = computed(() => {
    if (actionModal.action === 'approve') return 'Approve';
    if (actionModal.action === 'reject') return 'Reject';
    if (actionModal.action === 'rework') return 'Request Rework';
    return 'Action';
});

const reasonOptions = computed(() => {
    if (actionModal.action === 'reject') {
        return reasons.value.filter((item) => item.action === 'reject');
    }

    if (actionModal.action === 'rework') {
        return reasons.value.filter((item) => item.action === 'rework');
    }

    return [];
});

const selectedReason = computed(() => reasonOptions.value.find((item) => item.code === actionForm.reason_code) || null);
const selectedTimelineEvent = computed(() => timeline.value.find((event) => event.id === selectedTimelineEventId.value) || null);

const FIELD_LABELS = {
    report_type: 'Report Type',
    reporting_period_label: 'Reporting Period',
    project_code: 'Project Code',
    project_name: 'Project Name',
    goal_area: 'Goal Area',
    component_category: 'Component Category',
    project_status: 'Project Status',
    delay_reason: 'Reason for Delay',
    progress_impression: 'Impression of Work Progress',
    physical_progress: 'Physical Progress Seen',
    approximate_completion_percentage: 'Approximate Completion Percentage',
    additional_observations: 'Additional Observations',
    is_project_being_used: 'Project Being Used',
    user_categories: 'User Categories',
    is_used_as_intended: 'Used As Intended',
    functional_status: 'Functional Status',
    negative_environmental_impact: 'Negative Environmental Impact',
    negative_impact_details: 'Environmental Impact Details',
    actual_beneficiaries: 'Actual Beneficiaries',
    location_label: 'Location of Observation',
    location_source: 'Location Source',
    location_accuracy_meters: 'Location Accuracy (m)',
    summary_of_observation: 'Summary of Observation',
    key_updates: 'Key Updates',
    challenges_risks_issues: 'Challenges / Risks / Issues',
    risk_description: 'Risk Description',
    delay_constraint: 'Delay / Constraint',
    impact_description: 'Impact Description',
    notes: 'Additional Notes',
    observed_at: 'Observed At',
    confirm_accuracy: 'Confirmed Accuracy',
};

const humanizeKey = (key) => key
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());

const formatSubmissionValue = (value) => {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (Array.isArray(value)) {
        if (value.length === 0) {
            return '-';
        }

        return value
            .map((item) => (typeof item === 'string' ? item : JSON.stringify(item)))
            .join(', ');
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
};

const submissionDataEntries = computed(() => {
    const data = submission.value?.data;

    if (!data || typeof data !== 'object') {
        return [];
    }

    return Object.entries(data)
        .filter(([, value]) => {
            if (value === null || value === undefined || value === '') {
                return false;
            }

            if (Array.isArray(value) && value.length === 0) {
                return false;
            }

            return true;
        })
        .map(([key, value]) => ({
            key,
            label: FIELD_LABELS[key] || humanizeKey(key),
            value: formatSubmissionValue(value),
        }));
});

const loadSubmission = async () => {
    loading.value = true;
    error.value = '';

    try {
        const { data } = await api.get(`/submissions/${route.params.id}`);
        submission.value = data.submission;
        timeline.value = data.timeline || [];
        selectedTimelineEventId.value = timeline.value[0]?.id ?? null;
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load submission details.';
    } finally {
        loading.value = false;
    }
};

const loadReasons = async () => {
    try {
        const { data } = await api.get('/workflow/reasons');
        reasons.value = data.data || [];
    } catch {
        reasons.value = [];
    }
};

const openActionModal = (action) => {
    actionModal.visible = true;
    actionModal.action = action;
    actionForm.reason_code = '';
    actionForm.comment = '';
};

const closeActionModal = () => {
    actionModal.visible = false;
    actionModal.action = '';
    actionForm.reason_code = '';
    actionForm.comment = '';
};

const applyReasonTemplate = () => {
    if (!selectedReason.value) {
        return;
    }

    if (!actionForm.comment.trim()) {
        actionForm.comment = selectedReason.value.label;
    }
};

const submitAction = async () => {
    if (!submission.value || !actionModal.action) return;

    if ((actionModal.action === 'reject' || actionModal.action === 'rework') && !actionForm.comment.trim()) {
        error.value = 'Comment is required for reject and rework.';
        return;
    }

    actionLoading.value = true;
    error.value = '';

    try {
        await api.post(`/submissions/${submission.value.id}/${actionModal.action}`, {
            comment: actionForm.comment || null,
        });

        ui.pushToast(`Submission ${actionLabel.value.toLowerCase()}d successfully.`);
        closeActionModal();
        await loadSubmission();
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to update submission status.';
    } finally {
        actionLoading.value = false;
    }
};

const previewMedia = async (asset) => {
    mediaPreview.loading = true;
    mediaPreview.assetId = asset.id;
    mediaPreview.url = '';
    mediaPreview.mediaType = asset.media_type;
    error.value = '';

    try {
        const { data } = await api.get(`/media/${asset.id}/download-url`);
        mediaPreview.url = data.url;
    } catch (err) {
        error.value = err.response?.data?.message || 'Unable to load media preview.';
    } finally {
        mediaPreview.loading = false;
    }
};

const selectTimelineEvent = (event) => {
    selectedTimelineEventId.value = event.id;
    detailsModalOpen.value = true;
};

const closeDetailsModal = () => {
    detailsModalOpen.value = false;
};

onMounted(async () => {
    await Promise.all([
        loadSubmission(),
        loadReasons(),
    ]);
});
</script>

<template>
    <AppShell>
        <section class="panel">
            <header class="panel__header">
                <h2>Submission Detail</h2>
                <p class="panel__hint">Validation workspace with immutable timeline and evidence preview.</p>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <div v-if="loading">Loading...</div>

            <template v-else-if="submission">
                <div class="split-grid split-grid--wide">
                    <div class="detail-block sticky-block">
                        <h3>#{{ submission.id }} - {{ submission.title }}</h3>
                        <p><strong>Status:</strong> {{ submission.status_label }}</p>
                        <p><strong>Project:</strong> {{ submission.project?.name }}</p>
                        <p><strong>Municipality:</strong> {{ submission.municipality?.name }}</p>
                        <p><strong>Reporter:</strong> {{ submission.reporter?.name }}</p>
                        <p><strong>Details:</strong> {{ submission.details || '-' }}</p>
                        <p><strong>Validation Comment:</strong> {{ submission.validation_comment || '-' }}</p>

                        <div class="inline-group" v-if="canValidate">
                            <button class="btn btn--primary" @click="openActionModal('approve')">Approve</button>
                            <button class="btn btn--warn" @click="openActionModal('rework')">Request Rework</button>
                            <button class="btn btn--danger" @click="openActionModal('reject')">Reject</button>
                        </div>
                    </div>

                    <div class="detail-block">
                        <h3>Media Evidence</h3>
                        <p class="panel__hint" v-if="!submission.media_assets?.length">No media attached.</p>
                        <div class="media-grid" v-else>
                            <button
                                class="media-thumb"
                                v-for="asset in submission.media_assets"
                                :key="asset.id"
                                type="button"
                                @click="previewMedia(asset)"
                            >
                                <strong>{{ asset.media_type.toUpperCase() }}</strong>
                                <span>Status: {{ asset.status }}</span>
                                <small>#{{ asset.id }}</small>
                            </button>
                        </div>

                        <div class="media-preview" v-if="mediaPreview.assetId">
                            <p><strong>Preview Asset:</strong> #{{ mediaPreview.assetId }}</p>
                            <div v-if="mediaPreview.loading">Loading media...</div>
                            <template v-else-if="mediaPreview.url">
                                <img
                                    v-if="mediaPreview.mediaType === 'image'"
                                    :src="mediaPreview.url"
                                    alt="Submission media"
                                    class="media-preview__image"
                                >
                                <video
                                    v-else
                                    controls
                                    :src="mediaPreview.url"
                                    class="media-preview__video"
                                />
                            </template>
                        </div>
                    </div>
                </div>

                <div class="detail-block">
                    <h3>Timeline</h3>
                    <ul class="timeline">
                        <li
                            v-for="event in timeline"
                            :key="event.id"
                            :class="{ 'timeline-item--active': selectedTimelineEventId === event.id }"
                            tabindex="0"
                            @click="selectTimelineEvent(event)"
                            @keydown.enter.prevent="selectTimelineEvent(event)"
                            @keydown.space.prevent="selectTimelineEvent(event)"
                        >
                            <strong>{{ event.to_status }}</strong>
                            <span>{{ event.actor?.name || 'System' }} ({{ event.actor?.role || '-' }})</span>
                            <small>{{ new Date(event.created_at).toLocaleString() }}</small>
                            <p v-if="event.comment">{{ event.comment }}</p>
                        </li>
                    </ul>

                    <div class="timeline-detail-card" v-if="selectedTimelineEvent">
                        <p><strong>Selected Event:</strong> {{ selectedTimelineEvent.to_status }}</p>
                        <p>
                            <strong>Transition:</strong>
                            {{ selectedTimelineEvent.from_status || 'start' }} → {{ selectedTimelineEvent.to_status }}
                        </p>
                        <p><strong>Actor:</strong> {{ selectedTimelineEvent.actor?.name || 'System' }}</p>
                        <p><strong>Role:</strong> {{ selectedTimelineEvent.actor?.role || '-' }}</p>
                        <p><strong>Timestamp:</strong> {{ new Date(selectedTimelineEvent.created_at).toLocaleString() }}</p>
                        <p v-if="selectedTimelineEvent.comment"><strong>Comment:</strong> {{ selectedTimelineEvent.comment }}</p>
                        <button class="tracky-btn tracky-btn--soft" type="button" @click="detailsModalOpen = true">
                            Open Full Submission Snapshot
                        </button>
                    </div>
                </div>
            </template>
        </section>

        <section class="modal-backdrop" v-if="actionModal.visible">
            <article class="modal-card">
                <h3>{{ actionLabel }} Submission</h3>
                <p class="panel__hint">Confirm your decision and provide context for auditability.</p>

                <select
                    v-if="actionModal.action === 'reject' || actionModal.action === 'rework'"
                    v-model="actionForm.reason_code"
                    @change="applyReasonTemplate"
                >
                    <option value="">Select reason template (optional)</option>
                    <option v-for="reason in reasonOptions" :key="reason.code" :value="reason.code">
                        {{ reason.label }}
                    </option>
                </select>

                <textarea
                    v-model="actionForm.comment"
                    :placeholder="actionModal.action === 'approve' ? 'Comment (optional)' : 'Comment (required)'"
                />

                <div class="inline-group">
                    <button class="btn btn--primary" :disabled="actionLoading" @click="submitAction">
                        {{ actionLoading ? 'Submitting...' : `Confirm ${actionLabel}` }}
                    </button>
                    <button class="btn btn--ghost" :disabled="actionLoading" @click="closeActionModal">Cancel</button>
                </div>
            </article>
        </section>

        <section class="tracky-project-modal-backdrop" v-if="detailsModalOpen && submission" @click.self="closeDetailsModal">
            <article class="tracky-project-modal">
                <header class="tracky-project-modal__head">
                    <div>
                        <h3>Submission Snapshot</h3>
                        <p>#{{ submission.id }} - {{ submission.title }}</p>
                    </div>
                    <div class="tracky-project-modal__head-actions">
                        <button class="tracky-btn tracky-btn--ghost" type="button" @click="closeDetailsModal">Close</button>
                    </div>
                </header>

                <div class="tracky-project-modal__body">
                    <section class="tracky-project-modal__column">
                        <div class="tracky-project-section tracky-project-section--no-divider">
                            <h4>Timeline Event</h4>
                            <ul class="tracky-project-stats-list" v-if="selectedTimelineEvent">
                                <li><span>To Status</span><strong>{{ selectedTimelineEvent.to_status }}</strong></li>
                                <li><span>From Status</span><strong>{{ selectedTimelineEvent.from_status || 'start' }}</strong></li>
                                <li><span>Actor</span><strong>{{ selectedTimelineEvent.actor?.name || 'System' }}</strong></li>
                                <li><span>Role</span><strong>{{ selectedTimelineEvent.actor?.role || '-' }}</strong></li>
                                <li><span>Timestamp</span><strong>{{ new Date(selectedTimelineEvent.created_at).toLocaleString() }}</strong></li>
                            </ul>
                            <p v-if="selectedTimelineEvent?.comment"><strong>Comment:</strong> {{ selectedTimelineEvent.comment }}</p>
                        </div>

                        <div class="tracky-project-section">
                            <h4>Submission Overview</h4>
                            <ul class="tracky-project-stats-list">
                                <li><span>Status</span><strong>{{ submission.status_label }}</strong></li>
                                <li><span>Project</span><strong>{{ submission.project?.name || '-' }}</strong></li>
                                <li><span>Municipality</span><strong>{{ submission.municipality?.name || '-' }}</strong></li>
                                <li><span>Reporter</span><strong>{{ submission.reporter?.name || '-' }}</strong></li>
                                <li><span>Submitted At</span><strong>{{ submission.submitted_at ? new Date(submission.submitted_at).toLocaleString() : '-' }}</strong></li>
                                <li><span>Validated At</span><strong>{{ submission.validated_at ? new Date(submission.validated_at).toLocaleString() : '-' }}</strong></li>
                            </ul>
                        </div>

                        <div class="tracky-project-section">
                            <h4>Reported Data</h4>
                            <ul class="tracky-project-stats-list" v-if="submissionDataEntries.length">
                                <li v-for="entry in submissionDataEntries" :key="entry.key">
                                    <span>{{ entry.label }}</span>
                                    <strong>{{ entry.value }}</strong>
                                </li>
                            </ul>
                            <p v-else>No structured payload fields were submitted.</p>
                        </div>
                    </section>

                    <section class="tracky-project-modal__column">
                        <div class="tracky-project-section tracky-project-section--no-divider">
                            <h4>Attachments</h4>
                            <div class="tracky-project-media-grid">
                                <button
                                    type="button"
                                    class="tracky-project-media-tile tracky-project-media-tile--button"
                                    v-for="asset in submission.media_assets || []"
                                    :key="asset.id"
                                    @click="previewMedia(asset)"
                                >
                                    <strong>{{ asset.media_type === 'video' ? 'Video' : 'Image' }}</strong>
                                    <small>#{{ asset.id }}</small>
                                    <span>{{ asset.status }}</span>
                                </button>
                                <div class="tracky-project-media-empty" v-if="!(submission.media_assets || []).length">
                                    No uploaded media.
                                </div>
                            </div>
                        </div>

                        <div class="tracky-project-section" v-if="mediaPreview.assetId">
                            <h4>Attachment Preview</h4>
                            <p><strong>Asset ID:</strong> #{{ mediaPreview.assetId }}</p>
                            <div v-if="mediaPreview.loading">Loading media...</div>
                            <template v-else-if="mediaPreview.url">
                                <img
                                    v-if="mediaPreview.mediaType === 'image'"
                                    :src="mediaPreview.url"
                                    alt="Submission media"
                                    class="media-preview__image"
                                >
                                <video
                                    v-else
                                    controls
                                    :src="mediaPreview.url"
                                    class="media-preview__video"
                                />
                            </template>
                        </div>
                    </section>
                </div>
            </article>
        </section>
    </AppShell>
</template>
