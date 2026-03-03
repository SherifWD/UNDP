<?php

namespace App\Http\Controllers\Api;

use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Jobs\DispatchSubmissionStatusNotificationJob;
use App\Models\Project;
use App\Models\Submission;
use App\Models\SubmissionStatusEvent;
use App\Services\AuditLogger;
use App\Services\SubmissionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Submission::class);

        $user = $request->user();

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(SubmissionStatus::values())],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort_by' => ['nullable', Rule::in(['created_at', 'submitted_at', 'updated_at', 'status'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $query = Submission::query()->with([
            'reporter:id,name,role',
            'validator:id,name,role',
            'project:id,name_en,name_ar,municipality_id',
            'municipality:id,name_en,name_ar',
            'mediaAssets:id,submission_id,media_type,status',
        ]);

        SubmissionAccessService::scope($user, $query);

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('details', 'like', "%{$search}%")
                    ->orWhere('id', $search);
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        if (! empty($validated['municipality_id']) && $user->hasPermission('submissions.view.all')) {
            $query->where('municipality_id', $validated['municipality_id']);
        }

        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        $submissions = $query
            ->orderBy($sortBy, $sortDir)
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (Submission $submission): array => $this->serializeSubmission($submission));

        return response()->json($submissions);
    }

    public function pending(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort_by' => ['nullable', Rule::in(['created_at', 'project_id'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'status' => ['nullable', Rule::in([
                SubmissionStatus::UNDER_REVIEW->value,
                SubmissionStatus::REWORK_REQUESTED->value,
                SubmissionStatus::SUBMITTED->value,
            ])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
        ]);

        $query = Submission::query()
            ->select([
                'id',
                'title',
                'status',
                'created_at',
                'reporter_id',
                'project_id',
                'municipality_id',
            ])
            ->with([
                'reporter:id,name,role',
                'project:id,name_en,name_ar,municipality_id',
                'municipality:id,name_en,name_ar',
            ])
            ->whereIn('status', [
                SubmissionStatus::UNDER_REVIEW->value,
                SubmissionStatus::REWORK_REQUESTED->value,
                SubmissionStatus::SUBMITTED->value,
            ]);

        if ($request->user()->municipality_id && ! $request->user()->hasPermission('submissions.view.all')) {
            $query->where('municipality_id', $request->user()->municipality_id);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('details', 'like', "%{$search}%")
                    ->orWhere('id', $search);
            });
        }

        if (! empty($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        $submissions = $query
            ->orderBy($sortBy, $sortDir)
            ->paginate($validated['per_page'] ?? 20)
            ->through(fn (Submission $submission): array => $this->serializeWorklistSubmission($submission));

        $payload = $submissions->toArray();
        $scopeMunicipality = $request->user()->municipality()->first();

        $payload['scope'] = [
            'municipality_id' => $request->user()->municipality_id,
            'municipality_name' => $scopeMunicipality?->name,
        ];

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Submission::class);

        $validated = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:5000'],
            'data' => ['nullable', 'array'],
            'media' => ['nullable', 'array'],
            'status' => ['nullable', Rule::in([
                SubmissionStatus::DRAFT->value,
                SubmissionStatus::QUEUED->value,
                SubmissionStatus::SUBMITTED->value,
                SubmissionStatus::UNDER_REVIEW->value,
            ])],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        if (! empty($validated['client_uuid'])) {
            $existing = Submission::query()
                ->where('client_uuid', $validated['client_uuid'])
                ->where('reporter_id', $request->user()->id)
                ->first();

            if ($existing) {
                $existing->load(['reporter:id,name,role', 'project:id,name_en,name_ar', 'municipality:id,name_en,name_ar', 'mediaAssets:id,submission_id,media_type,status']);

                return response()->json([
                    'message' => __('Duplicate submission ignored. Returning existing submission.'),
                    'idempotent_reuse' => true,
                    'submission' => $this->serializeSubmission($existing),
                ]);
            }
        }

        $project = Project::query()->findOrFail($validated['project_id']);

        $status = $validated['status'] ?? SubmissionStatus::UNDER_REVIEW->value;

        $submission = Submission::create([
            'client_uuid' => $validated['client_uuid'] ?? null,
            'reporter_id' => $request->user()->id,
            'project_id' => $project->id,
            'municipality_id' => $project->municipality_id,
            'status' => $status,
            'title' => $validated['title'],
            'details' => $validated['details'] ?? null,
            'data' => $validated['data'] ?? [],
            'media' => $validated['media'] ?? [],
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'submitted_at' => in_array($status, [SubmissionStatus::DRAFT->value, SubmissionStatus::QUEUED->value], true) ? null : now(),
        ]);

        SubmissionStatusEvent::create([
            'submission_id' => $submission->id,
            'actor_id' => $request->user()->id,
            'from_status' => null,
            'to_status' => $submission->status,
            'comment' => null,
        ]);

        AuditLogger::log(
            action: 'submissions.created',
            entityType: 'submissions',
            entityId: $submission->id,
            after: $submission->only(['status', 'title', 'project_id', 'municipality_id', 'client_uuid']),
            metadata: [
                'status' => $submission->status,
                'project_id' => $submission->project_id,
                'municipality_id' => $submission->municipality_id,
                'client_uuid' => $submission->client_uuid,
            ],
            request: $request,
        );

        $submission->load([
            'reporter:id,name,role',
            'project:id,name_en,name_ar',
            'municipality:id,name_en,name_ar',
            'mediaAssets:id,submission_id,media_type,status',
        ]);

        return response()->json([
            'message' => __('Submission created successfully.'),
            'idempotent_reuse' => false,
            'submission' => $this->serializeSubmission($submission),
        ], 201);
    }

    public function show(Request $request, Submission $submission): JsonResponse
    {
        $this->authorize('view', $submission);

        $submission->load([
            'reporter:id,name,role',
            'validator:id,name,role',
            'project:id,name_en,name_ar,municipality_id',
            'municipality:id,name_en,name_ar',
            'mediaAssets:id,submission_id,uuid,media_type,mime_type,status,object_key,variants,uploaded_at,processed_at',
            'statusEvents.actor:id,name,role',
        ]);

        return response()->json([
            'submission' => $this->serializeSubmission($submission),
            'timeline' => $submission->statusEvents->map(fn ($event): array => [
                'id' => $event->id,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'comment' => $event->comment,
                'actor' => $event->actor ? [
                    'id' => $event->actor->id,
                    'name' => $event->actor->name,
                    'role' => $event->actor->role,
                ] : null,
                'created_at' => optional($event->created_at)->toIso8601String(),
            ]),
        ]);
    }

    public function timeline(Request $request, Submission $submission): JsonResponse
    {
        $this->authorize('view', $submission);

        $submission->load('statusEvents.actor:id,name,role');

        return response()->json([
            'data' => $submission->statusEvents->map(fn ($event): array => [
                'id' => $event->id,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'comment' => $event->comment,
                'actor' => $event->actor ? [
                    'id' => $event->actor->id,
                    'name' => $event->actor->name,
                    'role' => $event->actor->role,
                ] : null,
                'created_at' => optional($event->created_at)->toIso8601String(),
            ]),
        ]);
    }

    public function approve(Request $request, Submission $submission): JsonResponse
    {
        $this->authorize('approve', $submission);

        return $this->transition($request, $submission, SubmissionStatus::APPROVED->value, false);
    }

    public function reject(Request $request, Submission $submission): JsonResponse
    {
        $this->authorize('reject', $submission);

        return $this->transition($request, $submission, SubmissionStatus::REJECTED->value, true);
    }

    public function requestRework(Request $request, Submission $submission): JsonResponse
    {
        $this->authorize('rework', $submission);

        return $this->transition($request, $submission, SubmissionStatus::REWORK_REQUESTED->value, true);
    }

    private function transition(Request $request, Submission $submission, string $toStatus, bool $requiresComment): JsonResponse
    {
        $fromStatus = $submission->status;
        $allowedFromStatuses = $this->allowedFromStatuses($toStatus);

        if (! in_array($fromStatus, $allowedFromStatuses, true)) {
            return response()->json([
                'message' => __('Invalid status transition for current workflow cycle.'),
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'allowed_from' => $allowedFromStatuses,
            ], 422);
        }

        $validated = $request->validate([
            'comment' => [$requiresComment ? 'required' : 'nullable', 'string', 'max:2000'],
        ]);

        $comment = $validated['comment'] ?? null;

        $submission->forceFill([
            'status' => $toStatus,
            'validated_at' => now(),
            'validated_by' => $request->user()->id,
            'validation_comment' => $comment,
        ])->save();

        SubmissionStatusEvent::create([
            'submission_id' => $submission->id,
            'actor_id' => $request->user()->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comment' => $comment,
        ]);

        AuditLogger::log(
            action: 'submissions.status_changed',
            entityType: 'submissions',
            entityId: $submission->id,
            before: ['status' => $fromStatus],
            after: ['status' => $toStatus],
            metadata: [
                'status' => $toStatus,
                'old_status' => $fromStatus,
                'municipality_id' => $submission->municipality_id,
                'project_id' => $submission->project_id,
                'comment' => $comment,
            ],
            request: $request,
        );

        DispatchSubmissionStatusNotificationJob::dispatch($submission->id)
            ->onQueue('notifications');

        $submission->load([
            'reporter:id,name,role',
            'validator:id,name,role',
            'project:id,name_en,name_ar',
            'municipality:id,name_en,name_ar',
            'mediaAssets:id,submission_id,media_type,status',
        ]);

        return response()->json([
            'message' => __('Submission status updated successfully.'),
            'submission' => $this->serializeSubmission($submission),
        ]);
    }

    private function allowedFromStatuses(string $toStatus): array
    {
        return match ($toStatus) {
            SubmissionStatus::APPROVED->value => [
                SubmissionStatus::UNDER_REVIEW->value,
                SubmissionStatus::SUBMITTED->value,
                SubmissionStatus::REWORK_REQUESTED->value,
            ],
            SubmissionStatus::REJECTED->value => [
                SubmissionStatus::UNDER_REVIEW->value,
                SubmissionStatus::SUBMITTED->value,
                SubmissionStatus::REWORK_REQUESTED->value,
            ],
            SubmissionStatus::REWORK_REQUESTED->value => [
                SubmissionStatus::UNDER_REVIEW->value,
                SubmissionStatus::SUBMITTED->value,
            ],
            default => [],
        };
    }

    private function serializeSubmission(Submission $submission): array
    {
        return [
            'id' => $submission->id,
            'client_uuid' => $submission->client_uuid,
            'title' => $submission->title,
            'details' => $submission->details,
            'status' => $submission->status,
            'status_label' => $this->statusLabel($submission->status),
            'data' => $submission->data,
            'media' => $submission->media,
            'media_assets' => $submission->relationLoaded('mediaAssets')
                ? $submission->mediaAssets->map(fn ($asset): array => [
                    'id' => $asset->id,
                    'uuid' => $asset->uuid,
                    'media_type' => $asset->media_type,
                    'status' => $asset->status,
                    'variants' => $asset->variants,
                    'uploaded_at' => optional($asset->uploaded_at)->toIso8601String(),
                    'processed_at' => optional($asset->processed_at)->toIso8601String(),
                ])->values()
                : [],
            'latitude' => $submission->latitude,
            'longitude' => $submission->longitude,
            'submitted_at' => optional($submission->submitted_at)->toIso8601String(),
            'validated_at' => optional($submission->validated_at)->toIso8601String(),
            'validation_comment' => $submission->validation_comment,
            'created_at' => optional($submission->created_at)->toIso8601String(),
            'updated_at' => optional($submission->updated_at)->toIso8601String(),
            'reporter' => $submission->reporter ? [
                'id' => $submission->reporter->id,
                'name' => $submission->reporter->name,
                'role' => $submission->reporter->role,
            ] : null,
            'validator' => $submission->validator ? [
                'id' => $submission->validator->id,
                'name' => $submission->validator->name,
                'role' => $submission->validator->role,
            ] : null,
            'project' => $submission->project ? [
                'id' => $submission->project->id,
                'name_en' => $submission->project->name_en,
                'name_ar' => $submission->project->name_ar,
                'name' => $submission->project->name,
            ] : null,
            'municipality' => $submission->municipality ? [
                'id' => $submission->municipality->id,
                'name_en' => $submission->municipality->name_en,
                'name_ar' => $submission->municipality->name_ar,
                'name' => $submission->municipality->name,
            ] : null,
        ];
    }

    private function serializeWorklistSubmission(Submission $submission): array
    {
        return [
            'id' => $submission->id,
            'title' => $submission->title,
            'status' => $submission->status,
            'status_label' => $this->statusLabel($submission->status),
            'created_at' => optional($submission->created_at)->toIso8601String(),
            'reporter' => $submission->reporter ? [
                'id' => $submission->reporter->id,
                'name' => $submission->reporter->name,
                'role' => $submission->reporter->role,
            ] : null,
            'project' => $submission->project ? [
                'id' => $submission->project->id,
                'name_en' => $submission->project->name_en,
                'name_ar' => $submission->project->name_ar,
                'name' => $submission->project->name,
            ] : null,
            'municipality' => $submission->municipality ? [
                'id' => $submission->municipality->id,
                'name_en' => $submission->municipality->name_en,
                'name_ar' => $submission->municipality->name_ar,
                'name' => $submission->municipality->name,
            ] : null,
        ];
    }

    private function statusLabel(string $status): string
    {
        $enum = SubmissionStatus::tryFrom($status);

        return $enum ? $enum->label() : ucfirst(str_replace('_', ' ', $status));
    }
}
