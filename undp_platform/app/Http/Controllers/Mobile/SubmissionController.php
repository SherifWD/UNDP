<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\SubmissionStatus;
use App\Jobs\ProcessMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\Submission;
use App\Models\SubmissionStatusEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProjectAccessService;
use App\Services\SubmissionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SubmissionController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $statusFilterOptions = array_merge(SubmissionStatus::values(), ['rework']);

        $validator = Validator::make($request->all(), [
            'tab' => ['nullable', Rule::in(['submitted', 'drafts', 'all'])],
            'status' => ['nullable', Rule::in($statusFilterOptions)],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        $validated = $validator->validated();
        $query = Submission::query()
            ->with(['project.municipality', 'reporter'])
            ->latest('updated_at');

        SubmissionAccessService::scope($request->user(), $query);

        if (($validated['tab'] ?? 'submitted') === 'drafts') {
            $query->where('status', SubmissionStatus::DRAFT->value);
        } elseif (($validated['tab'] ?? 'submitted') === 'submitted') {
            $query->where('status', '!=', SubmissionStatus::DRAFT->value);
        }

        $status = $this->normalizeSubmissionStatusFilter($validated['status'] ?? null);
        if ($status) {
            $query->where('status', $status);
        }

        if (! empty($validated['search'])) {
            $search = trim((string) $validated['search']);

            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('details', 'like', "%{$search}%")
                    ->orWhere('id', $search)
                    ->orWhereHas('project', function ($projectQuery) use ($search): void {
                        $projectQuery
                            ->where('name_en', 'like', "%{$search}%")
                            ->orWhere('name_ar', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = (int) ($validated['per_page'] ?? $validated['limit'] ?? 25);
        $page = (int) ($validated['page'] ?? 1);
        $submissions = $query->paginate($perPage, ['*'], 'page', $page);

        $countsBase = Submission::query();
        SubmissionAccessService::scope($request->user(), $countsBase);

        return $this->successResponse([
            'items' => $submissions->getCollection()
                ->map(fn (Submission $submission): array => $this->serializeSubmissionCard($submission))
                ->values(),
            'counts' => [
                'submitted' => (clone $countsBase)->where('status', '!=', SubmissionStatus::DRAFT->value)->count(),
                'drafts' => (clone $countsBase)->where('status', SubmissionStatus::DRAFT->value)->count(),
                'approved' => (clone $countsBase)->where('status', SubmissionStatus::APPROVED->value)->count(),
                'rejected' => (clone $countsBase)->where('status', SubmissionStatus::REJECTED->value)->count(),
                'rework' => (clone $countsBase)->where('status', SubmissionStatus::REWORK_REQUESTED->value)->count(),
            ],
            'pagination' => [
                'page' => $submissions->currentPage(),
                'per_page' => $submissions->perPage(),
                'total' => $submissions->total(),
                'total_pages' => $submissions->lastPage(),
                'has_previous' => $submissions->currentPage() > 1,
                'has_more' => $submissions->hasMorePages(),
            ],
            'filters' => [
                'tab' => $validated['tab'] ?? 'submitted',
                'status' => $status,
                'search' => $validated['search'] ?? null,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('submissions.create')) {
            return $this->errorResponse('Access denied.', 403);
        }

        $validator = $this->makeSubmissionValidator($request, true);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        $validated = $validator->validated();

        if (! empty($validated['client_uuid'])) {
            $existing = Submission::query()
                ->where('client_uuid', $validated['client_uuid'])
                ->where('reporter_id', $request->user()->id)
                ->first();

            if ($existing) {
                $existing->load([
                    'project.municipality',
                    'reporter',
                    'validator',
                    'mediaAssets',
                    'statusEvents.actor',
                ]);

                return $this->successResponse([
                    'submission' => $this->serializeSubmissionDetail($existing),
                    'idempotent_reuse' => true,
                ], 'Draft already exists. Returning the existing submission.');
            }
        }

        return $this->persistSubmission($request, $validated);
    }

    public function update(Request $request, Submission $submission): JsonResponse
    {
        if (! SubmissionAccessService::canView($request->user(), $submission)
            || (int) $submission->reporter_id !== (int) $request->user()->id) {
            return $this->errorResponse('Access denied.', 403);
        }

        if (! $this->canEditSubmission($submission)) {
            return $this->errorResponse('This submission can no longer be edited.', 422);
        }

        if ($this->isMultipartFileUpdateWithoutPostTransport($request)) {
            return $this->errorResponse(
                'Multipart submission updates must be sent as POST with `_method=PUT` so PHP can parse uploaded files.',
                422,
            );
        }

        $validator = $this->makeSubmissionValidator($request, false);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        return $this->persistSubmission($request, $validator->validated(), $submission);
    }

    public function show(Request $request, Submission $submission): JsonResponse
    {
        if (! SubmissionAccessService::canView($request->user(), $submission)) {
            return $this->errorResponse('Access denied.', 403);
        }

        $submission->load([
            'project.municipality',
            'reporter',
            'validator',
            'mediaAssets',
            'statusEvents.actor',
        ]);

        return $this->successResponse([
            'submission' => $this->serializeSubmissionDetail($submission),
            'timeline' => $submission->statusEvents->map(fn ($event): array => [
                'id' => $event->id,
                'from_status' => $event->from_status,
                'from_status_label' => $event->from_status ? $this->mobileSubmissionStatusLabel($event->from_status) : null,
                'to_status' => $event->to_status,
                'to_status_label' => $this->mobileSubmissionStatusLabel($event->to_status),
                'comment' => $event->comment,
                'actor' => $event->actor ? [
                    'id' => $event->actor->id,
                    'name' => $event->actor->name,
                    'role' => $event->actor->role,
                ] : null,
                'created_at' => optional($event->created_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    public function summary(Request $request, Submission $submission): JsonResponse
    {
        if (! SubmissionAccessService::canView($request->user(), $submission)) {
            return $this->errorResponse('Access denied.', 403);
        }

        $submission->load([
            'project.municipality',
            'reporter',
            'validator',
            'mediaAssets',
        ]);

        $detail = $this->serializeSubmissionDetail($submission);

        return $this->successResponse([
            'submission' => $detail,
            'summary' => $detail['summary'],
        ]);
    }

    public function mediaIndex(Request $request, Submission $submission): JsonResponse
    {
        $user = $request->user();

        if (! SubmissionAccessService::canView($user, $submission)
            || ! $this->canViewSubmissionMedia($user, $submission)) {
            return $this->errorResponse('Access denied.', 403);
        }

        $submission->load('mediaAssets');
        $mediaAssets = $this->serializeSubmissionMedia($submission);

        return $this->successResponse([
            'submission_id' => $submission->id,
            'media_assets' => $mediaAssets,
            'assets' => $mediaAssets,
        ]);
    }

    public function destroyMedia(Request $request, Submission $submission, MediaAsset $mediaAsset): JsonResponse
    {
        $user = $request->user();

        if (! SubmissionAccessService::canView($user, $submission)
            || (int) $submission->reporter_id !== (int) $user->id
            || ! $user->hasPermission('media.upload')) {
            return $this->errorResponse('Access denied.', 403);
        }

        if (! $this->canEditSubmission($submission)) {
            return $this->errorResponse('This submission can no longer be edited.', 422);
        }

        if ((int) $mediaAsset->submission_id !== (int) $submission->id) {
            return $this->errorResponse('Media asset does not belong to this submission.', 422);
        }

        $submissionMedia = collect($this->submissionMediaReferences($submission))
            ->filter(fn (array $item): bool => (int) ($item['id'] ?? 0) !== (int) $mediaAsset->id)
            ->values()
            ->all();

        $submission->forceFill([
            'media' => $submissionMedia,
        ])->save();

        try {
            Storage::disk($mediaAsset->disk)->delete($mediaAsset->object_key);
        } catch (\Throwable) {
            // Best effort physical cleanup. DB delete still proceeds.
        }

        $mediaAssetId = $mediaAsset->id;
        $mediaAsset->delete();

        AuditLogger::log(
            action: 'mobile.submissions.media_deleted',
            entityType: 'media_assets',
            entityId: $mediaAssetId,
            metadata: [
                'submission_id' => $submission->id,
                'source' => 'mobile',
            ],
            request: $request,
        );

        $submission->load('mediaAssets');
        $mediaAssets = $this->serializeSubmissionMedia($submission);

        return $this->successResponse([
            'submission_id' => $submission->id,
            'media_assets' => $mediaAssets,
            'assets' => $mediaAssets,
        ], 'Media removed successfully.');
    }

    private function makeSubmissionValidator(Request $request, bool $creating)
    {
        $uploadedAssetFiles = $this->uploadedAssetFiles($request);
        $input = $this->normalizeSubmissionInput($request->all());

        if ($uploadedAssetFiles !== []) {
            unset($input['assets']);
        }

        $rules = [
            'client_uuid' => ['nullable', 'uuid'],
            'project_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:projects,id'],
            'mode' => ['nullable', Rule::in(['draft', 'submit'])],
            'title' => ['nullable', 'string', 'max:255'],
            'report_type' => ['nullable', 'string', 'max:120'],
            'reporting_period_label' => ['nullable', 'string', 'max:120'],
            'component_category' => ['nullable', 'string', 'max:255'],
            'project_status' => ['nullable', Rule::in(array_keys(config('mobile.reporting.project_statuses', [])))],
            'delay_reason' => ['nullable', Rule::in(array_keys(config('mobile.reporting.delay_reasons', [])))],
            'progress_impression' => ['nullable', Rule::in(array_keys(config('mobile.reporting.progress_impressions', [])))],
            'physical_progress' => ['nullable', 'boolean'],
            'approximate_completion_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'additional_observations' => ['nullable', 'string', 'max:5000'],
            'is_project_being_used' => ['nullable', 'boolean'],
            'activities_started' => ['nullable', 'boolean'],
            'user_categories' => ['nullable', 'array'],
            'user_categories.*' => ['string', Rule::in(array_keys(config('mobile.reporting.user_categories', [])))],
            'is_used_as_intended' => ['nullable', 'boolean'],
            'functional_status' => ['nullable', Rule::in(array_keys(config('mobile.reporting.functional_statuses', [])))],
            'negative_environmental_impact' => ['nullable', 'boolean'],
            'negative_impact_details' => ['nullable', 'string', 'max:5000'],
            'actual_beneficiaries' => ['nullable', 'integer', 'min:0'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_label' => ['nullable', 'string', 'max:255'],
            'location_source' => ['nullable', Rule::in(['manual', 'gps'])],
            'location_accuracy_meters' => ['nullable', 'numeric', 'min:0'],
            'media' => ['nullable', 'array'],
            'media.*.id' => ['nullable', 'integer', 'exists:media_assets,id'],
            'media.*.type' => ['nullable', Rule::in(['image', 'video'])],
            'media.*.label' => ['nullable', 'string', 'max:255'],
            'assets' => ['nullable', 'array'],
            'assets.*.id' => ['nullable', 'integer', 'exists:media_assets,id'],
            'assets.*.type' => ['nullable', Rule::in(['image', 'video'])],
            'assets.*.label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'confirm_accuracy' => ['nullable', 'boolean'],
            'summary_of_observation' => ['nullable', 'string', 'max:5000'],
            'key_updates' => ['nullable', 'array'],
            'key_updates.*' => ['string', 'max:255'],
            'challenges_risks_issues' => ['nullable', 'string', 'max:255'],
            'risk_description' => ['nullable', 'string', 'max:5000'],
            'delay_constraint' => ['nullable', Rule::in(array_keys(config('mobile.reporting.constraint_types', [])))],
            'impact_description' => ['nullable', 'string', 'max:5000'],
            'observed_at' => ['nullable', 'date'],
        ];

        $validator = Validator::make($input, $rules);

        $validator->after(function ($validator) use ($request, $creating, $uploadedAssetFiles): void {
            $input = $validator->safe()->all();
            $routeSubmission = $request->route('submission');
            $mode = $this->resolveSubmissionMode(
                is_string($input['mode'] ?? null) ? $input['mode'] : null,
                $creating,
                $routeSubmission instanceof Submission ? $routeSubmission : null,
            );

            $mediaRows = collect($this->resolveMediaRows($input))->filter(fn ($row): bool => is_array($row));
            $mediaIds = $mediaRows
                ->pluck('id')
                ->filter(fn ($id): bool => is_numeric($id))
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

            if ($mediaRows->contains(fn (array $row): bool => ! empty($row) && empty($row['id']))) {
                $validator->errors()->add('media', 'Each selected media item must include its media asset id.');
            }

            if ($mediaIds->isNotEmpty()) {
                $existingMediaCount = MediaAsset::query()
                    ->whereIn('id', $mediaIds->all())
                    ->count();

                if ($existingMediaCount !== $mediaIds->count()) {
                    $validator->errors()->add('media', 'One or more media assets could not be found.');
                }
            }

            if ($creating && $mediaIds->isNotEmpty()) {
                $validator->errors()->add('media', 'Attach media after creating the draft submission.');
            }

            if (! $creating && $routeSubmission instanceof Submission && $mediaIds->isNotEmpty()) {
                $ownedMediaCount = MediaAsset::query()
                    ->where('submission_id', $routeSubmission->id)
                    ->whereIn('id', $mediaIds->all())
                    ->count();

                if ($ownedMediaCount !== $mediaIds->count()) {
                    $validator->errors()->add('media', 'One or more media assets do not belong to this submission.');
                }
            }

            if ($uploadedAssetFiles !== []) {
                $this->validateUploadedAssetFiles(
                    $uploadedAssetFiles,
                    $routeSubmission instanceof Submission ? $routeSubmission : null,
                    $validator,
                );
            }

            if ($mode !== 'submit') {
                return;
            }

            if (empty($input['project_status'])) {
                $validator->errors()->add('project_status', 'Current project status is required.');
            }

            if (empty($input['confirm_accuracy'])) {
                $validator->errors()->add('confirm_accuracy', 'You must confirm the report accuracy before submission.');
            }

            if (! isset($input['actual_beneficiaries'])) {
                $validator->errors()->add('actual_beneficiaries', 'Actual beneficiaries is required.');
            }

            if (empty($input['location_label']) && (! isset($input['latitude']) || ! isset($input['longitude']))) {
                $validator->errors()->add('location_label', 'Submission location is required.');
            }

            if (($input['location_source'] ?? null) === 'gps' && (! isset($input['latitude']) || ! isset($input['longitude']))) {
                $validator->errors()->add('latitude', 'GPS location requires latitude and longitude coordinates.');
            }

            $projectStatus = $input['project_status'] ?? null;

            if ($projectStatus === 'planned' && empty($input['delay_reason'])) {
                $validator->errors()->add('delay_reason', 'Reason for delay is required when the project is still planned.');
            }

            if ($projectStatus === 'in_progress') {
                if (empty($input['progress_impression'])) {
                    $validator->errors()->add('progress_impression', 'Impression of work progress is required.');
                }

                if (! array_key_exists('physical_progress', $input)) {
                    $validator->errors()->add('physical_progress', 'Please indicate if physical progress is visible.');
                }

                if (! isset($input['approximate_completion_percentage'])) {
                    $validator->errors()->add('approximate_completion_percentage', 'Approximate completion percentage is required.');
                }

                // if (empty($input['additional_observations'])) {
                //     $validator->errors()->add('additional_observations', 'Additional observations are required.');
                // }
            }

            if ($projectStatus === 'completed') {
                if (! array_key_exists('is_project_being_used', $input)) {
                    $validator->errors()->add('is_project_being_used', 'Please confirm whether the project is being used.');
                }

                if (($input['is_project_being_used'] ?? false) && empty($input['user_categories'])) {
                    $validator->errors()->add('user_categories', 'At least one user category is required.');
                }

                if (! array_key_exists('is_used_as_intended', $input)) {
                    $validator->errors()->add('is_used_as_intended', 'Please confirm whether the project is being used as intended.');
                }

                if (empty($input['functional_status'])) {
                    $validator->errors()->add('functional_status', 'Functional status is required.');
                }

                if (! array_key_exists('negative_environmental_impact', $input)) {
                    $validator->errors()->add('negative_environmental_impact', 'Please confirm whether there is a negative environmental impact.');
                }

                if (($input['negative_environmental_impact'] ?? false)) {
                    $validator->errors()->add('negative_impact_details', 'Please describe the environmental impact observed.');
                }
            }
        });

        return $validator;
    }

    private function persistSubmission(Request $request, array $validated, ?Submission $submission = null): JsonResponse
    {
        $user = $request->user();
        $isNew = ! $submission;
        $uploadedAssetFiles = $this->uploadedAssetFiles($request);

        $projectId = (int) ($validated['project_id'] ?? $submission?->project_id);
        $project = Project::query()->with('municipality')->findOrFail($projectId);

        if (! ProjectAccessService::canView($user, $project)) {
            return $this->errorResponse('You can only report projects within your assigned scope.', 403);
        }

        $mode = $this->resolveSubmissionMode(
            isset($validated['mode']) && is_string($validated['mode']) ? $validated['mode'] : null,
            $isNew,
            $submission,
        );
        $targetStatus = $mode === 'draft'
            ? SubmissionStatus::DRAFT->value
            : SubmissionStatus::SUBMITTED->value;
        $beforeStatus = $submission?->status;
        $existingFormData = $submission ? $this->submissionFormData($submission) : [];

        $formData = $this->buildSubmissionData($validated, $project, $existingFormData);
        $title = array_key_exists('title', $validated)
            ? trim((string) ($validated['title'] ?? ''))
            : trim((string) ($submission?->title ?? ''));

        if ($title === '') {
            $title = sprintf('%s Progress Update', $project->name_en);
        }

        $details = $validated['summary_of_observation']
            ?? $validated['additional_observations']
            ?? $validated['notes']
            ?? $submission?->details;

        if ($uploadedAssetFiles !== [] && ! $user->hasPermission('media.upload')) {
            return $this->errorResponse('Access denied.', 403);
        }

        $hasMediaPayload = array_key_exists('media', $validated)
            || array_key_exists('assets', $validated)
            || $uploadedAssetFiles !== [];
        $mediaReferences = $hasMediaPayload
            ? $this->normalizeMediaReferences($this->resolveMediaRows($validated))
            : ($submission ? $this->submissionMediaReferences($submission) : []);

        $submission ??= new Submission();
        $submission->forceFill([
            'client_uuid' => $validated['client_uuid'] ?? $submission->client_uuid,
            'reporter_id' => $submission->reporter_id ?? $user->id,
            'project_id' => $project->id,
            'municipality_id' => $project->municipality_id,
            'status' => $targetStatus,
            'title' => $title,
            'details' => $details,
            'data' => $formData,
            'media' => $mediaReferences,
            'latitude' => $validated['latitude'] ?? $submission->latitude ?? $project->latitude,
            'longitude' => $validated['longitude'] ?? $submission->longitude ?? $project->longitude,
            'submitted_at' => $targetStatus === SubmissionStatus::SUBMITTED->value
                ? ($submission->submitted_at ?? now())
                : null,
            'validated_at' => null,
            'validated_by' => null,
            'validation_comment' => null,
        ])->save();

        if ($uploadedAssetFiles !== []) {
            try {
                $storedMediaReferences = $this->storeUploadedAssetFiles(
                    $request,
                    $submission,
                    $uploadedAssetFiles,
                    count($mediaReferences),
                );
            } catch (\RuntimeException $exception) {
                return $this->errorResponse($exception->getMessage(), 500);
            }

            $mediaReferences = array_merge(
                $mediaReferences,
                $storedMediaReferences,
            );

            $submission->forceFill([
                'media' => $mediaReferences,
            ])->save();
        }

        $this->syncMediaAssetsMetadata($submission, $mediaReferences);

        if ($isNew || $beforeStatus !== $targetStatus) {
            SubmissionStatusEvent::create([
                'submission_id' => $submission->id,
                'actor_id' => $user->id,
                'from_status' => $beforeStatus,
                'to_status' => $targetStatus,
                'comment' => $targetStatus === SubmissionStatus::DRAFT->value
                    ? 'Saved from mobile reporting flow.'
                    : 'Submitted from mobile reporting flow.',
            ]);
        }

        AuditLogger::log(
            action: $isNew
                ? ($targetStatus === SubmissionStatus::DRAFT->value ? 'mobile.submissions.draft_created' : 'mobile.submissions.created')
                : ($targetStatus === SubmissionStatus::DRAFT->value ? 'mobile.submissions.draft_updated' : 'mobile.submissions.resubmitted'),
            entityType: 'submissions',
            entityId: $submission->id,
            after: [
                'status' => $submission->status,
                'project_id' => $submission->project_id,
            ],
            metadata: [
                'status' => $submission->status,
                'project_id' => $submission->project_id,
                'project_status' => $formData['project_status'] ?? null,
                'client_uuid' => $submission->client_uuid,
                'source' => 'mobile',
            ],
            request: $request,
        );

        $submission->load([
            'project.municipality',
            'reporter',
            'validator',
            'mediaAssets',
            'statusEvents.actor',
        ]);

        return $this->successResponse([
            'submission' => $this->serializeSubmissionDetail($submission),
            'idempotent_reuse' => false,
        ], $targetStatus === SubmissionStatus::DRAFT->value
            ? 'Draft saved successfully.'
            : 'Monitoring report submitted successfully.', $isNew ? 201 : 200);
    }

    private function buildSubmissionData(array $validated, Project $project, array $existing = []): array
    {
        $projectPayload = $this->serializeProject($project);

        $data = [
            'report_type' => $this->valueFromPayload($validated, $existing, 'report_type', config('mobile.reporting.report_type')),
            'reporting_period_label' => $this->valueFromPayload($validated, $existing, 'reporting_period_label', sprintf('Week %s - %s', now()->format('W'), now()->format('F Y'))),
            'project_code' => $projectPayload['code'],
            'project_name' => $projectPayload['name'],
            'goal_area' => $projectPayload['goal_area'],
            'component_category' => $this->valueFromPayload($validated, $existing, 'component_category', $projectPayload['component_category']),
            'project_status' => $this->valueFromPayload($validated, $existing, 'project_status'),
            'delay_reason' => $this->valueFromPayload($validated, $existing, 'delay_reason'),
            'progress_impression' => $this->valueFromPayload($validated, $existing, 'progress_impression'),
            'physical_progress' => $this->valueFromPayload($validated, $existing, 'physical_progress'),
            'approximate_completion_percentage' => $this->valueFromPayload($validated, $existing, 'approximate_completion_percentage'),
            'additional_observations' => $this->valueFromPayload($validated, $existing, 'additional_observations'),
            'is_project_being_used' => $this->valueFromPayload($validated, $existing, 'is_project_being_used'),
            'activities_started' => $this->valueFromPayload($validated, $existing, 'activities_started'),
            'user_categories' => $this->normalizeStringArray($this->valueFromPayload($validated, $existing, 'user_categories', [])),
            'is_used_as_intended' => $this->valueFromPayload($validated, $existing, 'is_used_as_intended'),
            'functional_status' => $this->valueFromPayload($validated, $existing, 'functional_status'),
            'negative_environmental_impact' => $this->valueFromPayload($validated, $existing, 'negative_environmental_impact'),
            'negative_impact_details' => $this->valueFromPayload($validated, $existing, 'negative_impact_details'),
            'actual_beneficiaries' => $this->valueFromPayload($validated, $existing, 'actual_beneficiaries'),
            'location_label' => $this->valueFromPayload($validated, $existing, 'location_label', $projectPayload['location_label']),
            'location_source' => $this->valueFromPayload($validated, $existing, 'location_source', 'manual'),
            'location_accuracy_meters' => $this->valueFromPayload($validated, $existing, 'location_accuracy_meters'),
            'summary_of_observation' => $this->valueFromPayload($validated, $existing, 'summary_of_observation'),
            'key_updates' => $this->normalizeStringArray($this->valueFromPayload($validated, $existing, 'key_updates', [])),
            'challenges_risks_issues' => $this->valueFromPayload($validated, $existing, 'challenges_risks_issues'),
            'risk_description' => $this->valueFromPayload($validated, $existing, 'risk_description'),
            'delay_constraint' => $this->valueFromPayload($validated, $existing, 'delay_constraint'),
            'impact_description' => $this->valueFromPayload($validated, $existing, 'impact_description'),
            'notes' => $this->valueFromPayload($validated, $existing, 'notes'),
            'observed_at' => $this->valueFromPayload($validated, $existing, 'observed_at'),
            'confirm_accuracy' => (bool) $this->valueFromPayload($validated, $existing, 'confirm_accuracy', false),
        ];

        return $this->pruneStatusSpecificData($data);
    }

    private function normalizeSubmissionInput(array $input): array
    {
        foreach ([
            'physical_progress',
            'is_project_being_used',
            'is_used_as_intended',
            'negative_environmental_impact',
            'confirm_accuracy',
        ] as $booleanKey) {
            if (array_key_exists($booleanKey, $input)) {
                $input[$booleanKey] = $this->normalizeBooleanInput($input[$booleanKey]);
            }
        }

        if (! array_key_exists('activities_started', $input)) {
            foreach (['activity_started', 'activities_workshops_or_training_started'] as $alias) {
                if (! array_key_exists($alias, $input)) {
                    continue;
                }

                $input['activities_started'] = $this->normalizeBooleanInput($input[$alias]);
                break;
            }
        } else {
            $input['activities_started'] = $this->normalizeBooleanInput($input['activities_started']);
        }

        return $input;
    }

    private function normalizeBooleanInput(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '1', 'true', 'yes', 'y', 'on' => true,
            '0', 'false', 'no', 'n', 'off' => false,
            default => $value,
        };
    }

    private function uploadedAssetFiles(Request $request): array
    {
        $files = $request->file('assets');

        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        return collect($files)
            ->flatten(1)
            ->filter(fn ($file): bool => $file instanceof UploadedFile)
            ->values()
            ->all();
    }

    private function isMultipartFileUpdateWithoutPostTransport(Request $request): bool
    {
        $contentType = strtolower((string) $request->header('Content-Type', ''));
        $realMethod = strtoupper($request->getRealMethod());

        return in_array($realMethod, ['PUT', 'PATCH'], true)
            && str_starts_with($contentType, 'multipart/form-data');
    }

    private function validateUploadedAssetFiles(array $files, ?Submission $submission, $validator): void
    {
        $countsByType = [
            'image' => 0,
            'video' => 0,
        ];

        if ($submission) {
            MediaAsset::query()
                ->where('submission_id', $submission->id)
                ->selectRaw('media_type, COUNT(*) as aggregate_count')
                ->groupBy('media_type')
                ->get()
                ->each(function (MediaAsset $asset) use (&$countsByType): void {
                    if (isset($countsByType[$asset->media_type])) {
                        $countsByType[$asset->media_type] = (int) $asset->aggregate_count;
                    }
                });
        }

        foreach (array_values($files) as $index => $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                $validator->errors()->add("assets.{$index}", 'Uploaded asset is invalid.');

                continue;
            }

            $mimeType = $file->getMimeType() ?: $file->getClientMimeType();
            $mediaType = $this->uploadedAssetMediaType($file, $mimeType);
            $sizeBytes = (int) ($file->getSize() ?? 0);

            if (! $mediaType || ! in_array($mimeType, config('media.allowed_mime_types', []), true)) {
                $validator->errors()->add("assets.{$index}", 'Uploaded asset must be a supported image or video.');

                continue;
            }

            if (! $this->withinDirectUploadLimits($countsByType[$mediaType] ?? 0, $mediaType, $sizeBytes)) {
                $validator->errors()->add("assets.{$index}", 'Media limits exceeded for this submission.');

                continue;
            }

            $countsByType[$mediaType]++;
        }
    }

    private function withinDirectUploadLimits(int $existingCount, string $mediaType, int $sizeBytes): bool
    {
        if ($mediaType === 'image') {
            $maxCount = (int) config('media.images.max_count', 10);
            $maxBytes = (int) config('media.images.max_upload_mb', 15) * 1024 * 1024;

            return $existingCount < $maxCount && $sizeBytes > 0 && $sizeBytes <= $maxBytes;
        }

        $maxCount = (int) config('media.videos.max_count', 1);
        $maxBytes = (int) config('media.videos.max_upload_mb', 300) * 1024 * 1024;

        return $existingCount < $maxCount && $sizeBytes > 0 && $sizeBytes <= $maxBytes;
    }

    private function uploadedAssetMediaType(UploadedFile $file, ?string $mimeType = null): ?string
    {
        $mimeType ??= $file->getMimeType() ?: $file->getClientMimeType();

        if (is_string($mimeType) && str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        if (is_string($mimeType) && str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        return null;
    }

    private function valueFromPayload(array $validated, array $existing, string $key, mixed $fallback = null): mixed
    {
        if (array_key_exists($key, $validated)) {
            return $validated[$key];
        }

        if (array_key_exists($key, $existing)) {
            return $existing[$key];
        }

        return $fallback;
    }

    private function normalizeStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => trim($item))
            ->values()
            ->all();
    }

    private function normalizeMediaReferences(array $mediaRows): array
    {
        return collect($mediaRows)
            ->filter(fn ($row): bool => is_array($row))
            ->map(function (array $row): ?array {
                $id = (int) ($row['id'] ?? 0);

                if ($id <= 0) {
                    return null;
                }

                $label = trim((string) ($row['label'] ?? ''));
                $type = isset($row['type']) && is_string($row['type']) ? trim($row['type']) : null;

                return [
                    'id' => $id,
                    'type' => $type !== '' ? $type : null,
                    'label' => $label !== '' ? $label : null,
                ];
            })
            ->filter()
            ->unique('id')
            ->values()
            ->all();
    }

    private function resolveMediaRows(array $payload): array
    {
        $mediaRows = $payload['media'] ?? null;

        $assetRows = $payload['assets'] ?? null;

        if (! is_array($mediaRows) && is_array($assetRows)) {
            $mediaRows = $assetRows;
        }

        if (! is_array($mediaRows)) {
            return [];
        }

        return collect($mediaRows)
            ->map(function ($row): array {
                if (is_array($row)) {
                    return $row;
                }

                if (is_numeric($row)) {
                    return ['id' => (int) $row];
                }

                if (is_string($row) && trim($row) !== '' && is_numeric($row)) {
                    return ['id' => (int) $row];
                }

                return [];
            })
            ->values()
            ->all();
    }

    private function syncMediaAssetsMetadata(Submission $submission, array $mediaReferences): void
    {
        $orderedIds = collect($mediaReferences)
            ->pluck('id')
            ->filter(fn ($id): bool => is_int($id) && $id > 0)
            ->values();

        if ($orderedIds->isEmpty()) {
            MediaAsset::query()
                ->where('submission_id', $submission->id)
                ->update(['display_order' => null]);

            return;
        }

        $assets = MediaAsset::query()
            ->where('submission_id', $submission->id)
            ->whereIn('id', $orderedIds->all())
            ->get()
            ->keyBy('id');

        $referencesById = collect($mediaReferences)->keyBy('id');

        foreach ($orderedIds as $index => $assetId) {
            $asset = $assets->get($assetId);

            if (! $asset) {
                continue;
            }

            $reference = $referencesById->get($assetId, []);
            $label = trim((string) ($reference['label'] ?? ''));
            $metadata = is_array($asset->metadata) ? $asset->metadata : [];

            if ($label !== '') {
                $metadata['label'] = $label;
            } else {
                unset($metadata['label']);
            }

            $asset->forceFill([
                'label' => $label !== '' ? $label : null,
                'display_order' => (int) $index,
                'metadata' => ! empty($metadata) ? $metadata : null,
            ])->save();
        }

        MediaAsset::query()
            ->where('submission_id', $submission->id)
            ->whereNotIn('id', $orderedIds->all())
            ->update(['display_order' => null]);
    }

    private function storeUploadedAssetFiles(
        Request $request,
        Submission $submission,
        array $files,
        int $startingDisplayOrder = 0,
    ): array {
        $disk = (string) config('media.direct_upload_disk', config('media.disk', 'public'));
        $diskConfig = config("filesystems.disks.{$disk}");

        if (! is_array($diskConfig)) {
            throw new \RuntimeException("Media direct upload disk [{$disk}] is not configured.");
        }

        $bucket = ($diskConfig['driver'] ?? null) === 's3'
            ? ($diskConfig['bucket'] ?? null)
            : null;

        $storedAssets = [];

        try {
            return collect(array_values($files))
                ->map(function (UploadedFile $file, int $offset) use ($request, $submission, $disk, $bucket, $startingDisplayOrder, &$storedAssets): ?array {
                $mimeType = $file->getMimeType() ?: $file->getClientMimeType();
                $mediaType = $this->uploadedAssetMediaType($file, $mimeType);

                if (! $mediaType || ! is_string($mimeType)) {
                    return null;
                }

                $uuid = (string) Str::uuid();
                $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: ($mediaType === 'video' ? 'mp4' : 'jpg'));
                $objectKey = sprintf(
                    'mobile/assets/%d/%s.%s',
                    $submission->id,
                    $uuid,
                    $extension,
                );

                $storedPath = Storage::disk($disk)->putFileAs(
                    dirname($objectKey),
                    $file,
                    basename($objectKey),
                );

                if (! is_string($storedPath) || trim($storedPath) === '') {
                    throw new \RuntimeException(
                        "Unable to store uploaded assets on disk [{$disk}]. Configure MEDIA_DIRECT_UPLOAD_DISK or MEDIA_DISK correctly.",
                    );
                }

                $label = trim((string) pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
                $mediaAsset = MediaAsset::query()->create([
                    'uuid' => $uuid,
                    'submission_id' => $submission->id,
                    'uploaded_by' => $request->user()->id,
                    'client_media_id' => null,
                    'label' => $label !== '' ? $label : null,
                    'display_order' => $startingDisplayOrder + $offset,
                    'disk' => $disk,
                    'bucket' => $bucket,
                    'object_key' => $objectKey,
                    'media_type' => $mediaType,
                    'mime_type' => $mimeType,
                    'original_filename' => $file->getClientOriginalName(),
                    'size_bytes' => (int) ($file->getSize() ?? 0),
                    'status' => 'uploaded',
                    'uploaded_at' => now(),
                ]);

                $storedAssets[] = [
                    'id' => $mediaAsset->id,
                    'disk' => $disk,
                    'object_key' => $objectKey,
                ];

                ProcessMediaAssetJob::dispatch($mediaAsset->id)
                    ->onQueue(config('media.processing_queue', 'media'));

                AuditLogger::log(
                    action: 'mobile.submissions.asset_uploaded',
                    entityType: 'media_assets',
                    entityId: $mediaAsset->id,
                    metadata: [
                        'submission_id' => $submission->id,
                        'media_type' => $mediaType,
                        'object_key' => $objectKey,
                        'source' => 'mobile',
                    ],
                    request: $request,
                );

                return [
                    'id' => $mediaAsset->id,
                    'type' => $mediaType,
                    'label' => $label !== '' ? $label : null,
                ];
                })
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable $exception) {
            foreach (array_reverse($storedAssets) as $storedAsset) {
                try {
                    Storage::disk($storedAsset['disk'])->delete($storedAsset['object_key']);
                } catch (\Throwable) {
                    // Best effort cleanup for partially stored files.
                }

                MediaAsset::query()->whereKey($storedAsset['id'])->delete();
            }

            if ($exception instanceof \RuntimeException) {
                throw $exception;
            }

            throw new \RuntimeException(
                "Unable to store uploaded assets on disk [{$disk}]. Configure MEDIA_DIRECT_UPLOAD_DISK or MEDIA_DISK correctly.",
                previous: $exception,
            );
        }
    }

    private function pruneStatusSpecificData(array $data): array
    {
        $status = $data['project_status'] ?? null;

        if ($status === 'planned') {
            $data['progress_impression'] = null;
            $data['physical_progress'] = null;
            $data['approximate_completion_percentage'] = null;
            $data['additional_observations'] = null;
            $data['is_project_being_used'] = null;
            $data['activities_started'] = null;
            $data['user_categories'] = [];
            $data['is_used_as_intended'] = null;
            $data['functional_status'] = null;
            $data['negative_environmental_impact'] = null;
            $data['negative_impact_details'] = null;
        } elseif ($status === 'in_progress') {
            $data['delay_reason'] = null;
            $data['is_project_being_used'] = null;
            $data['activities_started'] = null;
            $data['user_categories'] = [];
            $data['is_used_as_intended'] = null;
            $data['functional_status'] = null;
            $data['negative_environmental_impact'] = null;
            $data['negative_impact_details'] = null;
        } elseif ($status === 'completed') {
            $data['delay_reason'] = null;
            $data['progress_impression'] = null;
            $data['physical_progress'] = null;
            $data['approximate_completion_percentage'] = null;
            $data['additional_observations'] = null;

            if (($data['is_project_being_used'] ?? false) !== true) {
                $data['user_categories'] = [];
            }

            if (($data['negative_environmental_impact'] ?? false) !== true) {
                $data['negative_impact_details'] = null;
            }
        }

        return $data;
    }

    private function resolveSubmissionMode(?string $mode, bool $creating, ?Submission $submission = null): string
    {
        if ($mode === 'draft' || $mode === 'submit') {
            return $mode;
        }

        if ($creating || $submission?->status === SubmissionStatus::DRAFT->value) {
            return 'draft';
        }

        return 'submit';
    }

    private function normalizeSubmissionStatusFilter(?string $status): ?string
    {
        if (! is_string($status) || trim($status) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($status));

        if ($normalized === 'rework') {
            return SubmissionStatus::REWORK_REQUESTED->value;
        }

        return in_array($normalized, SubmissionStatus::values(), true) ? $normalized : null;
    }

    private function canViewSubmissionMedia(User $user, Submission $submission): bool
    {
        if ($user->hasPermission('media.view.all')) {
            return true;
        }

        if ($user->hasPermission('media.view.municipality')) {
            return (int) $user->municipality_id === (int) $submission->municipality_id;
        }

        if ($user->hasPermission('media.view.own')) {
            return (int) $user->id === (int) $submission->reporter_id;
        }

        return false;
    }
}
