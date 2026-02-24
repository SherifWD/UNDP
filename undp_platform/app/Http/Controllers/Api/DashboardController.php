<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Models\Municipality;
use App\Models\Project;
use App\Models\Submission;
use App\Services\SubmissionAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            ])
            ->orderByDesc('last_update_at')
            ->get()
            ->map(function (Project $project): array {
                $total = (int) $project->total_submissions;
                $approved = (int) $project->approved_submissions;
                $progress = $total > 0 ? (int) round(($approved / $total) * 100) : 0;

                return [
                    'id' => $project->id,
                    'name_en' => $project->name_en,
                    'name_ar' => $project->name_ar,
                    'name' => $project->name,
                    'total_submissions' => $total,
                    'approved_submissions' => $approved,
                    'progress' => $progress,
                    'last_update_at' => optional($project->last_update_at)->toIso8601String(),
                ];
            });

        return response()->json([
            'municipality' => [
                'id' => $municipality->id,
                'name_en' => $municipality->name_en,
                'name_ar' => $municipality->name_ar,
                'name' => $municipality->name,
            ],
            'kpis' => $kpis,
            'projects' => $projects,
        ]);
    }

    public function mapData(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('dashboards.view.system')
            && ! $user->hasPermission('dashboards.view.municipality')
            && ! $user->hasPermission('dashboards.view.partner')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'status' => ['nullable', 'string', 'max:100'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'cluster' => ['nullable', 'boolean'],
            'cluster_zoom' => ['nullable', 'integer', 'min:1', 'max:18'],
        ]);

        $projectQuery = Project::query()->with('municipality:id,name_en,name_ar');
        $submissionQuery = Submission::query()->with(['municipality:id,name_en,name_ar', 'project:id,name_en,name_ar']);

        if ($user->hasRole(UserRole::MUNICIPAL_FOCAL_POINT) && $user->municipality_id) {
            $projectQuery->where('municipality_id', $user->municipality_id);
            $submissionQuery->where('municipality_id', $user->municipality_id);
        }

        if (! empty($validated['municipality_id']) && $user->hasPermission('dashboards.view.system')) {
            $projectQuery->where('municipality_id', $validated['municipality_id']);
            $submissionQuery->where('municipality_id', $validated['municipality_id']);
        }

        if (! empty($validated['status'])) {
            $submissionQuery->where('status', $validated['status']);
        }

        if (! empty($validated['project_id'])) {
            $submissionQuery->where('project_id', $validated['project_id']);
        }

        if (! empty($validated['date_from'])) {
            $submissionQuery->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $submissionQuery->whereDate('created_at', '<=', $validated['date_to']);
        }

        if ($user->hasRole(UserRole::PARTNER_DONOR_VIEWER)) {
            $submissionQuery->where('status', 'approved');
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
            ]);

        $submissions = $submissionQuery
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
            ]);

        $markers = $projects->merge($submissions)->values();

        $clusterEnabled = (bool) ($validated['cluster'] ?? true);
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
        ]);

        $query = Submission::query()->where('status', 'approved');

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
            ],
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
}
