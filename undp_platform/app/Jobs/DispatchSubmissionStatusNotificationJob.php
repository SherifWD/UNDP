<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Notifications\SubmissionStatusChangedNotification;
use App\Services\FcmService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchSubmissionStatusNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $submissionId)
    {
    }

    public function handle(FcmService $fcmService): void
    {
        $submission = Submission::query()
            ->with(['reporter', 'project'])
            ->find($this->submissionId);

        if (! $submission || ! $submission->reporter) {
            return;
        }

        $submission->reporter->notify(new SubmissionStatusChangedNotification($submission));

        $title = 'Submission status updated';
        $body = sprintf(
            '%s is now %s',
            $submission->title,
            str_replace('_', ' ', $submission->status),
        );

        $fcmService->sendToUser($submission->reporter, $title, $body, [
            'submission_id' => $submission->id,
            'status' => $submission->status,
            'project_name' => $submission->project?->name,
        ]);
    }
}
