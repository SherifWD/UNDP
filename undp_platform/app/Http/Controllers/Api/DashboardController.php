<?php

namespace App\Http\Controllers\Api;

use App\Enums\FundingRequestStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\FundingRequest;
use App\Models\Municipality;
use App\Models\Project;
use App\Models\Submission;
use App\Services\ProjectAccessService;
use App\Services\SubmissionAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function kpis(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('dashboards.view.system')
            && ! $user->hasPermission('dashboards.view.municipality')
            && ! $user->hasPermission('dashboards.view.partner')
            && ! $user->hasPermission('dashboards.view.own')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'status' => ['nullable', 'string', 'in:'.implode(',', SubmissionStatus::values())],
            'donor_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $query = $this->buildScopedSubmissionQuery($request, $validated);

        $total = (clone $query)->count();
        $approved = (clone $query)->where('status', 'approved')->count();
        $underReview = (clone $query)->where('status', 'under_review')->count();
        $rework = (clone $query)->where('status', 'rework_requested')->count();
        $rejected = (clone $query)->where('status', 'rejected')->count();
        $submitted = (clone $query)->where('status', SubmissionStatus::SUBMITTED->value)->count();
        $queued = (clone $query)->where('status', SubmissionStatus::QUEUED->value)->count();
        $draft = (clone $query)->where('status', SubmissionStatus::DRAFT->value)->count();

        $approvalRate = $total > 0 ? round(($approved / $total) * 100, 1) : 0.0;
        $rejectionRate = $total > 0 ? round(($rejected / $total) * 100, 1) : 0.0;
        $pendingValidation = $underReview + $submitted + $queued;
        $statusCounts = [
            SubmissionStatus::APPROVED->value => $approved,
            SubmissionStatus::UNDER_REVIEW->value => $underReview,
            SubmissionStatus::REWORK_REQUESTED->value => $rework,
            SubmissionStatus::REJECTED->value => $rejected,
            SubmissionStatus::SUBMITTED->value => $submitted,
            SubmissionStatus::QUEUED->value => $queued,
            SubmissionStatus::DRAFT->value => $draft,
        ];

        $dataMetrics = $this->extractSubmissionDataMetrics($query);
        $statusBreakdown = array_filter(
            $statusCounts,
            static fn (int $count): bool => $count > 0,
        );
        $statusSummary = $this->buildStatusSummary($statusCounts, $total);
        $municipalityBreakdown = $this->buildMunicipalityBreakdown($query, $total);
        $projectBreakdown = $this->buildProjectBreakdown($query, $total);
        $trend = $this->buildTrendSeries($query);
        $reviewBacklog = $this->buildReviewBacklog($query);
        $fundingOverview = $this->buildFundingOverview($request, $validated);

        return response()->json([
            'kpis' => [
                'total_submissions' => $total,
                'approved' => $approved,
                'under_review' => $underReview,
                'rework_requested' => $rework,
                'rejected' => $rejected,
                'submitted' => $submitted,
                'queued' => $queued,
                'draft' => $draft,
                'pending_validation' => $pendingValidation,
                'approval_rate_percent' => $approvalRate,
                'rejection_rate_percent' => $rejectionRate,
                'total_actual_beneficiaries' => $dataMetrics['total_actual_beneficiaries'],
                'average_completion_percentage' => $dataMetrics['average_completion_percentage'],
            ],
            'status_breakdown' => $statusBreakdown,
            'status_summary' => $statusSummary,
            'municipality_breakdown' => $municipalityBreakdown,
            'project_breakdown' => $projectBreakdown,
            'review_backlog' => $reviewBacklog,
            'funding_overview' => $fundingOverview,
            'trend' => $trend,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function municipalOverview(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole(UserRole::MUNICIPAL_FOCAL_POINT) && ! $user->hasPermission('dashboards.view.system')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $requestedMunicipalityId = $request->validate([
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
        ])['municipality_id'] ?? null;

        $municipalityId = $user->hasRole(UserRole::MUNICIPAL_FOCAL_POINT)
            ? $user->municipality_id
            : ($requestedMunicipalityId ?: ($user->municipality_id ?: Municipality::query()->orderBy('name_en')->value('id')));

        if (! $municipalityId) {
            return response()->json([
                'message' => 'Municipality scope is required.',
            ], 422);
        }

        $municipality = Municipality::findOrFail($municipalityId);

        $base = Submission::query()->where('municipality_id', $municipalityId);

        $kpis = [
            'total_submissions' => (clone $base)->count(),
            'under_review' => (clone $base)->where('status', 'under_review')->count(),
            'approved' => (clone $base)->where('status', 'approved')->count(),
            'rework_requested' => (clone $base)->where('status', 'rework_requested')->count(),
            'rejected' => (clone $base)->where('status', 'rejected')->count(),
        ];

        $projects = Project::query()
            ->where('municipality_id', $municipalityId)
            ->withCount([
                'submissions as total_submissions',
                'submissions as approved_submissions' => fn (Builder $query) => $query->where('status', 'approved'),
                'submissions as under_review_submissions' => fn (Builder $query) => $query->where('status', 'under_review'),
                'submissions as rework_submissions' => fn (Builder $query) => $query->where('status', 'rework_requested'),
                'submissions as rejected_submissions' => fn (Builder $query) => $query->where('status', 'rejected'),
            ])
            ->orderByDesc('last_update_at')
            ->get()
            ->map(function (Project $project): array {
                $total = (int) $project->total_submissions;
                $approved = (int) $project->approved_submissions;
                $underReview = (int) $project->under_review_submissions;
                $rework = (int) $project->rework_submissions;
                $rejected = (int) $project->rejected_submissions;
                $progress = $total > 0 ? (int) round(($approved / $total) * 100) : 0;

                return [
                    'id' => $project->id,
                    'name_en' => $project->name_en,
                    'name_ar' => $project->name_ar,
                    'name' => $project->name,
                    'total_submissions' => $total,
                    'approved_submissions' => $approved,
                    'under_review_submissions' => $underReview,
                    'rework_submissions' => $rework,
                    'rejected_submissions' => $rejected,
                    'progress' => $progress,
                    'last_update_at' => optional($project->last_update_at)->toIso8601String(),
                ];
            });

        $statusBreakdown = [
            'under_review' => (int) ($kpis['under_review'] ?? 0),
            'approved' => (int) ($kpis['approved'] ?? 0),
            'rework_requested' => (int) ($kpis['rework_requested'] ?? 0),
            'rejected' => (int) ($kpis['rejected'] ?? 0),
        ];

        return response()->json([
            'municipality' => [
                'id' => $municipality->id,
                'name_en' => $municipality->name_en,
                'name_ar' => $municipality->name_ar,
                'name' => $municipality->name,
            ],
            'kpis' => $kpis,
            'status_breakdown' => $statusBreakdown,
            'projects' => $projects,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function mapData(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('dashboards.view.system')
            && ! $user->hasPermission('dashboards.view.municipality')
            && ! $user->hasPermission('dashboards.view.partner')
            && ! $user->hasPermission('dashboards.view.own')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'status' => ['nullable', 'string', 'in:'.implode(',', SubmissionStatus::values())],
            'project_status' => ['nullable', 'string', 'in:active,archived'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'cluster' => ['nullable', Rule::in(['1', '0', 'true', 'false'])],
            'cluster_zoom' => ['nullable', 'integer', 'min:1', 'max:18'],
            'include_submissions' => ['nullable', Rule::in(['1', '0', 'true', 'false'])],
        ]);

        $includeSubmissions = $request->boolean('include_submissions', true);

        $projectQuery = Project::query()
            ->select([
                'id',
                'name_en',
                'name_ar',
                'municipality_id',
                'status',
                'latitude',
                'longitude',
                'last_update_at',
            ])
            ->with('municipality:id,name_en,name_ar');

        $submissionQuery = null;

        if ($includeSubmissions) {
            $submissionQuery = Submission::query()
                ->select([
                    'id',
                    'title',
                    'status',
                    'municipality_id',
                    'project_id',
                    'latitude',
                    'longitude',
                    'updated_at',
                ])
                ->with([
                    'municipality:id,name_en,name_ar',
                    'project:id,name_en,name_ar',
                ]);

            SubmissionAccessService::scope($user, $submissionQuery);
        }

        ProjectAccessService::scope($user, $projectQuery);

        if (! empty($validated['municipality_id']) && $user->hasPermission('dashboards.view.system')) {
            $projectQuery->where('municipality_id', $validated['municipality_id']);
            if ($submissionQuery) {
                $submissionQuery->where('municipality_id', $validated['municipality_id']);
            }
        }

        if (! empty($validated['project_status'])) {
            $projectQuery->where('status', $validated['project_status']);
        }

        if ($submissionQuery && ! empty($validated['status'])) {
            $submissionQuery->where('status', $validated['status']);
        }

        if (! empty($validated['project_id'])) {
            $projectQuery->where('id', $validated['project_id']);
            if ($submissionQuery) {
                $submissionQuery->where('project_id', $validated['project_id']);
            }
        }

        if ($submissionQuery && ! empty($validated['date_from'])) {
            $submissionQuery->whereDate('created_at', '>=', $validated['date_from']);
        }

        if ($submissionQuery && ! empty($validated['date_to'])) {
            $submissionQuery->whereDate('created_at', '<=', $validated['date_to']);
        }

        $projects = $projectQuery
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'type' => 'project',
                'name' => $project->name,
                'municipality' => $project->municipality?->name,
                'status' => $project->status,
                'lat' => (float) $project->latitude,
                'lng' => (float) $project->longitude,
                'last_update_at' => optional($project->last_update_at)->toIso8601String(),
            ])
            ->values()
            ->toBase();

        $submissions = $submissionQuery
            ? $submissionQuery
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->get()
                ->map(fn (Submission $submission): array => [
                    'id' => $submission->id,
                    'type' => 'submission',
                    'name' => $submission->title,
                    'municipality' => $submission->municipality?->name,
                    'project' => $submission->project?->name,
                    'status' => $submission->status,
                    'lat' => (float) $submission->latitude,
                    'lng' => (float) $submission->longitude,
                    'last_update_at' => optional($submission->updated_at)->toIso8601String(),
                ])
                ->values()
                ->toBase()
            : collect();

        $markers = $projects->merge($submissions)->values();

        $clusterEnabled = $request->boolean('cluster', true);
        $clusterZoom = (int) ($validated['cluster_zoom'] ?? 8);
        $clusters = $clusterEnabled
            ? $this->buildMarkerClusters($markers->all(), $clusterZoom)
            : [];

        return response()->json([
            'legend' => [
                'project' => 'Blue marker',
                'submission' => 'Orange marker',
            ],
            'markers' => $markers,
            'clusters' => $clusters,
            'cluster_meta' => [
                'enabled' => $clusterEnabled,
                'zoom' => $clusterZoom,
                'include_submissions' => $includeSubmissions,
            ],
        ]);
    }

    public function partnerOverview(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('dashboards.view.partner')
            && ! $request->user()->hasPermission('dashboards.view.system')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'status' => ['nullable', Rule::in([SubmissionStatus::APPROVED->value])],
        ]);

        $query = Submission::query()->where('status', SubmissionStatus::APPROVED->value);
        SubmissionAccessService::scope($request->user(), $query);

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        if (! empty($validated['municipality_id'])) {
            $query->where('municipality_id', $validated['municipality_id']);
        }

        if (! empty($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }

        $approvedTotal = (clone $query)->count();
        $municipalitiesCovered = (clone $query)->distinct('municipality_id')->count('municipality_id');
        $projectsCovered = (clone $query)->distinct('project_id')->count('project_id');
        $approvedLast30Days = (clone $query)->whereDate('created_at', '>=', now()->subDays(30))->count();
        $approvedLast7Days = (clone $query)->whereDate('created_at', '>=', now()->subDays(7))->count();
        $dataMetrics = $this->extractSubmissionDataMetrics($query);
        $statusCounts = [
            SubmissionStatus::APPROVED->value => $approvedTotal,
        ];
        $municipalityBreakdown = $this->buildMunicipalityBreakdown($query, $approvedTotal);
        $projectBreakdown = $this->buildProjectBreakdown($query, $approvedTotal);
        $trend = $this->buildTrendSeries($query);
        $fundingOverview = $this->buildFundingOverview($request, $validated);

        return response()->json([
            'kpis' => [
                'approved_total' => $approvedTotal,
                'municipalities_covered' => $municipalitiesCovered,
                'projects_covered' => $projectsCovered,
                'approved_last_30_days' => $approvedLast30Days,
                'approved_last_7_days' => $approvedLast7Days,
                'total_actual_beneficiaries' => $dataMetrics['total_actual_beneficiaries'],
                'average_completion_percentage' => $dataMetrics['average_completion_percentage'],
            ],
            'status_breakdown' => $statusCounts,
            'status_summary' => $this->buildStatusSummary($statusCounts, $approvedTotal),
            'municipality_breakdown' => $municipalityBreakdown,
            'project_breakdown' => $projectBreakdown,
            'funding_overview' => $fundingOverview,
            'trend' => $trend,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    private function buildScopedSubmissionQuery(Request $request, array $filters): Builder
    {
        $query = Submission::query();

        SubmissionAccessService::scope($request->user(), $query);

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['municipality_id']) && $request->user()->hasPermission('dashboards.view.system')) {
            $query->where('municipality_id', $filters['municipality_id']);
        }

        return $query;
    }

    private function buildStatusSummary(array $counts, int $total): array
    {
        return collect($counts)
            ->map(function ($count, $status) use ($total): array {
                return [
                    'status' => (string) $status,
                    'label' => $this->submissionStatusLabel((string) $status),
                    'count' => (int) $count,
                    'percentage' => $total > 0 ? round(((int) $count / $total) * 100, 1) : 0.0,
                ];
            })
            ->filter(fn (array $row): bool => $row['count'] > 0)
            ->values()
            ->all();
    }

    private function buildMunicipalityBreakdown(Builder $query, int $grandTotal): array
    {
        $rows = (clone $query)
            ->selectRaw(
                'municipality_id,
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as under_review,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rework_requested,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as submitted,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as draft',
                [
                    SubmissionStatus::APPROVED->value,
                    SubmissionStatus::UNDER_REVIEW->value,
                    SubmissionStatus::REWORK_REQUESTED->value,
                    SubmissionStatus::REJECTED->value,
                    SubmissionStatus::SUBMITTED->value,
                    SubmissionStatus::QUEUED->value,
                    SubmissionStatus::DRAFT->value,
                ],
            )
            ->groupBy('municipality_id')
            ->orderByDesc('total')
            ->get();

        $municipalities = Municipality::query()
            ->whereIn('id', $rows->pluck('municipality_id')->filter()->all())
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($grandTotal, $municipalities): array {
            $municipality = $municipalities->get($row->municipality_id);
            $total = (int) $row->total;
            $approved = (int) $row->approved;
            $underReview = (int) $row->under_review;
            $submitted = (int) $row->submitted;
            $queued = (int) $row->queued;

            return [
                'municipality_id' => (int) $row->municipality_id,
                'municipality_name' => $municipality?->name,
                'count' => $total,
                'approved' => $approved,
                'under_review' => $underReview,
                'rework_requested' => (int) $row->rework_requested,
                'rejected' => (int) $row->rejected,
                'submitted' => $submitted,
                'queued' => $queued,
                'draft' => (int) $row->draft,
                'approval_rate_percent' => $total > 0 ? round(($approved / $total) * 100, 1) : 0.0,
                'pending_validation' => $underReview + $submitted + $queued,
                'percentage_of_total' => $grandTotal > 0 ? round(($total / $grandTotal) * 100, 1) : 0.0,
            ];
        })->values()->all();
    }

    private function buildProjectBreakdown(Builder $query, int $grandTotal): array
    {
        $rows = (clone $query)
            ->selectRaw(
                'project_id,
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as under_review,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rework_requested,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as submitted,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as draft',
                [
                    SubmissionStatus::APPROVED->value,
                    SubmissionStatus::UNDER_REVIEW->value,
                    SubmissionStatus::REWORK_REQUESTED->value,
                    SubmissionStatus::REJECTED->value,
                    SubmissionStatus::SUBMITTED->value,
                    SubmissionStatus::QUEUED->value,
                    SubmissionStatus::DRAFT->value,
                ],
            )
            ->groupBy('project_id')
            ->orderByDesc('total')
            ->get();

        $projects = Project::query()
            ->with('municipality:id,name_en,name_ar')
            ->whereIn('id', $rows->pluck('project_id')->filter()->all())
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($grandTotal, $projects): array {
            $project = $projects->get($row->project_id);
            $total = (int) $row->total;
            $approved = (int) $row->approved;
            $underReview = (int) $row->under_review;
            $submitted = (int) $row->submitted;
            $queued = (int) $row->queued;

            return [
                'project_id' => (int) $row->project_id,
                'project_name' => $project?->name,
                'municipality_name' => $project?->municipality?->name,
                'count' => $total,
                'approved' => $approved,
                'under_review' => $underReview,
                'rework_requested' => (int) $row->rework_requested,
                'rejected' => (int) $row->rejected,
                'submitted' => $submitted,
                'queued' => $queued,
                'draft' => (int) $row->draft,
                'approval_rate_percent' => $total > 0 ? round(($approved / $total) * 100, 1) : 0.0,
                'pending_validation' => $underReview + $submitted + $queued,
                'percentage_of_total' => $grandTotal > 0 ? round(($total / $grandTotal) * 100, 1) : 0.0,
            ];
        })->values()->all();
    }

    private function buildTrendSeries(Builder $query): array
    {
        return (clone $query)
            ->selectRaw(
                'DATE(created_at) as day,
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as under_review,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rework_requested,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as submitted,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as draft',
                [
                    SubmissionStatus::APPROVED->value,
                    SubmissionStatus::UNDER_REVIEW->value,
                    SubmissionStatus::REWORK_REQUESTED->value,
                    SubmissionStatus::REJECTED->value,
                    SubmissionStatus::SUBMITTED->value,
                    SubmissionStatus::QUEUED->value,
                    SubmissionStatus::DRAFT->value,
                ],
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row): array => [
                'day' => (string) $row->day,
                'count' => (int) $row->total,
                'approved' => (int) $row->approved,
                'under_review' => (int) $row->under_review,
                'rework_requested' => (int) $row->rework_requested,
                'rejected' => (int) $row->rejected,
                'submitted' => (int) $row->submitted,
                'queued' => (int) $row->queued,
                'draft' => (int) $row->draft,
            ])
            ->values()
            ->all();
    }

    private function buildReviewBacklog(Builder $query): array
    {
        $pendingBase = (clone $query)->whereIn('status', [
            SubmissionStatus::UNDER_REVIEW->value,
            SubmissionStatus::SUBMITTED->value,
            SubmissionStatus::QUEUED->value,
        ]);

        return [
            [
                'key' => 'fresh',
                'label' => '0-3 days',
                'count' => (clone $pendingBase)->whereDate('created_at', '>=', now()->subDays(3))->count(),
            ],
            [
                'key' => 'watch',
                'label' => '4-7 days',
                'count' => (clone $pendingBase)
                    ->whereDate('created_at', '<', now()->subDays(3))
                    ->whereDate('created_at', '>=', now()->subDays(7))
                    ->count(),
            ],
            [
                'key' => 'stale',
                'label' => '8+ days',
                'count' => (clone $pendingBase)->whereDate('created_at', '<', now()->subDays(7))->count(),
            ],
        ];
    }

    private function buildFundingOverview(Request $request, array $filters): ?array
    {
        $user = $request->user();

        if (
            ! $user->hasPermission('funding_requests.view.all')
            && ! $user->hasPermission('funding_requests.view.own')
            && ! $user->hasPermission('funding_requests.create')
            && ! $user->hasPermission('funding_requests.review')
        ) {
            return null;
        }

        $query = FundingRequest::query()->with('donor:id,name');

        if (
            ! $user->hasPermission('funding_requests.view.all')
            && ! $user->hasPermission('funding_requests.review')
        ) {
            $query->where('donor_user_id', $user->id);
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (! empty($filters['municipality_id'])) {
            $query->whereHas('project', function (Builder $builder) use ($filters): void {
                $builder->where('municipality_id', $filters['municipality_id']);
            });
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $donorOptions = (clone $query)
            ->get()
            ->groupBy(fn (FundingRequest $row) => (int) $row->donor_user_id)
            ->map(function ($rows): array {
                /** @var FundingRequest $sample */
                $sample = $rows->first();
                $approvedCurrencyTotals = $this->fundingCurrencyTotals(
                    $rows->where('status', FundingRequestStatus::APPROVED->value)->all(),
                );
                $totalCurrencyTotals = $this->fundingCurrencyTotals($rows->all());

                return [
                    'id' => (int) ($sample?->donor_user_id ?? 0),
                    'name' => $sample?->donor?->name ?? 'Unknown donor',
                    'approved_currency_totals' => $approvedCurrencyTotals,
                    'approved_requested_amount' => round((float) $rows->where('status', FundingRequestStatus::APPROVED->value)->sum('amount'), 2),
                    'approved_requested_amount_label' => $this->formatCurrencyTotals($approvedCurrencyTotals),
                    'total_currency_totals' => $totalCurrencyTotals,
                    'total_requested_amount' => round((float) $rows->sum('amount'), 2),
                    'total_requested_amount_label' => $this->formatCurrencyTotals($totalCurrencyTotals),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        if (! empty($filters['donor_user_id'])) {
            $query->where('donor_user_id', $filters['donor_user_id']);
        }

        $total = (clone $query)->count();
        $pending = (clone $query)->where('status', FundingRequestStatus::PENDING->value)->count();
        $approved = (clone $query)->where('status', FundingRequestStatus::APPROVED->value)->count();
        $declined = (clone $query)->where('status', FundingRequestStatus::DECLINED->value)->count();
        $totalAmount = round((float) (clone $query)->sum('amount'), 2);
        $pendingAmount = round((float) (clone $query)->where('status', FundingRequestStatus::PENDING->value)->sum('amount'), 2);
        $approvedAmount = round((float) (clone $query)->where('status', FundingRequestStatus::APPROVED->value)->sum('amount'), 2);
        $declinedAmount = round((float) (clone $query)->where('status', FundingRequestStatus::DECLINED->value)->sum('amount'), 2);
        $allRows = (clone $query)->get(['amount', 'currency', 'status']);
        $totalCurrencyTotals = $this->fundingCurrencyTotals($allRows->all());
        $pendingCurrencyTotals = $this->fundingCurrencyTotals(
            $allRows->where('status', FundingRequestStatus::PENDING->value)->all(),
        );
        $approvedCurrencyTotals = $this->fundingCurrencyTotals(
            $allRows->where('status', FundingRequestStatus::APPROVED->value)->all(),
        );
        $declinedCurrencyTotals = $this->fundingCurrencyTotals(
            $allRows->where('status', FundingRequestStatus::DECLINED->value)->all(),
        );

        return [
            'total_requests' => $total,
            'pending_requests' => $pending,
            'approved_requests' => $approved,
            'declined_requests' => $declined,
            'total_requested_amount' => $totalAmount,
            'total_requested_amount_label' => $this->formatCurrencyTotals($totalCurrencyTotals),
            'total_currency_totals' => $totalCurrencyTotals,
            'pending_requested_amount' => $pendingAmount,
            'pending_requested_amount_label' => $this->formatCurrencyTotals($pendingCurrencyTotals),
            'pending_currency_totals' => $pendingCurrencyTotals,
            'approved_requested_amount' => $approvedAmount,
            'approved_requested_amount_label' => $this->formatCurrencyTotals($approvedCurrencyTotals),
            'approved_currency_totals' => $approvedCurrencyTotals,
            'declined_requested_amount' => $declinedAmount,
            'declined_requested_amount_label' => $this->formatCurrencyTotals($declinedCurrencyTotals),
            'declined_currency_totals' => $declinedCurrencyTotals,
            'approval_rate_percent' => $total > 0 ? round(($approved / $total) * 100, 1) : 0.0,
            'pending_share_percent' => $total > 0 ? round(($pending / $total) * 100, 1) : 0.0,
            'selected_donor_user_id' => ! empty($filters['donor_user_id']) ? (int) $filters['donor_user_id'] : null,
            'donor_options' => $donorOptions,
            'status_breakdown' => [
                FundingRequestStatus::PENDING->value => $pending,
                FundingRequestStatus::APPROVED->value => $approved,
                FundingRequestStatus::DECLINED->value => $declined,
            ],
            'status_summary' => collect([
                FundingRequestStatus::PENDING->value => $pending,
                FundingRequestStatus::APPROVED->value => $approved,
                FundingRequestStatus::DECLINED->value => $declined,
            ])->map(function ($count, $status) use ($total): array {
                $statusEnum = FundingRequestStatus::tryFrom((string) $status);

                return [
                    'status' => (string) $status,
                    'label' => $statusEnum?->label() ?? ucfirst(str_replace('_', ' ', (string) $status)),
                    'count' => (int) $count,
                    'percentage' => $total > 0 ? round(((int) $count / $total) * 100, 1) : 0.0,
                ];
            })->filter(fn (array $row): bool => $row['count'] > 0)->values()->all(),
        ];
    }

    private function fundingCurrencyTotals(array $rows): array
    {
        return collect($rows)
            ->groupBy(fn ($row): string => strtoupper((string) (data_get($row, 'currency') ?: 'USD')))
            ->map(function ($group, string $currency): array {
                $amount = round((float) $group->sum(fn ($item) => (float) data_get($item, 'amount', 0)), 2);

                return [
                    'currency' => $currency,
                    'amount' => $amount,
                    'label' => $currency.' '.$this->formatMoney($amount),
                ];
            })
            ->values()
            ->all();
    }

    private function formatCurrencyTotals(array $totals): string
    {
        if ($totals === []) {
            return '0';
        }

        return collect($totals)
            ->map(fn (array $row): string => (string) ($row['label'] ?? (($row['currency'] ?? 'USD').' '.$this->formatMoney((float) ($row['amount'] ?? 0)))))
            ->implode(' + ');
    }

    private function formatMoney(float $amount): string
    {
        $precision = fmod($amount, 1.0) === 0.0 ? 0 : 2;

        return number_format($amount, $precision, '.', ',');
    }

    private function submissionStatusLabel(string $status): string
    {
        return match ($status) {
            SubmissionStatus::UNDER_REVIEW->value => 'Under Review',
            SubmissionStatus::REWORK_REQUESTED->value => 'Rework Requested',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function buildMarkerClusters(array $markers, int $zoom): array
    {
        $gridSize = $this->gridSizeForZoom($zoom);
        $groups = [];

        foreach ($markers as $marker) {
            $lat = (float) ($marker['lat'] ?? 0.0);
            $lng = (float) ($marker['lng'] ?? 0.0);

            $latBucket = floor($lat / $gridSize) * $gridSize;
            $lngBucket = floor($lng / $gridSize) * $gridSize;
            $key = sprintf('%.6f:%.6f', $latBucket, $lngBucket);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'count' => 0,
                    'lat_total' => 0.0,
                    'lng_total' => 0.0,
                    'projects' => 0,
                    'submissions' => 0,
                ];
            }

            $groups[$key]['count'] += 1;
            $groups[$key]['lat_total'] += $lat;
            $groups[$key]['lng_total'] += $lng;

            if (($marker['type'] ?? null) === 'project') {
                $groups[$key]['projects'] += 1;
            } else {
                $groups[$key]['submissions'] += 1;
            }
        }

        return collect($groups)->map(function (array $group): array {
            return [
                'type' => 'cluster',
                'key' => $group['key'],
                'count' => $group['count'],
                'lat' => round($group['lat_total'] / max($group['count'], 1), 6),
                'lng' => round($group['lng_total'] / max($group['count'], 1), 6),
                'projects' => $group['projects'],
                'submissions' => $group['submissions'],
            ];
        })->values()->all();
    }

    private function gridSizeForZoom(int $zoom): float
    {
        return match (true) {
            $zoom <= 5 => 1.2,
            $zoom <= 7 => 0.8,
            $zoom <= 9 => 0.4,
            $zoom <= 11 => 0.2,
            $zoom <= 13 => 0.1,
            $zoom <= 15 => 0.05,
            default => 0.02,
        };
    }

    /**
     * @return array{total_actual_beneficiaries:int,average_completion_percentage:float}
     */
    private function extractSubmissionDataMetrics(Builder $query): array
    {
        $rows = (clone $query)->get(['data']);
        $beneficiariesTotal = 0;
        $completionValues = [];

        foreach ($rows as $row) {
            $beneficiariesValue = data_get($row->data, 'actual_beneficiaries');
            if (is_numeric($beneficiariesValue)) {
                $beneficiariesTotal += max(0, (int) $beneficiariesValue);
            }

            $completionValue = data_get($row->data, 'approximate_completion_percentage');
            if (is_numeric($completionValue)) {
                $completionValues[] = max(0.0, min(100.0, (float) $completionValue));
            }
        }

        $averageCompletion = count($completionValues) > 0
            ? round(array_sum($completionValues) / count($completionValues), 1)
            : 0.0;

        return [
            'total_actual_beneficiaries' => $beneficiariesTotal,
            'average_completion_percentage' => $averageCompletion,
        ];
    }
}
