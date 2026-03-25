<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\Submission;
use App\Services\ProjectAccessService;
use App\Services\SubmissionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProjectController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('projects.view')) {
            return $this->errorResponse(__('Access denied.'), 403);
        }

        $validator = Validator::make($request->all(), [
            'search' => ['nullable', 'string', 'max:255'],
            'list_type' => ['nullable', Rule::in(['all', 'invited', 'area'])],
            'execution_status' => ['nullable', Rule::in(['planned', 'in_progress', 'completed', 'not_started'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        $validated = $validator->validated();
        $projects = $this->visibleProjects($request);

        if (! empty($validated['search'])) {
            $search = mb_strtolower(trim((string) $validated['search']));

            $projects = $projects->filter(function (Project $project) use ($search): bool {
                $meta = $this->projectMeta($project);

                return str_contains(mb_strtolower($project->name_en), $search)
                    || str_contains(mb_strtolower($project->name_ar), $search)
                    || str_contains(mb_strtolower((string) $meta['code']), $search)
                    || str_contains(mb_strtolower((string) $meta['goal_area']), $search);
            })->values();
        }

        if (! empty($validated['execution_status'])) {
            $projects = $projects->filter(fn (Project $project): bool => $this->projectMeta($project)['execution_status'] === $validated['execution_status'])
                ->values();
        }

        if (($validated['list_type'] ?? 'all') === 'invited' && ! $user->hasRole(UserRole::REPORTER)) {
            $projects = $projects->filter(fn (Project $project): bool => (bool) $this->projectMeta($project)['is_invited'])
                ->values();
        }

        $limit = $validated['limit'] ?? 25;

        return $this->successResponse([
            'items' => $projects
                ->take($limit)
                ->map(fn (Project $project): array => $this->serializeProject($project, $user))
                ->values(),
            'meta' => [
                'total' => $projects->count(),
                'returned' => min($projects->count(), $limit),
                'list_type' => $validated['list_type'] ?? 'all',
            ],
        ]);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('projects.view')) {
            return $this->errorResponse(__('Access denied.'), 403);
        }

        $project->loadMissing('municipality');

        if (! ProjectAccessService::canView($user, $project)) {
            return $this->errorResponse(__('Access denied.'), 403);
        }

        $submissionQuery = Submission::query()->where('project_id', $project->id);
        SubmissionAccessService::scope($user, $submissionQuery);

        return $this->successResponse([
            'project' => $this->serializeProject($project, $user),
            'stats' => [
                'total_submissions' => (clone $submissionQuery)->count(),
                'drafts' => (clone $submissionQuery)->where('status', 'draft')->count(),
                'submitted' => (clone $submissionQuery)->whereIn('status', ['submitted', 'under_review', 'rework_requested'])->count(),
                'approved' => (clone $submissionQuery)->where('status', 'approved')->count(),
                'rejected' => (clone $submissionQuery)->where('status', 'rejected')->count(),
            ],
        ]);
    }

    private function visibleProjects(Request $request)
    {
        $query = Project::query()
            ->with('municipality')
            ->where('status', 'active')
            ->orderByDesc('last_update_at')
            ->orderBy('name_en');

        ProjectAccessService::scope($request->user(), $query);

        return $query->get();
    }
}
