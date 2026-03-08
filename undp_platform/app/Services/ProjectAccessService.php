<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ProjectAccessService
{
    public static function scope(User $user, Builder $query): Builder
    {
        if ($user->hasRole(UserRole::REPORTER)) {
            return $query->whereHas('assignedReporters', function (Builder $builder) use ($user): void {
                $builder->where('users.id', $user->id);
            });
        }

        if ($user->hasRole(UserRole::MUNICIPAL_FOCAL_POINT) && $user->municipality_id) {
            return $query->where('municipality_id', $user->municipality_id);
        }

        return $query;
    }

    public static function canView(User $user, Project $project): bool
    {
        if ($user->hasRole(UserRole::REPORTER)) {
            return $project->assignedReporters()
                ->where('users.id', $user->id)
                ->exists();
        }

        if ($user->hasRole(UserRole::MUNICIPAL_FOCAL_POINT)) {
            return $user->municipality_id && (int) $user->municipality_id === (int) $project->municipality_id;
        }

        return true;
    }
}
