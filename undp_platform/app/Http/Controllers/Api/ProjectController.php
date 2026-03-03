<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\Submission;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SubmissionAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'status' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'with_stats' => ['nullable', Rule::in(['1', '0', 'true', 'false'])],
        ]);

        $withStats = $request->boolean('with_stats');

        $query = Project::query()->with('municipality:id,name_en,name_ar');
        $tabCountQuery = Project::query();

        $this->applyProjectScope($request, $query, $validated, true);
        $this->applyProjectScope($request, $tabCountQuery, $validated, false);

        if ($withStats) {
            $this->applyStatCounts($request, $query);
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

    public function show(Request $request, Project $project): JsonResponse
    {
        if (! $this->canViewProject($request, $project)) {
            return response()->json([
                'message' => 'Access denied.',
            ], 403);
        }

        $project->load('municipality:id,name_en,name_ar');

        $submissionQuery = Submission::query()->where('project_id', $project->id);
        SubmissionAccessService::scope($request->user(), $submissionQuery);

        $total = (clone $submissionQuery)->count();
        $approved = (clone $submissionQuery)->where('status', 'approved')->count();
        $pending = (clone $submissionQuery)->whereIn('status', ['under_review', 'submitted', 'rework_requested', 'queued'])->count();
        $rejected = (clone $submissionQuery)->where('status', 'rejected')->count();
        $mediaAttachments = MediaAsset::query()
            ->whereIn('submission_id', (clone $submissionQuery)->select('id'))
            ->count();
        $activeReporters = (clone $submissionQuery)->distinct('reporter_id')->count('reporter_id');

        $reporterIds = (clone $submissionQuery)
            ->whereNotNull('reporter_id')
            ->distinct('reporter_id')
            ->limit(6)
            ->pluck('reporter_id')
            ->all();

        $reporters = User::query()
            ->whereIn('id', $reporterIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->values();

        return response()->json([
            'project' => $this->serializeProject($project, true, [
                'total_submissions' => $total,
                'approved_submissions' => $approved,
                'pending_submissions' => $pending,
                'rejected_submissions' => $rejected,
                'media_attachments' => $mediaAttachments,
                'active_reporters' => $activeReporters,
            ]),
            'reporters' => $reporters,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'municipality_id' => ['required', 'integer', 'exists:municipalities,id'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_ar' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $project = Project::create([
            ...$validated,
            'status' => $validated['status'] ?? 'active',
            'last_update_at' => now(),
        ]);

        AuditLogger::log(
            action: 'projects.created',
            entityType: 'projects',
            entityId: $project->id,
            after: $project->toArray(),
            request: $request,
        );

        return response()->json([
            'message' => __('Project created successfully.'),
            'project' => $project->load('municipality'),
        ], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'municipality_id' => ['sometimes', 'integer', 'exists:municipalities,id'],
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
        ]);

        $before = $project->toArray();

        $project->fill($validated);
        $project->last_update_at = now();
        $project->save();

        AuditLogger::log(
            action: 'projects.updated',
            entityType: 'projects',
            entityId: $project->id,
            before: $before,
            after: $project->toArray(),
            request: $request,
        );

        return response()->json([
            'message' => __('Project updated successfully.'),
            'project' => $project->load('municipality'),
        ]);
    }

    private function applyProjectScope(Request $request, Builder $query, array $validated, bool $applyMunicipalityFilter): void
    {
        $user = $request->user();

        if ($user->municipality_id && $user->hasRole(UserRole::MUNICIPAL_FOCAL_POINT)) {
            $query->where('municipality_id', $user->municipality_id);
        }

        if ($user->municipality_id && $user->hasRole(UserRole::REPORTER)) {
            $query->where('municipality_id', $user->municipality_id);
        }

        if ($applyMunicipalityFilter && ! empty($validated['municipality_id'])) {
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
        $pendingStatuses = ['under_review', 'submitted', 'rework_requested', 'queued'];

        $submissionScope = function (Builder $submissionQuery) use ($user): void {
            if ($user->hasRole(UserRole::REPORTER)) {
                $submissionQuery->where('reporter_id', $user->id);
                return;
            }

            if ($user->hasRole(UserRole::PARTNER_DONOR_VIEWER)) {
                $submissionQuery->where('status', 'approved');
            }
        };

        $query->withCount([
            'submissions as total_submissions_count' => function (Builder $submissionQuery) use ($submissionScope): void {
                $submissionScope($submissionQuery);
            },
            'submissions as approved_submissions_count' => function (Builder $submissionQuery) use ($submissionScope): void {
                $submissionScope($submissionQuery);
                $submissionQuery->where('status', 'approved');
            },
            'submissions as pending_submissions_count' => function (Builder $submissionQuery) use ($submissionScope, $pendingStatuses): void {
                $submissionScope($submissionQuery);
                $submissionQuery->whereIn('status', $pendingStatuses);
            },
            'submissions as rejected_submissions_count' => function (Builder $submissionQuery) use ($submissionScope): void {
                $submissionScope($submissionQuery);
                $submissionQuery->where('status', 'rejected');
            },
        ]);
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

    private function serializeProject(Project $project, bool $withStats = false, ?array $statOverrides = null): array
    {
        $payload = [
            'id' => $project->id,
            'name_en' => $project->name_en,
            'name_ar' => $project->name_ar,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status,
            'latitude' => $project->latitude,
            'longitude' => $project->longitude,
            'municipality' => $project->municipality ? [
                'id' => $project->municipality->id,
                'name_en' => $project->municipality->name_en,
                'name_ar' => $project->municipality->name_ar,
                'name' => $project->municipality->name,
            ] : null,
            'last_update_at' => optional($project->last_update_at)->toIso8601String(),
        ];

        if (! $withStats) {
            return $payload;
        }

        $total = (int) ($statOverrides['total_submissions'] ?? $project->total_submissions_count ?? 0);
        $approved = (int) ($statOverrides['approved_submissions'] ?? $project->approved_submissions_count ?? 0);
        $pending = (int) ($statOverrides['pending_submissions'] ?? $project->pending_submissions_count ?? 0);
        $rejected = (int) ($statOverrides['rejected_submissions'] ?? $project->rejected_submissions_count ?? 0);
        $mediaAttachments = (int) ($statOverrides['media_attachments'] ?? 0);
        $activeReporters = (int) ($statOverrides['active_reporters'] ?? 0);
        $progressPercent = $total > 0
            ? (int) round(($approved / $total) * 100)
            : ($project->status === 'archived' ? 100 : 0);

        $payload['stats'] = [
            'total_submissions' => $total,
            'approved_submissions' => $approved,
            'pending_submissions' => $pending,
            'rejected_submissions' => $rejected,
            'media_attachments' => $mediaAttachments,
            'active_reporters' => $activeReporters,
            'progress_percent' => $progressPercent,
        ];

        return $payload;
    }

    private function canViewProject(Request $request, Project $project): bool
    {
        $user = $request->user();

        if ($user->hasRole(UserRole::UNDP_ADMIN) || $user->hasRole(UserRole::AUDITOR) || $user->hasRole(UserRole::PARTNER_DONOR_VIEWER)) {
            return true;
        }

        if ($user->hasRole(UserRole::MUNICIPAL_FOCAL_POINT) || $user->hasRole(UserRole::REPORTER)) {
            return (int) $project->municipality_id === (int) $user->municipality_id;
        }

        return false;
    }
}
