<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\Submission;
use App\Services\ProjectAccessService;
use App\Services\SubmissionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class HomeController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'invited_status' => ['nullable', Rule::in(['all', 'planned', 'inprogress', 'in_progress', 'completed'])],
            'invited_search' => ['nullable', 'string', 'max:255'],
            'invited_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'area_status' => ['nullable', Rule::in(['all', 'planned', 'inprogress', 'in_progress', 'completed'])],
            'area_search' => ['nullable', 'string', 'max:255'],
            'area_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->returnValidationError($validator);
        }

        $validated = $validator->validated();
        $user = $request->user();

        $submissionQuery = Submission::query();
        SubmissionAccessService::scope($user, $submissionQuery);

        $approved = (clone $submissionQuery)->where('status', 'approved')->count();
        $rejected = (clone $submissionQuery)->where('status', 'rejected')->count();
        $pending = (clone $submissionQuery)->whereIn('status', [
            'queued',
            'submitted',
            'under_review',
            'rework_requested',
        ])->count();

        $projectQuery = Project::query()
            ->with('municipality')
            ->where('status', 'active')
            ->orderByDesc('last_update_at')
            ->orderBy('name_en');

        ProjectAccessService::scope($user, $projectQuery);

        $projects = $projectQuery->get()->values();
        $baseInvitedProjects = $user->hasRole(UserRole::REPORTER)
            ? $projects->values()
            : $projects
                ->filter(fn (Project $project): bool => (bool) $this->projectMeta($project)['is_invited'])
                ->values();

        $invitedExecutionFilter = $this->normalizeExecutionStatusFilter($validated['invited_status'] ?? null);
        $areaExecutionFilter = $this->normalizeExecutionStatusFilter($validated['area_status'] ?? null);

        $invitedProjects = $this->filterProjects(
            $baseInvitedProjects,
            $validated['invited_search'] ?? null,
            $invitedExecutionFilter,
        );

        $areaProjects = $this->filterProjects(
            $projects,
            $validated['area_search'] ?? null,
            $areaExecutionFilter,
        );

        $invitedLimit = (int) ($validated['invited_limit'] ?? 10);
        $areaLimit = (int) ($validated['area_limit'] ?? 10);

        return $this->successResponse([
            'presence' => [
                'is_online' => true,
                'label' => 'Online',
            ],
            'current_municipality' => $user->municipality ? [
                'id' => $user->municipality->id,
                'name' => $user->municipality->name,
                'name_en' => $user->municipality->name_en,
                'name_ar' => $user->municipality->name_ar,
            ] : null,
            'submission_overview' => [
                'approved' => $approved,
                'rejected' => $rejected,
                'pending' => $pending,
                'total' => $approved + $rejected + $pending,
            ],
            'projects' => [
                'invited_count' => $invitedProjects->count(),
                'invited' => $invitedProjects
                    ->take($invitedLimit)
                    ->map(fn (Project $project): array => $this->serializeProject($project, $user))
                    ->values(),
                'area_count' => $areaProjects->count(),
                'area' => $areaProjects
                    ->take($areaLimit)
                    ->map(fn (Project $project): array => $this->serializeProject($project, $user))
                    ->values(),
                'filters' => [
                    'invited' => [
                        'status' => $invitedExecutionFilter ?? 'all',
                        'search' => $validated['invited_search'] ?? null,
                    ],
                    'area' => [
                        'status' => $areaExecutionFilter ?? 'all',
                        'search' => $validated['area_search'] ?? null,
                    ],
                ],
            ],
            'inbox' => [
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    private function normalizeExecutionStatusFilter(?string $status): ?string
    {
        if (! is_string($status) || trim($status) === '' || $status === 'all') {
            return null;
        }

        $normalized = mb_strtolower(trim($status));

        return match ($normalized) {
            'inprogress', 'in_progress' => 'in_progress',
            'planned' => 'planned',
            'completed' => 'completed',
            default => null,
        };
    }

    private function filterProjects(Collection $projects, ?string $search, ?string $executionStatus): Collection
    {
        if (is_string($search) && trim($search) !== '') {
            $needle = mb_strtolower(trim($search));

            $projects = $projects->filter(function (Project $project) use ($needle): bool {
                $meta = $this->projectMeta($project);

                $haystack = mb_strtolower(implode(' ', array_filter([
                    (string) $project->name_en,
                    (string) $project->name_ar,
                    (string) $project->name,
                    (string) ($project->description ?? ''),
                    (string) ($project->municipality?->name_en ?? ''),
                    (string) ($project->municipality?->name_ar ?? ''),
                    (string) ($project->municipality?->name ?? ''),
                    (string) ($meta['code'] ?? ''),
                    (string) ($meta['goal_area'] ?? ''),
                    (string) ($meta['location_label'] ?? ''),
                    (string) ($meta['component_category'] ?? ''),
                ])));

                return str_contains($haystack, $needle);
            })->values();
        }

        if ($executionStatus) {
            $projects = $projects->filter(function (Project $project) use ($executionStatus): bool {
                $projectExecutionStatus = (string) ($this->projectMeta($project)['execution_status'] ?? '');

                if ($executionStatus === 'planned') {
                    return in_array($projectExecutionStatus, ['planned', 'not_started'], true);
                }

                return $projectExecutionStatus === $executionStatus;
            })->values();
        }

        return $projects->values();
    }
}
