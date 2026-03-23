<?php

namespace App\Http\Controllers\Api;

use App\Enums\FundingRequestStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\Submission;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProjectAccessService;
use App\Services\SubmissionAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'status' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'with_stats' => ['nullable', Rule::in(['1', '0', 'true', 'false'])],
        ]);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        $validated = $validator->validated();
        $withStats = $request->boolean('with_stats');

        $query = Project::query()
            ->with('municipality:id,name_en,name_ar,code')
            ->withCount('assignedReporters');
        $tabCountQuery = Project::query();

        $this->applyProjectScope($request, $query, $validated, true);
        $this->applyProjectScope($request, $tabCountQuery, $validated, false);

        if ($withStats) {
            $this->applyStatCounts($request, $query);
            $this->applyFundingRequestStats($request, $query);
        }

        $query
            ->orderByDesc('last_update_at')
            ->orderBy('name_en');

        $tabCounts = $this->buildTabCounts($tabCountQuery);

        if (! empty($validated['per_page'])) {
            $paginator = $query
                ->paginate($validated['per_page'])
                ->through(fn (Project $project): array => $this->serializeProject($project, $withStats));

            $payload = $paginator->toArray();
            $payload['tab_counts'] = $tabCounts;

            return response()->json($payload);
        }

        $projects = $query
            ->get()
            ->map(fn (Project $project): array => $this->serializeProject($project, $withStats));

        return response()->json([
            'data' => $projects,
            'tab_counts' => $tabCounts,
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
        ]);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        $municipalityId = $validator->validated()['municipality_id'] ?? null;

        if ($request->user()->hasRole(UserRole::MUNICIPAL_FOCAL_POINT) && $request->user()->municipality_id) {
            $municipalityId = (int) $request->user()->municipality_id;
        }

        return response()->json([
            'available_reporters' => $this->availableReporterOptions($municipalityId),
            'option_sets' => [
                'execution_statuses' => $this->serializeOptionSet($this->executionStatuses()),
                'project_categories' => $this->serializeOptionSet($this->projectCategories()),
                'execution_models' => $this->serializeOptionSet($this->executionModels()),
                'development_goal_areas' => $this->serializeOptionSet($this->developmentGoalAreas()),
                'visibility_options' => $this->serializeOptionSet($this->visibilityOptions()),
                'lifecycle_statuses' => $this->serializeOptionSet([
                    'active' => 'Active',
                    'archived' => 'Archived',
                ]),
            ],
        ]);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        if (! ProjectAccessService::canView($request->user(), $project)) {
            return response()->json([
                'message' => 'Access denied.',
            ], 403);
        }

        $project->load([
            'municipality:id,name_en,name_ar,code',
            'assignedReporters:id,name,email,role,status,municipality_id',
        ]);
        $this->loadFundingRequestStats($request, $project);

        $submissionQuery = Submission::query()->where('project_id', $project->id);
        SubmissionAccessService::scope($request->user(), $submissionQuery);

        $total = (clone $submissionQuery)->count();
        $approved = (clone $submissionQuery)->where('status', SubmissionStatus::APPROVED->value)->count();
        $pending = (clone $submissionQuery)->whereIn('status', [
            SubmissionStatus::UNDER_REVIEW->value,
            SubmissionStatus::SUBMITTED->value,
            SubmissionStatus::REWORK_REQUESTED->value,
            SubmissionStatus::QUEUED->value,
        ])->count();
        $rejected = (clone $submissionQuery)->where('status', SubmissionStatus::REJECTED->value)->count();
        $submissionIds = (clone $submissionQuery)->select('id');
        $mediaAttachments = MediaAsset::query()
            ->whereIn('submission_id', $submissionIds)
            ->count();
        $mediaPreview = MediaAsset::query()
            ->whereIn('submission_id', (clone $submissionQuery)->select('id'))
            ->latest('id')
            ->limit(6)
            ->get(['id', 'submission_id', 'media_type', 'status'])
            ->map(fn (MediaAsset $asset): array => [
                'id' => $asset->id,
                'submission_id' => $asset->submission_id,
                'media_type' => $asset->media_type,
                'status' => $asset->status,
            ])
            ->values();

        return response()->json([
            'project' => $this->serializeProject($project, true, [
                'total_submissions' => $total,
                'approved_submissions' => $approved,
                'pending_submissions' => $pending,
                'rejected_submissions' => $rejected,
                'media_attachments' => $mediaAttachments,
                'active_reporters' => $project->assignedReporters->count(),
            ], true),
            'reporters' => $this->serializeReporterCollection($project->assignedReporters),
            'available_reporters' => $this->availableReporterOptions((int) $project->municipality_id),
            'media_attachments' => $mediaPreview,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = $this->makeProjectValidator($request, true);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        $validated = $validator->validated();

        $project = Project::create([
            'municipality_id' => $validated['municipality_id'],
            'name_en' => $validated['name_en'],
            'name_ar' => $validated['name_ar'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'active',
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'last_update_at' => now(),
        ]);

        $project->load('municipality');
        $project->mobile_meta = $this->buildProjectMeta($request, $project, $validated);
        $project->save();

        $this->syncAssignedReporters(
            $project,
            $validated['assigned_reporter_ids'] ?? [],
            $request->user()->id,
        );

        $project->load([
            'municipality:id,name_en,name_ar,code',
            'assignedReporters:id,name,email,role,status,municipality_id',
        ]);

        AuditLogger::log(
            action: 'projects.created',
            entityType: 'projects',
            entityId: $project->id,
            after: $project->toArray(),
            metadata: [
                'assigned_reporter_ids' => $project->assignedReporters->pluck('id')->all(),
            ],
            request: $request,
        );

        return response()->json([
            'message' => __('Project created successfully.'),
            'project' => $this->serializeProject($project, true, null, true),
            'reporters' => $this->serializeReporterCollection($project->assignedReporters),
        ], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        if (! ProjectAccessService::canView($request->user(), $project)) {
            return response()->json([
                'message' => 'Access denied.',
            ], 403);
        }

        $validator = $this->makeProjectValidator($request, false);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        $validated = $validator->validated();
        $before = $project->toArray();

        $project->fill([
            'municipality_id' => $validated['municipality_id'] ?? $project->municipality_id,
            'name_en' => $validated['name_en'] ?? $project->name_en,
            'name_ar' => $validated['name_ar'] ?? $project->name_ar,
            'description' => array_key_exists('description', $request->all()) ? ($validated['description'] ?? null) : $project->description,
            'status' => $validated['status'] ?? $project->status,
            'latitude' => array_key_exists('latitude', $request->all()) ? ($validated['latitude'] ?? null) : $project->latitude,
            'longitude' => array_key_exists('longitude', $request->all()) ? ($validated['longitude'] ?? null) : $project->longitude,
            'last_update_at' => now(),
        ]);
        $project->save();

        $project->load('municipality');
        $project->mobile_meta = $this->buildProjectMeta($request, $project, $validated);
        $project->save();

        if (array_key_exists('assigned_reporter_ids', $request->all())) {
            $this->syncAssignedReporters(
                $project,
                $validated['assigned_reporter_ids'] ?? [],
                $request->user()->id,
            );
        }

        $project->load([
            'municipality:id,name_en,name_ar,code',
            'assignedReporters:id,name,email,role,status,municipality_id',
        ]);

        AuditLogger::log(
            action: 'projects.updated',
            entityType: 'projects',
            entityId: $project->id,
            before: $before,
            after: $project->toArray(),
            metadata: [
                'assigned_reporter_ids' => $project->assignedReporters->pluck('id')->all(),
            ],
            request: $request,
        );

        return response()->json([
            'message' => __('Project updated successfully.'),
            'project' => $this->serializeProject($project, true, null, true),
            'reporters' => $this->serializeReporterCollection($project->assignedReporters),
        ]);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        if (! ProjectAccessService::canView($request->user(), $project)) {
            return response()->json([
                'message' => 'Access denied.',
            ], 403);
        }

        $before = $project->toArray();
        $projectId = $project->id;
        $project->delete();

        AuditLogger::log(
            action: 'projects.deleted',
            entityType: 'projects',
            entityId: $projectId,
            before: $before,
            request: $request,
        );

        return response()->json([
            'message' => __('Project deleted successfully.'),
        ]);
    }

    private function makeProjectValidator(Request $request, bool $creating)
    {
        $rules = [
            'municipality_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:municipalities,id'],
            'name_en' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'name_ar' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'project_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'execution_status' => ['sometimes', Rule::in(array_keys($this->executionStatuses()))],
            'project_category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'region_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'implementing_partner' => ['sometimes', 'nullable', 'string', 'max:255'],
            'program_lead' => ['sometimes', 'nullable', 'string', 'max:255'],
            'development_goal_area' => ['sometimes', 'nullable', 'string', 'max:255'],
            'execution_model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'objectives' => ['sometimes', 'nullable', 'array'],
            'objectives.*' => ['string', 'max:500'],
            'hard_components' => ['sometimes', 'nullable', 'array'],
            'hard_components.*' => ['string', 'max:500'],
            'soft_components' => ['sometimes', 'nullable', 'array'],
            'soft_components.*' => ['string', 'max:500'],
            'funding_budget' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'funding_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'funding_sources' => ['sometimes', 'nullable', 'array'],
            'funding_sources.*' => ['string', 'max:255'],
            'funding_types' => ['sometimes', 'nullable', 'array'],
            'funding_types.*' => ['string', 'max:255'],
            'progress_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'visibility' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contacts' => ['sometimes', 'nullable', 'array'],
            'contacts.*' => ['string', 'max:120'],
            'assigned_reporter_ids' => ['sometimes', 'nullable', 'array'],
            'assigned_reporter_ids.*' => ['integer', 'exists:users,id'],
        ];

        $validator = Validator::make($request->all(), $rules);

        $validator->after(function ($validator) use ($request, $creating): void {
            $input = $validator->safe()->all();
            $municipalityId = (int) ($input['municipality_id'] ?? 0);

            if (! $creating && ! $municipalityId) {
                $municipalityId = (int) data_get($request->route('project'), 'municipality_id', 0);
            }

            if (
                array_key_exists('latitude', $request->all())
                xor array_key_exists('longitude', $request->all())
            ) {
                $validator->errors()->add('latitude', 'Latitude and longitude must be saved together.');
            }

            if (! empty($input['start_date']) && ! empty($input['end_date'])) {
                try {
                    if (Carbon::parse($input['end_date'])->lt(Carbon::parse($input['start_date']))) {
                        $validator->errors()->add('end_date', 'End date must be after or equal to start date.');
                    }
                } catch (\Throwable) {
                    // Base validation already handles invalid dates.
                }
            }

            $reporterIds = array_values(array_unique(array_map('intval', $input['assigned_reporter_ids'] ?? [])));

            if ($reporterIds === []) {
                return;
            }

            $reporters = User::query()
                ->whereIn('id', $reporterIds)
                ->get(['id', 'role', 'status', 'municipality_id']);

            if ($reporters->count() !== count($reporterIds)) {
                $validator->errors()->add('assigned_reporter_ids', 'One or more selected reporters are invalid.');
                return;
            }

            foreach ($reporters as $reporter) {
                if (! $reporter->hasRole(UserRole::REPORTER)) {
                    $validator->errors()->add('assigned_reporter_ids', 'Only users with the reporter role can be assigned to projects.');
                    break;
                }

                if ($reporter->status !== 'active') {
                    $validator->errors()->add('assigned_reporter_ids', 'Inactive reporters cannot be assigned to projects.');
                    break;
                }

                if ($municipalityId && $reporter->municipality_id && (int) $reporter->municipality_id !== $municipalityId) {
                    $validator->errors()->add('assigned_reporter_ids', 'Assigned reporters must belong to the same municipality as the project.');
                    break;
                }
            }
        });

        return $validator;
    }

    private function applyProjectScope(Request $request, Builder $query, array $validated, bool $applyMunicipalityFilter): void
    {
        ProjectAccessService::scope($request->user(), $query);

        if ($applyMunicipalityFilter && ! empty($validated['municipality_id']) && ! $request->user()->hasRole(UserRole::MUNICIPAL_FOCAL_POINT)) {
            $query->where('municipality_id', $validated['municipality_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = trim((string) $validated['search']);

            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('name_en', 'like', "%{$search}%")
                    ->orWhere('name_ar', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");

                if (is_numeric($search)) {
                    $builder->orWhere('id', (int) $search);
                }
            });
        }
    }

    private function applyStatCounts(Request $request, Builder $query): void
    {
        $user = $request->user();
        $pendingStatuses = [
            SubmissionStatus::UNDER_REVIEW->value,
            SubmissionStatus::SUBMITTED->value,
            SubmissionStatus::REWORK_REQUESTED->value,
            SubmissionStatus::QUEUED->value,
        ];

        $submissionScope = function (Builder $submissionQuery) use ($user): void {
            if ($user->hasRole(UserRole::REPORTER)) {
                $submissionQuery->where('reporter_id', $user->id);
                return;
            }

            if ($user->hasRole(UserRole::PARTNER_DONOR_VIEWER)) {
                $submissionQuery->where('status', SubmissionStatus::APPROVED->value);
            }
        };

        $query->withCount([
            'submissions as total_submissions_count' => function (Builder $submissionQuery) use ($submissionScope): void {
                $submissionScope($submissionQuery);
            },
            'submissions as approved_submissions_count' => function (Builder $submissionQuery) use ($submissionScope): void {
                $submissionScope($submissionQuery);
                $submissionQuery->where('status', SubmissionStatus::APPROVED->value);
            },
            'submissions as pending_submissions_count' => function (Builder $submissionQuery) use ($submissionScope, $pendingStatuses): void {
                $submissionScope($submissionQuery);
                $submissionQuery->whereIn('status', $pendingStatuses);
            },
            'submissions as rejected_submissions_count' => function (Builder $submissionQuery) use ($submissionScope): void {
                $submissionScope($submissionQuery);
                $submissionQuery->where('status', SubmissionStatus::REJECTED->value);
            },
        ]);
    }

    private function applyFundingRequestStats(Request $request, Builder $query): void
    {
        if (! $this->shouldExposeFundingSummary($request)) {
            return;
        }

        $user = $request->user();
        $scope = function (Builder $fundingQuery) use ($user): void {
            if (
                ! $user->hasPermission('funding_requests.view.all')
                && ! $user->hasPermission('funding_requests.review')
            ) {
                $fundingQuery->where('donor_user_id', $user->id);
            }
        };

        $query->withCount([
            'fundingRequests as funding_requests_total_count' => function (Builder $fundingQuery) use ($scope): void {
                $scope($fundingQuery);
            },
            'fundingRequests as funding_requests_pending_count' => function (Builder $fundingQuery) use ($scope): void {
                $scope($fundingQuery);
                $fundingQuery->where('status', FundingRequestStatus::PENDING->value);
            },
            'fundingRequests as funding_requests_approved_count' => function (Builder $fundingQuery) use ($scope): void {
                $scope($fundingQuery);
                $fundingQuery->where('status', FundingRequestStatus::APPROVED->value);
            },
            'fundingRequests as funding_requests_declined_count' => function (Builder $fundingQuery) use ($scope): void {
                $scope($fundingQuery);
                $fundingQuery->where('status', FundingRequestStatus::DECLINED->value);
            },
        ])->withSum([
            'fundingRequests as funding_requested_total_amount' => function (Builder $fundingQuery) use ($scope): void {
                $scope($fundingQuery);
            },
        ], 'amount')->withMax([
            'fundingRequests as latest_funding_request_at' => function (Builder $fundingQuery) use ($scope): void {
                $scope($fundingQuery);
            },
        ], 'created_at');
    }

    private function loadFundingRequestStats(Request $request, Project $project): void
    {
        if (! $this->shouldExposeFundingSummary($request)) {
            return;
        }

        $user = $request->user();
        $scope = function (Builder $fundingQuery) use ($user): void {
            if (
                ! $user->hasPermission('funding_requests.view.all')
                && ! $user->hasPermission('funding_requests.review')
            ) {
                $fundingQuery->where('donor_user_id', $user->id);
            }
        };

        $project->loadCount([
            'fundingRequests as funding_requests_total_count' => function (Builder $fundingQuery) use ($scope): void {
                $scope($fundingQuery);
            },
            'fundingRequests as funding_requests_pending_count' => function (Builder $fundingQuery) use ($scope): void {
                $scope($fundingQuery);
                $fundingQuery->where('status', FundingRequestStatus::PENDING->value);
            },
            'fundingRequests as funding_requests_approved_count' => function (Builder $fundingQuery) use ($scope): void {
                $scope($fundingQuery);
                $fundingQuery->where('status', FundingRequestStatus::APPROVED->value);
            },
            'fundingRequests as funding_requests_declined_count' => function (Builder $fundingQuery) use ($scope): void {
                $scope($fundingQuery);
                $fundingQuery->where('status', FundingRequestStatus::DECLINED->value);
            },
        ])->loadSum([
            'fundingRequests as funding_requested_total_amount' => function (Builder $fundingQuery) use ($scope): void {
                $scope($fundingQuery);
            },
        ], 'amount')->loadMax([
            'fundingRequests as latest_funding_request_at' => function (Builder $fundingQuery) use ($scope): void {
                $scope($fundingQuery);
            },
        ], 'created_at');
    }

    private function buildTabCounts(Builder $query): array
    {
        $total = (clone $query)->count();
        $rows = (clone $query)
            ->selectRaw('municipality_id, COUNT(*) as total')
            ->groupBy('municipality_id')
            ->pluck('total', 'municipality_id')
            ->map(fn ($count) => (int) $count)
            ->all();

        return [
            'all' => (int) $total,
            'by_municipality' => $rows,
        ];
    }

    private function serializeProject(
        Project $project,
        bool $withStats = false,
        ?array $statOverrides = null,
        bool $includeRelations = false
    ): array {
        $meta = $this->projectMeta($project);
        $assignedReportersCount = (int) ($project->assigned_reporters_count
            ?? ($project->relationLoaded('assignedReporters') ? $project->assignedReporters->count() : 0));

        $payload = [
            'id' => $project->id,
            'code' => $meta['code'],
            'name_en' => $project->name_en,
            'name_ar' => $project->name_ar,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status,
            'lifecycle_status' => $project->status,
            'execution_status' => $meta['execution_status'],
            'execution_status_label' => $meta['execution_status_label'],
            'project_category' => $meta['project_category'],
            'region_label' => $meta['region_label'],
            'location_label' => $meta['location_label'],
            'implementing_partner' => $meta['implementing_partner'],
            'program_lead' => $meta['program_lead'],
            'development_goal_area' => $meta['development_goal_area'],
            'execution_model' => $meta['execution_model'],
            'start_date' => $meta['start_date'],
            'end_date' => $meta['end_date'],
            'date_range_label' => $meta['date_range_label'],
            'duration_months' => $meta['duration_months'],
            'duration_label' => $meta['duration_label'],
            'progress_percent' => $meta['progress_percent'],
            'visibility' => $meta['visibility'],
            'objectives' => $meta['objectives'],
            'hard_components' => $meta['hard_components'],
            'soft_components' => $meta['soft_components'],
            'funding_currency' => $meta['funding_currency'],
            'funding_budget' => $meta['funding_budget'],
            'funding_budget_label' => $meta['funding_budget_label'],
            'funding_sources' => $meta['funding_sources'],
            'funding_types' => $meta['funding_types'],
            'contacts' => $meta['contacts'],
            'created_by_label' => $meta['created_by_label'],
            'updated_by_label' => $meta['updated_by_label'],
            'funding_requests_summary' => $this->serializeFundingRequestSummary($project, $meta['funding_currency']),
            'assigned_reporters_count' => $assignedReportersCount,
            'latitude' => $project->latitude,
            'longitude' => $project->longitude,
            'municipality' => $project->municipality ? [
                'id' => $project->municipality->id,
                'name_en' => $project->municipality->name_en,
                'name_ar' => $project->municipality->name_ar,
                'name' => $project->municipality->name,
                'code' => $project->municipality->code,
            ] : null,
            'last_update_at' => optional($project->last_update_at)->toIso8601String(),
            'updated_at' => optional($project->updated_at)->toIso8601String(),
            'created_at' => optional($project->created_at)->toIso8601String(),
        ];

        if ($withStats) {
            $payload['stats'] = [
                'total_submissions' => (int) ($statOverrides['total_submissions'] ?? $project->total_submissions_count ?? 0),
                'approved_submissions' => (int) ($statOverrides['approved_submissions'] ?? $project->approved_submissions_count ?? 0),
                'pending_submissions' => (int) ($statOverrides['pending_submissions'] ?? $project->pending_submissions_count ?? 0),
                'rejected_submissions' => (int) ($statOverrides['rejected_submissions'] ?? $project->rejected_submissions_count ?? 0),
                'media_attachments' => (int) ($statOverrides['media_attachments'] ?? 0),
                'active_reporters' => (int) ($statOverrides['active_reporters'] ?? $assignedReportersCount),
                'progress_percent' => $meta['progress_percent'],
            ];
        }

        if ($includeRelations) {
            $payload['assigned_reporters'] = $project->relationLoaded('assignedReporters')
                ? $this->serializeReporterCollection($project->assignedReporters)
                : [];
        }

        return $payload;
    }

    private function serializeFundingRequestSummary(Project $project, string $defaultCurrency): ?array
    {
        $attributes = $project->getAttributes();

        if (
            ! array_key_exists('funding_requests_total_count', $attributes)
            && ! array_key_exists('funding_requested_total_amount', $attributes)
        ) {
            return null;
        }

        $latestRequestedAt = data_get($attributes, 'latest_funding_request_at');

        return [
            'total_requests' => (int) ($project->funding_requests_total_count ?? 0),
            'pending_requests' => (int) ($project->funding_requests_pending_count ?? 0),
            'approved_requests' => (int) ($project->funding_requests_approved_count ?? 0),
            'declined_requests' => (int) ($project->funding_requests_declined_count ?? 0),
            'total_requested_amount' => round((float) ($project->funding_requested_total_amount ?? 0), 2),
            'currency' => $defaultCurrency,
            'total_requested_amount_label' => $defaultCurrency.' '.$this->formatMoney((float) ($project->funding_requested_total_amount ?? 0)),
            'latest_requested_at' => $latestRequestedAt
                ? Carbon::parse((string) $latestRequestedAt)->toIso8601String()
                : null,
        ];
    }

    private function shouldExposeFundingSummary(Request $request): bool
    {
        $user = $request->user();

        return $user->hasPermission('funding_requests.view.all')
            || $user->hasPermission('funding_requests.view.own')
            || $user->hasPermission('funding_requests.create')
            || $user->hasPermission('funding_requests.review');
    }

    private function projectMeta(Project $project): array
    {
        $stored = is_array($project->mobile_meta) ? $project->mobile_meta : [];

        $startDate = $this->safeDateString($stored['start_date'] ?? null);
        $endDate = $this->safeDateString($stored['end_date'] ?? ($stored['expected_end_date'] ?? null));
        $progressPercent = max(0, min(100, (int) ($stored['progress_percent'] ?? 0)));
        $executionStatus = $this->normalizeExecutionStatus($stored['execution_status'] ?? null, $progressPercent);
        $durationMonths = $this->durationMonths($startDate, $endDate)
            ?? max(1, (int) ($stored['duration_months'] ?? 3));
        $fundingBudget = (float) ($stored['funding_budget'] ?? 0);
        $fundingCurrency = $this->normalizeCurrencyCode($stored['funding_currency'] ?? 'USD');

        return [
            'code' => (string) ($stored['code'] ?? $this->defaultProjectCode($project)),
            'execution_status' => $executionStatus,
            'execution_status_label' => $this->executionStatuses()[$executionStatus] ?? ucfirst(str_replace('_', ' ', $executionStatus)),
            'project_category' => (string) ($stored['project_category'] ?? $stored['component_category'] ?? 'Infrastructure - Public Safety'),
            'region_label' => (string) ($stored['region_label'] ?? ('Region / '.($project->municipality?->name ?? 'Unassigned'))),
            'location_label' => (string) ($stored['location_label'] ?? ($project->municipality?->name ?? 'Unassigned location')),
            'implementing_partner' => (string) ($stored['implementing_partner'] ?? $stored['implemented_by'] ?? ($project->municipality?->name ?? 'UNDP Partner')),
            'program_lead' => (string) ($stored['program_lead'] ?? 'UNDP Libya'),
            'development_goal_area' => (string) ($stored['development_goal_area'] ?? $stored['goal_area'] ?? 'Public Safety'),
            'execution_model' => (string) ($stored['execution_model'] ?? 'Government-led implementation with donor support'),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'date_range_label' => $this->formatDateRange($startDate, $endDate),
            'duration_months' => $durationMonths,
            'duration_label' => $durationMonths === 1 ? '1 Month' : sprintf('%d Months', $durationMonths),
            'progress_percent' => $progressPercent,
            'visibility' => (string) ($stored['visibility'] ?? 'Internal - Admin & Authorized Stakeholders'),
            'objectives' => $this->normalizeStringList($stored['objectives'] ?? []),
            'hard_components' => $this->normalizeStringList($stored['hard_components'] ?? []),
            'soft_components' => $this->normalizeStringList($stored['soft_components'] ?? []),
            'funding_currency' => $fundingCurrency,
            'funding_budget' => $fundingBudget,
            'funding_budget_label' => $fundingCurrency.' '.$this->formatMoney($fundingBudget),
            'funding_sources' => $this->normalizeStringList($stored['funding_sources'] ?? $stored['donors'] ?? []),
            'funding_types' => $this->normalizeStringList($stored['funding_types'] ?? []),
            'contacts' => $this->normalizeStringList($stored['contacts'] ?? []),
            'created_by_label' => (string) ($stored['created_by_name'] ?? 'System'),
            'updated_by_label' => (string) ($stored['updated_by_name'] ?? $stored['created_by_name'] ?? 'System'),
        ];
    }

    private function buildProjectMeta(Request $request, Project $project, array $validated): array
    {
        $existing = is_array($project->mobile_meta) ? $project->mobile_meta : [];
        $input = $request->all();

        $value = function (string $key, $default = null) use ($existing, $validated, $input) {
            if (array_key_exists($key, $input)) {
                return $validated[$key] ?? $default;
            }

            return $existing[$key] ?? $default;
        };

        $startDate = $this->safeDateString($value('start_date'));
        $endDate = $this->safeDateString($value('end_date') ?? $existing['expected_end_date'] ?? null);
        $progressPercent = max(0, min(100, (int) ($value('progress_percent', $existing['progress_percent'] ?? 0) ?? 0)));
        $executionStatus = $this->normalizeExecutionStatus($value('execution_status'), $progressPercent);
        $projectCode = trim((string) ($value('project_code', $existing['code'] ?? $this->defaultProjectCode($project)) ?? ''));

        if ($projectCode === '') {
            $projectCode = $this->defaultProjectCode($project);
        }

        return [
            ...$existing,
            'code' => $projectCode,
            'execution_status' => $executionStatus,
            'progress_percent' => $progressPercent,
            'project_category' => (string) ($value('project_category', $existing['project_category'] ?? $existing['component_category'] ?? 'Infrastructure - Public Safety') ?? 'Infrastructure - Public Safety'),
            'component_category' => (string) ($value('project_category', $existing['project_category'] ?? $existing['component_category'] ?? 'Infrastructure - Public Safety') ?? 'Infrastructure - Public Safety'),
            'region_label' => (string) ($value('region_label', $existing['region_label'] ?? ('Region / '.($project->municipality?->name ?? 'Unassigned'))) ?? ''),
            'location_label' => (string) ($value('location_label', $existing['location_label'] ?? ($project->municipality?->name ?? 'Unassigned location')) ?? ''),
            'implementing_partner' => (string) ($value('implementing_partner', $existing['implementing_partner'] ?? $existing['implemented_by'] ?? ($project->municipality?->name ?? 'UNDP Partner')) ?? ''),
            'implemented_by' => (string) ($value('implementing_partner', $existing['implemented_by'] ?? $existing['implementing_partner'] ?? ($project->municipality?->name ?? 'UNDP Partner')) ?? ''),
            'program_lead' => (string) ($value('program_lead', $existing['program_lead'] ?? 'UNDP Libya') ?? 'UNDP Libya'),
            'development_goal_area' => (string) ($value('development_goal_area', $existing['development_goal_area'] ?? $existing['goal_area'] ?? 'Public Safety') ?? 'Public Safety'),
            'goal_area' => (string) ($value('development_goal_area', $existing['goal_area'] ?? $existing['development_goal_area'] ?? 'Public Safety') ?? 'Public Safety'),
            'execution_model' => (string) ($value('execution_model', $existing['execution_model'] ?? 'Government-led implementation with donor support') ?? 'Government-led implementation with donor support'),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'expected_end_date' => $endDate,
            'duration_months' => $this->durationMonths($startDate, $endDate)
                ?? max(1, (int) ($existing['duration_months'] ?? 3)),
            'objectives' => $this->normalizeStringList($value('objectives', $existing['objectives'] ?? [])),
            'hard_components' => $this->normalizeStringList($value('hard_components', $existing['hard_components'] ?? [])),
            'soft_components' => $this->normalizeStringList($value('soft_components', $existing['soft_components'] ?? [])),
            'funding_currency' => $this->normalizeCurrencyCode($value('funding_currency', $existing['funding_currency'] ?? 'USD')),
            'funding_budget' => (float) ($value('funding_budget', $existing['funding_budget'] ?? 0) ?? 0),
            'funding_sources' => $this->normalizeStringList($value('funding_sources', $existing['funding_sources'] ?? $existing['donors'] ?? [])),
            'donors' => $this->normalizeStringList($value('funding_sources', $existing['donors'] ?? $existing['funding_sources'] ?? [])),
            'funding_types' => $this->normalizeStringList($value('funding_types', $existing['funding_types'] ?? [])),
            'visibility' => (string) ($value('visibility', $existing['visibility'] ?? 'Internal - Admin & Authorized Stakeholders') ?? 'Internal - Admin & Authorized Stakeholders'),
            'contacts' => $this->normalizeStringList($value('contacts', $existing['contacts'] ?? [])),
            'is_invited' => ! empty($validated['assigned_reporter_ids'] ?? $existing['is_invited'] ?? false),
            'created_by_name' => (string) ($existing['created_by_name'] ?? $request->user()->name),
            'updated_by_name' => (string) $request->user()->name,
        ];
    }

    private function syncAssignedReporters(Project $project, array $reporterIds, int $assignedBy): void
    {
        $syncPayload = collect($reporterIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->mapWithKeys(fn (int $id): array => [$id => ['assigned_by' => $assignedBy]])
            ->all();

        $project->assignedReporters()->sync($syncPayload);
    }

    private function availableReporterOptions(?int $municipalityId = null): array
    {
        return User::query()
            ->where('role', UserRole::REPORTER->value)
            ->where('status', 'active')
            ->when($municipalityId, fn (Builder $builder) => $builder->where('municipality_id', $municipalityId))
            ->with('municipality:id,name_en,name_ar')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'municipality_id'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'municipality_id' => $user->municipality_id,
                'municipality_name' => $user->municipality?->name,
            ])
            ->values()
            ->all();
    }

    private function serializeReporterCollection($reporters): array
    {
        return collect($reporters)
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'municipality_id' => $user->municipality_id,
            ])
            ->values()
            ->all();
    }

    private function executionStatuses(): array
    {
        return [
            'not_started' => 'Not Started',
            'planned' => 'Planned',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
        ];
    }

    private function projectCategories(): array
    {
        return [
            'Infrastructure - Public Safety' => 'Infrastructure - Public Safety',
            'Water / Sanitation' => 'Water / Sanitation',
            'Health Services' => 'Health Services',
            'Education Rehabilitation' => 'Education Rehabilitation',
            'Governance / Capacity Building' => 'Governance / Capacity Building',
            'Economic Recovery' => 'Economic Recovery',
        ];
    }

    private function executionModels(): array
    {
        return [
            'Government-led implementation with donor support' => 'Government-led implementation with donor support',
            'Direct implementation by municipal contractor' => 'Direct implementation by municipal contractor',
            'NGO-led delivery partner model' => 'NGO-led delivery partner model',
            'Mixed implementation model' => 'Mixed implementation model',
        ];
    }

    private function developmentGoalAreas(): array
    {
        return [
            'Public Safety' => 'Public Safety',
            'Primary Healthcare' => 'Primary Healthcare',
            'Education Access' => 'Education Access',
            'Water Access' => 'Water Access',
            'Community Resilience' => 'Community Resilience',
            'Local Governance' => 'Local Governance',
        ];
    }

    private function visibilityOptions(): array
    {
        return [
            'Internal - Admin & Authorized Stakeholders' => 'Internal - Admin & Authorized Stakeholders',
            'Municipality-only internal' => 'Municipality-only internal',
            'Shared with donors (summary only)' => 'Shared with donors (summary only)',
        ];
    }

    private function serializeOptionSet(array $options): array
    {
        return collect($options)
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    private function normalizeExecutionStatus(?string $status, int $progressPercent): string
    {
        if ($status && array_key_exists($status, $this->executionStatuses())) {
            return $status;
        }

        if ($progressPercent >= 100) {
            return 'completed';
        }

        if ($progressPercent > 0) {
            return 'in_progress';
        }

        return 'planned';
    }

    private function defaultProjectCode(Project $project): string
    {
        $municipalityCode = strtoupper((string) ($project->municipality?->code ?? 'GEN'));

        return sprintf('PRJ-%s-%03d', $municipalityCode, $project->id);
    }

    private function safeDateString(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function durationMonths(?string $startDate, ?string $endDate): ?int
    {
        if (! $startDate || ! $endDate) {
            return null;
        }

        try {
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($end->lt($start)) {
            return null;
        }

        return max(1, $start->diffInMonths($end) + 1);
    }

    private function formatDateRange(?string $startDate, ?string $endDate): ?string
    {
        if (! $startDate && ! $endDate) {
            return null;
        }

        try {
            if ($startDate && $endDate) {
                return sprintf(
                    '%s - %s',
                    Carbon::parse($startDate)->format('F Y'),
                    Carbon::parse($endDate)->format('F Y'),
                );
            }

            $date = Carbon::parse($startDate ?: $endDate);

            return $date->format('F Y');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeStringList($items): array
    {
        return collect(is_array($items) ? $items : [])
            ->map(fn ($item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    private function normalizeCurrencyCode(?string $value): string
    {
        $currency = strtoupper(trim((string) $value));

        return $currency !== '' ? $currency : 'USD';
    }

    private function formatMoney(float $amount): string
    {
        $precision = fmod($amount, 1.0) === 0.0 ? 0 : 2;

        return number_format($amount, $precision, '.', ',');
    }
}
