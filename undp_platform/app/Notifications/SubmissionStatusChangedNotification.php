<?php

namespace App\Notifications;

use App\Models\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SubmissionStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Submission $submission)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'submission_id' => $this->submission->id,
            'title' => $this->submission->title,
            'status' => $this->submission->status,
            'status_label' => str_replace('_', ' ', ucfirst($this->submission->status)),
            'project_name' => $this->submission->project?->name,
            'updated_at' => optional($this->submission->updated_at)->toIso8601String(),
        ];
    }
}
