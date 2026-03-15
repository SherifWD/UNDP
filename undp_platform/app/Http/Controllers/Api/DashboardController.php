<?php

namespace App\Http\Controllers\Api;

use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
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

        $dataMetrics = $this->extractSubmissionDataMetrics($query);

        $statusBreakdown = (clone $query)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($value) => (int) $value)
            ->all();

        $municipalityRows = (clone $query)
            ->selectRaw('municipality_id, COUNT(*) as total')
            ->groupBy('municipality_id')
            ->get();

        $municipalities = Municipality::query()
            ->whereIn('id', $municipalityRows->pluck('municipality_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $municipalityBreakdown = $municipalityRows->map(function ($row) use ($municipalities): array {
            $municipality = $municipalities->get($row->municipality_id);

            return [
                'municipality_id' => (int) $row->municipality_id,
                'municipality_name' => $municipality?->name,
                'count' => (int) $row->total,
            ];
        })->values();

        $projectRows = (clone $query)
            ->selectRaw('project_id, COUNT(*) as total')
            ->groupBy('project_id')
            ->get();

        $projects = Project::query()
            ->whereIn('id', $projectRows->pluck('project_id')->all())
            ->get()
            ->keyBy('id');

        $projectBreakdown = $projectRows->map(function ($row) use ($projects): array {
            $project = $projects->get($row->project_id);

            return [
                'project_id' => (int) $row->project_id,
                'project_name' => $project?->name,
                'count' => (int) $row->total,
            ];
        })->values();

        $trend = (clone $query)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row): array => [
                'day' => $row->day,
                'count' => (int) $row->total,
            ]);

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
            'municipality_breakdown' => $municipalityBreakdown,
            'project_breakdown' => $projectBreakdown,
            'trend' => $trend,
        ]);
    }

    public function municipalOverview(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('dashboards.view.municipality')
            && ! $request->user()->hasPermission('dashboards.view.system')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
        ]);

        $municipalityId = $request->user()->hasPermission('dashboards.view.system')
            ? ($validated['municipality_id'] ?? $request->user()->municipality_id)
            : $request->user()->municipality_id;

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

        $municipalityRows = (clone $query)
            ->selectRaw('municipality_id, COUNT(*) as total')
            ->groupBy('municipality_id')
            ->orderByDesc('total')
            ->get();

        $municipalities = Municipality::query()
            ->whereIn('id', $municipalityRows->pluck('municipality_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $municipalityBreakdown = $municipalityRows->map(function ($row) use ($municipalities): array {
            $municipality = $municipalities->get($row->municipality_id);

            return [
                'municipality_id' => (int) $row->municipality_id,
                'municipality_name' => $municipality?->name,
                'count' => (int) $row->total,
            ];
        })->values();

        $projectRows = (clone $query)
            ->selectRaw('project_id, COUNT(*) as total')
            ->groupBy('project_id')
            ->orderByDesc('total')
            ->get();

        $projects = Project::query()
            ->whereIn('id', $projectRows->pluck('project_id')->all())
            ->get()
            ->keyBy('id');

        $projectBreakdown = $projectRows->map(function ($row) use ($projects): array {
            $project = $projects->get($row->project_id);

            return [
                'project_id' => (int) $row->project_id,
                'project_name' => $project?->name,
                'count' => (int) $row->total,
            ];
        })->values();

        $trend = (clone $query)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row): array => [
                'day' => $row->day,
                'count' => (int) $row->total,
            ]);

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
            'status_breakdown' => [
                SubmissionStatus::APPROVED->value => $approvedTotal,
            ],
            'municipality_breakdown' => $municipalityBreakdown,
            'project_breakdown' => $projectBreakdown,
            'trend' => $trend,
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
