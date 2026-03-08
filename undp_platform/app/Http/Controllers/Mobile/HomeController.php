<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\Submission;
use App\Services\ProjectAccessService;
use App\Services\SubmissionAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
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

        $projects = $projectQuery->get();
        $invitedProjects = $user->hasRole(UserRole::REPORTER)
            ? $projects->values()
            : $projects
                ->filter(fn (Project $project): bool => (bool) $this->projectMeta($project)['is_invited'])
                ->values();

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
                    ->take(10)
                    ->map(fn (Project $project): array => $this->serializeProject($project, $user))
                    ->values(),
                'area_count' => $projects->count(),
                'area' => $projects
                    ->take(10)
                    ->map(fn (Project $project): array => $this->serializeProject($project, $user))
                    ->values(),
            ],
            'inbox' => [
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }
}
