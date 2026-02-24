<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SubmissionAccessService
{
    public static function scope(User $user, Builder $query): Builder
    {
        if ($user->hasRole(UserRole::UNDP_ADMIN) || $user->hasRole(UserRole::AUDITOR)) {
            return $query;
        }

        if ($user->hasRole(UserRole::REPORTER)) {
            return $query->where('reporter_id', $user->id);
        }

        if ($user->hasRole(UserRole::MUNICIPAL_FOCAL_POINT)) {
            return $query->where('municipality_id', $user->municipality_id);
        }

        if ($user->hasRole(UserRole::PARTNER_DONOR_VIEWER)) {
            return $query->where('status', 'approved');
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canView(User $user, Submission $submission): bool
    {
        if ($user->hasRole(UserRole::UNDP_ADMIN) || $user->hasRole(UserRole::AUDITOR)) {
            return true;
        }

        if ($user->hasRole(UserRole::REPORTER)) {
            return (int) $submission->reporter_id === (int) $user->id;
        }

        if ($user->hasRole(UserRole::MUNICIPAL_FOCAL_POINT)) {
            return (int) $submission->municipality_id === (int) $user->municipality_id;
        }

        if ($user->hasRole(UserRole::PARTNER_DONOR_VIEWER)) {
            return $submission->status === 'approved';
        }

        return false;
    }

    public static function canValidate(User $user, Submission $submission): bool
    {
        if (! $user->hasPermission('submissions.validate')) {
            return false;
        }

        if ($user->hasRole(UserRole::UNDP_ADMIN)) {
            return true;
        }

        if ($user->hasRole(UserRole::MUNICIPAL_FOCAL_POINT)) {
            return (int) $submission->municipality_id === (int) $user->municipality_id;
        }

        return false;
    }
}
