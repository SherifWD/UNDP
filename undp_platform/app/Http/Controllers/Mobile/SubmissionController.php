<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\SubmissionStatus;
use App\Models\Project;
use App\Models\Submission;
use App\Models\SubmissionStatusEvent;
use App\Services\AuditLogger;
use App\Services\ProjectAccessService;
use App\Services\SubmissionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubmissionController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tab' => ['nullable', Rule::in(['submitted', 'drafts', 'all'])],
            'search' => ['nullable', 'string', 'max:255'],
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

        $limit = $validated['limit'] ?? 25;
        $submissions = $query->limit($limit)->get();

        $countsBase = Submission::query();
        SubmissionAccessService::scope($request->user(), $countsBase);

        return $this->successResponse([
            'items' => $submissions
                ->map(fn (Submission $submission): array => $this->serializeSubmissionCard($submission))
                ->values(),
            'counts' => [
                'submitted' => (clone $countsBase)->where('status', '!=', SubmissionStatus::DRAFT->value)->count(),
                'drafts' => (clone $countsBase)->where('status', SubmissionStatus::DRAFT->value)->count(),
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

    private function makeSubmissionValidator(Request $request, bool $creating)
    {
        $rules = [
            'client_uuid' => ['nullable', 'uuid'],
            'project_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:projects,id'],
            'mode' => ['nullable', Rule::in(['draft', 'submit'])],
            'title' => ['nullable', 'string', 'max:255'],
            'report_type' => ['nullable', 'string', 'max:120'],
            'reporting_period_label' => ['nullable', 'string', 'max:120'],
            'component_category' => ['nullable', 'string', 'max:255'],
            'project_status' => ['nullable', Rule::in(array_keys(config('mobile.reporting.project_statuses', [])))],
            'delay_reason' => ['nullable', 'string', 'max:255'],
            'progress_impression' => ['nullable', Rule::in(array_keys(config('mobile.reporting.progress_impressions', [])))],
            'physical_progress' => ['nullable', 'boolean'],
            'approximate_completion_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'additional_observations' => ['nullable', 'string', 'max:5000'],
            'is_project_being_used' => ['nullable', 'boolean'],
            'user_categories' => ['nullable', 'array'],
            'user_categories.*' => ['string', 'max:100'],
            'is_used_as_intended' => ['nullable', 'boolean'],
            'functional_status' => ['nullable', Rule::in(array_keys(config('mobile.reporting.functional_statuses', [])))],
            'negative_environmental_impact' => ['nullable', 'boolean'],
            'negative_impact_details' => ['nullable', 'string', 'max:5000'],
            'actual_beneficiaries' => ['nullable', 'integer', 'min:0'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_label' => ['nullable', 'string', 'max:255'],
            'media' => ['nullable', 'array'],
            'media.*.id' => ['nullable', 'integer'],
            'media.*.type' => ['nullable', 'string', 'max:30'],
            'media.*.label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'confirm_accuracy' => ['nullable', 'boolean'],
            'summary_of_observation' => ['nullable', 'string', 'max:5000'],
            'key_updates' => ['nullable', 'array'],
            'key_updates.*' => ['string', 'max:255'],
            'challenges_risks_issues' => ['nullable', 'string', 'max:255'],
            'risk_description' => ['nullable', 'string', 'max:5000'],
            'delay_constraint' => ['nullable', Rule::in(array_keys(config('mobile.reporting.constraint_types', [])))],
            'impact_description' => ['nullable', 'string', 'max:5000'],
        ];

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator): void {
            $input = $validator->safe()->all();
            $mode = $input['mode'] ?? 'submit';

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

                if (empty($input['additional_observations'])) {
                    $validator->errors()->add('additional_observations', 'Additional observations are required.');
                }
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

                if (($input['negative_environmental_impact'] ?? false) && empty($input['negative_impact_details'])) {
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

        $projectId = (int) ($validated['project_id'] ?? $submission?->project_id);
        $project = Project::query()->with('municipality')->findOrFail($projectId);

        if (! ProjectAccessService::canView($user, $project)) {
            return $this->errorResponse('You can only report projects within your assigned scope.', 403);
        }

        $mode = $validated['mode'] ?? 'submit';
        $targetStatus = $mode === 'draft'
            ? SubmissionStatus::DRAFT->value
            : SubmissionStatus::SUBMITTED->value;
        $beforeStatus = $submission?->status;

        $formData = $this->buildSubmissionData($validated, $project);
        $title = trim((string) ($validated['title'] ?? ''));

        if ($title === '') {
            $title = sprintf('%s Progress Update', $project->name_en);
        }
        $details = $validated['summary_of_observation']
            ?? $validated['additional_observations']
            ?? $validated['notes']
            ?? $submission?->details;

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
            'media' => array_values($validated['media'] ?? []),
            'latitude' => $validated['latitude'] ?? $submission->latitude ?? $project->latitude,
            'longitude' => $validated['longitude'] ?? $submission->longitude ?? $project->longitude,
            'submitted_at' => $targetStatus === SubmissionStatus::SUBMITTED->value
                ? ($submission->submitted_at ?? now())
                : null,
            'validated_at' => null,
            'validated_by' => null,
            'validation_comment' => null,
        ])->save();

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

    private function buildSubmissionData(array $validated, Project $project): array
    {
        $projectPayload = $this->serializeProject($project);

        return [
            'report_type' => $validated['report_type'] ?? config('mobile.reporting.report_type'),
            'reporting_period_label' => $validated['reporting_period_label'] ?? sprintf('Week %s - %s', now()->format('W'), now()->format('F Y')),
            'project_code' => $projectPayload['code'],
            'project_name' => $projectPayload['name'],
            'goal_area' => $projectPayload['goal_area'],
            'component_category' => $validated['component_category'] ?? $projectPayload['component_category'],
            'project_status' => $validated['project_status'] ?? null,
            'delay_reason' => $validated['delay_reason'] ?? null,
            'progress_impression' => $validated['progress_impression'] ?? null,
            'physical_progress' => $validated['physical_progress'] ?? null,
            'approximate_completion_percentage' => $validated['approximate_completion_percentage'] ?? null,
            'additional_observations' => $validated['additional_observations'] ?? null,
            'is_project_being_used' => $validated['is_project_being_used'] ?? null,
            'user_categories' => array_values($validated['user_categories'] ?? []),
            'is_used_as_intended' => $validated['is_used_as_intended'] ?? null,
            'functional_status' => $validated['functional_status'] ?? null,
            'negative_environmental_impact' => $validated['negative_environmental_impact'] ?? null,
            'negative_impact_details' => $validated['negative_impact_details'] ?? null,
            'actual_beneficiaries' => $validated['actual_beneficiaries'] ?? null,
            'location_label' => $validated['location_label'] ?? $projectPayload['location_label'],
            'summary_of_observation' => $validated['summary_of_observation'] ?? null,
            'key_updates' => array_values($validated['key_updates'] ?? []),
            'challenges_risks_issues' => $validated['challenges_risks_issues'] ?? null,
            'risk_description' => $validated['risk_description'] ?? null,
            'delay_constraint' => $validated['delay_constraint'] ?? null,
            'impact_description' => $validated['impact_description'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'confirm_accuracy' => (bool) ($validated['confirm_accuracy'] ?? false),
        ];
    }
}
