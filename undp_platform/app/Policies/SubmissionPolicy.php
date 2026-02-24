<?php

namespace App\Policies;

use App\Models\Submission;
use App\Models\User;
use App\Services\SubmissionAccessService;

class SubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('submissions.view.own')
            || $user->hasPermission('submissions.view.municipality')
            || $user->hasPermission('submissions.view.all')
            || $user->hasPermission('submissions.view.approved_aggregated');
    }

    public function view(User $user, Submission $submission): bool
    {
        return SubmissionAccessService::canView($user, $submission);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('submissions.create');
    }

    public function validate(User $user, Submission $submission): bool
    {
        return SubmissionAccessService::canValidate($user, $submission);
    }

    public function approve(User $user, Submission $submission): bool
    {
        return $user->hasPermission('submissions.approve')
            && SubmissionAccessService::canValidate($user, $submission);
    }

    public function reject(User $user, Submission $submission): bool
    {
        return $user->hasPermission('submissions.reject')
            && SubmissionAccessService::canValidate($user, $submission);
    }

    public function rework(User $user, Submission $submission): bool
    {
        return $user->hasPermission('submissions.rework')
            && SubmissionAccessService::canValidate($user, $submission);
    }

    public function update(User $user, Submission $submission): bool
    {
        return false;
    }

    public function delete(User $user, Submission $submission): bool
    {
        return false;
    }
}
