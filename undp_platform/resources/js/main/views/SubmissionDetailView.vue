<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import AppShell from '../components/AppShell.vue';
import api from '../api';
import { useAuthStore } from '../stores/auth';
import { useUiStore } from '../stores/ui';

const route = useRoute();
const auth = useAuthStore();
const ui = useUiStore();
const { t } = useI18n();

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
const isApproved = computed(() => submission.value?.status === 'approved');

const actionLabel = computed(() => {
    if (actionModal.action === 'approve') return t('submissionDetail.approve');
    if (actionModal.action === 'reject') return t('submissionDetail.reject');
    if (actionModal.action === 'rework') return t('submissionDetail.rework');
    return t('submissionDetail.action');
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

const humanizeKey = (key) => key
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());

const formatSubmissionValue = (value) => {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    if (typeof value === 'boolean') {
        return value ? t('common.yes') : t('common.no');
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
            label: t(`submissionDetail.fieldLabels.${key}`) !== `submissionDetail.fieldLabels.${key}`
                ? t(`submissionDetail.fieldLabels.${key}`)
                : humanizeKey(key),
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
        error.value = err.response?.data?.message || t('submissionDetail.unableToLoad');
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
    if (isApproved.value) {
        return;
    }

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
        error.value = t('submissionDetail.commentRequiredError');
        return;
    }

    actionLoading.value = true;
    error.value = '';

    try {
        await api.post(`/submissions/${submission.value.id}/${actionModal.action}`, {
            comment: actionForm.comment || null,
        });

        ui.pushToast(t('submissionDetail.updatedSuccess', { action: actionLabel.value.toLowerCase() }));
        closeActionModal();
        await loadSubmission();
    } catch (err) {
        error.value = err.response?.data?.message || t('submissionDetail.unableToUpdate');
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
        error.value = err.response?.data?.message || t('submissionDetail.unableToPreview');
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
                <h2>{{ t('submissionDetail.title') }}</h2>
                <p class="panel__hint">{{ t('submissionDetail.hint') }}</p>
            </header>

            <p class="field-error" v-if="error">{{ error }}</p>

            <div v-if="loading">{{ t('common.loading') }}</div>

            <template v-else-if="submission">
                <div class="split-grid split-grid--wide">
                    <div class="detail-block sticky-block">
                        <h3>#{{ submission.id }} - {{ submission.title }}</h3>
                        <p>
                            <strong>{{ t('common.status') }}:</strong>
                            {{ submission.status_label }}
                            <span v-if="isApproved" class="status-pill status-pill--active">{{ t('statusLabels.approved') }}</span>
                        </p>
                        <p><strong>{{ t('common.project') }}:</strong> {{ submission.project?.name }}</p>
                        <p><strong>{{ t('common.municipality') }}:</strong> {{ submission.municipality?.name }}</p>
                        <p><strong>{{ t('validation.reporter') }}:</strong> {{ submission.reporter?.name }}</p>
                        <p><strong>{{ t('common.details') }}:</strong> {{ submission.details || '-' }}</p>
                        <p><strong>{{ t('submissionDetail.validationComment') }}:</strong> {{ submission.validation_comment || '-' }}</p>

                        <div class="inline-group" v-if="canValidate">
                            <button class="btn btn--primary" :disabled="isApproved" @click="openActionModal('approve')">{{ t('submissionDetail.approve') }}</button>
                            <button class="btn btn--warn" :disabled="isApproved" @click="openActionModal('rework')">{{ t('submissionDetail.rework') }}</button>
                            <button class="btn btn--danger" :disabled="isApproved" @click="openActionModal('reject')">{{ t('submissionDetail.reject') }}</button>
                        </div>
                    </div>

                    <div class="detail-block">
                        <h3>{{ t('submissionDetail.mediaEvidence') }}</h3>
                        <p class="panel__hint" v-if="!submission.media_assets?.length">{{ t('submissionDetail.noMedia') }}</p>
                        <div class="media-grid" v-else>
                            <button
                                class="media-thumb"
                                v-for="asset in submission.media_assets"
                                :key="asset.id"
                                type="button"
                                @click="previewMedia(asset)"
                            >
                                <strong>{{ asset.media_type.toUpperCase() }}</strong>
                                <span>{{ t('submissionDetail.mediaStatus') }}: {{ asset.status }}</span>
                                <small>#{{ asset.id }}</small>
                            </button>
                        </div>

                        <div class="media-preview" v-if="mediaPreview.assetId">
                            <p><strong>{{ t('submissionDetail.previewAsset') }}:</strong> #{{ mediaPreview.assetId }}</p>
                            <div v-if="mediaPreview.loading">{{ t('submissionDetail.loadingMedia') }}</div>
                            <template v-else-if="mediaPreview.url">
                                <img
                                    v-if="mediaPreview.mediaType === 'image'"
                                    :src="mediaPreview.url"
                                    :alt="t('submissionDetail.mediaEvidence')"
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
                    <h3>{{ t('submissionDetail.timeline') }}</h3>
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
                            <span>{{ event.actor?.name || t('common.system') }} ({{ event.actor?.role || '-' }})</span>
                            <small>{{ new Date(event.created_at).toLocaleString() }}</small>
                            <p v-if="event.comment">{{ event.comment }}</p>
                        </li>
                    </ul>

                    <div class="timeline-detail-card" v-if="selectedTimelineEvent">
                        <p><strong>{{ t('submissionDetail.selectedEvent') }}:</strong> {{ selectedTimelineEvent.to_status }}</p>
                        <p>
                            <strong>{{ t('submissionDetail.transition') }}:</strong>
                            {{ selectedTimelineEvent.from_status || t('statusLabels.start') }} → {{ selectedTimelineEvent.to_status }}
                        </p>
                        <p><strong>{{ t('common.actor') }}:</strong> {{ selectedTimelineEvent.actor?.name || t('common.system') }}</p>
                        <p><strong>{{ t('common.role') }}:</strong> {{ selectedTimelineEvent.actor?.role || '-' }}</p>
                        <p><strong>{{ t('common.timestamp') }}:</strong> {{ new Date(selectedTimelineEvent.created_at).toLocaleString() }}</p>
                        <p v-if="selectedTimelineEvent.comment"><strong>{{ t('common.comment') }}:</strong> {{ selectedTimelineEvent.comment }}</p>
                        <button class="tracky-btn tracky-btn--soft" type="button" @click="detailsModalOpen = true">
                            {{ t('submissionDetail.openSnapshot') }}
                        </button>
                    </div>
                </div>
            </template>
        </section>

        <section class="modal-backdrop" v-if="actionModal.visible">
            <article class="modal-card">
                <h3>{{ actionLabel }} {{ t('submissionDetail.title') }}</h3>
                <p class="panel__hint">{{ t('submissionDetail.confirmDecision') }}</p>

                <select
                    v-if="actionModal.action === 'reject' || actionModal.action === 'rework'"
                    v-model="actionForm.reason_code"
                    @change="applyReasonTemplate"
                >
                    <option value="">{{ t('submissionDetail.selectReasonTemplate') }}</option>
                    <option v-for="reason in reasonOptions" :key="reason.code" :value="reason.code">
                        {{ reason.label }}
                    </option>
                </select>

                <textarea
                    v-model="actionForm.comment"
                    :placeholder="actionModal.action === 'approve' ? t('submissionDetail.commentOptional') : t('submissionDetail.commentRequired')"
                />

                <div class="inline-group">
                    <button class="btn btn--primary" :disabled="actionLoading" @click="submitAction">
                        {{ actionLoading ? t('submissionDetail.submitting') : `${t('common.apply')} ${actionLabel}` }}
                    </button>
                    <button class="btn btn--ghost" :disabled="actionLoading" @click="closeActionModal">{{ t('common.cancel') }}</button>
                </div>
            </article>
        </section>

        <section class="tracky-project-modal-backdrop" v-if="detailsModalOpen && submission" @click.self="closeDetailsModal">
            <article class="tracky-project-modal">
                <header class="tracky-project-modal__head">
                    <div>
                        <h3>{{ t('submissionDetail.snapshotTitle') }}</h3>
                        <p>#{{ submission.id }} - {{ submission.title }}</p>
                    </div>
                    <div class="tracky-project-modal__head-actions">
                        <button class="tracky-btn tracky-btn--ghost" type="button" @click="closeDetailsModal">{{ t('common.close') }}</button>
                    </div>
                </header>

                <div class="tracky-project-modal__body">
                    <section class="tracky-project-modal__column">
                        <div class="tracky-project-section tracky-project-section--no-divider">
                            <h4>{{ t('submissionDetail.timelineEvent') }}</h4>
                            <ul class="tracky-project-stats-list" v-if="selectedTimelineEvent">
                                <li><span>{{ t('submissionDetail.toStatus') }}</span><strong>{{ selectedTimelineEvent.to_status }}</strong></li>
                                <li><span>{{ t('submissionDetail.fromStatus') }}</span><strong>{{ selectedTimelineEvent.from_status || t('statusLabels.start') }}</strong></li>
                                <li><span>{{ t('common.actor') }}</span><strong>{{ selectedTimelineEvent.actor?.name || t('common.system') }}</strong></li>
                                <li><span>{{ t('common.role') }}</span><strong>{{ selectedTimelineEvent.actor?.role || '-' }}</strong></li>
                                <li><span>{{ t('common.timestamp') }}</span><strong>{{ new Date(selectedTimelineEvent.created_at).toLocaleString() }}</strong></li>
                            </ul>
                            <p v-if="selectedTimelineEvent?.comment"><strong>{{ t('common.comment') }}:</strong> {{ selectedTimelineEvent.comment }}</p>
                        </div>

                        <div class="tracky-project-section">
                            <h4>{{ t('submissionDetail.overview') }}</h4>
                            <ul class="tracky-project-stats-list">
                                <li><span>{{ t('common.status') }}</span><strong>{{ submission.status_label }}</strong></li>
                                <li><span>{{ t('common.project') }}</span><strong>{{ submission.project?.name || '-' }}</strong></li>
                                <li><span>{{ t('common.municipality') }}</span><strong>{{ submission.municipality?.name || '-' }}</strong></li>
                                <li><span>{{ t('validation.reporter') }}</span><strong>{{ submission.reporter?.name || '-' }}</strong></li>
                                <li><span>{{ t('common.submittedAt') }}</span><strong>{{ submission.submitted_at ? new Date(submission.submitted_at).toLocaleString() : '-' }}</strong></li>
                                <li><span>{{ t('common.validatedAt') }}</span><strong>{{ submission.validated_at ? new Date(submission.validated_at).toLocaleString() : '-' }}</strong></li>
                            </ul>
                        </div>

                        <div class="tracky-project-section">
                            <h4>{{ t('submissionDetail.reportedData') }}</h4>
                            <ul class="tracky-project-stats-list" v-if="submissionDataEntries.length">
                                <li v-for="entry in submissionDataEntries" :key="entry.key">
                                    <span>{{ entry.label }}</span>
                                    <strong>{{ entry.value }}</strong>
                                </li>
                            </ul>
                            <p v-else>{{ t('submissionDetail.noStructuredData') }}</p>
                        </div>
                    </section>

                    <section class="tracky-project-modal__column">
                        <div class="tracky-project-section tracky-project-section--no-divider">
                            <h4>{{ t('submissionDetail.attachments') }}</h4>
                            <div class="tracky-project-media-grid">
                                <button
                                    type="button"
                                    class="tracky-project-media-tile tracky-project-media-tile--button"
                                    v-for="asset in submission.media_assets || []"
                                    :key="asset.id"
                                    @click="previewMedia(asset)"
                                >
                                    <strong>{{ asset.media_type === 'video' ? t('common.video') : t('common.image') }}</strong>
                                    <small>#{{ asset.id }}</small>
                                    <span>{{ asset.status }}</span>
                                </button>
                                <div class="tracky-project-media-empty" v-if="!(submission.media_assets || []).length">
                                    {{ t('submissionDetail.noUploadedMedia') }}
                                </div>
                            </div>
                        </div>

                        <div class="tracky-project-section" v-if="mediaPreview.assetId">
                            <h4>{{ t('submissionDetail.attachmentPreview') }}</h4>
                            <p><strong>{{ t('submissionDetail.assetId') }}:</strong> #{{ mediaPreview.assetId }}</p>
                            <div v-if="mediaPreview.loading">{{ t('submissionDetail.loadingMedia') }}</div>
                            <template v-else-if="mediaPreview.url">
                                <img
                                    v-if="mediaPreview.mediaType === 'image'"
                                    :src="mediaPreview.url"
                                    :alt="t('submissionDetail.mediaEvidence')"
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
