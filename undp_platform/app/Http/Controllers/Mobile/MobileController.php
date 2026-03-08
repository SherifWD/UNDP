<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Submission;
use App\Models\User;
use Carbon\Carbon;

abstract class MobileController extends Controller
{
    protected function projectMeta(Project $project): array
    {
        $stored = is_array($project->mobile_meta) ? $project->mobile_meta : [];
        $progressPercent = max(0, min(100, (int) ($stored['progress_percent'] ?? 0)));
        $executionStatus = $this->normalizeProjectExecutionStatus(
            $stored['execution_status'] ?? null,
            $progressPercent,
        );
        $expectedEndDate = $stored['end_date']
            ?? $stored['expected_end_date']
            ?? now()->addMonths(9)->endOfMonth()->toDateString();

        return [
            'code' => (string) ($stored['code'] ?? sprintf('PRJ-%03d', $project->id)),
            'goal_area' => (string) ($stored['development_goal_area'] ?? $stored['goal_area'] ?? ($project->municipality?->name ?? 'General Area')),
            'location_label' => (string) ($stored['location_label'] ?? ($project->municipality?->name ?? 'Unassigned area')),
            'component_category' => (string) ($stored['project_category'] ?? $stored['component_category'] ?? 'Hard Component - Infrastructure'),
            'donors' => collect($stored['funding_sources'] ?? $stored['donors'] ?? ['UNDP Libya'])
                ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
                ->values()
                ->all(),
            'program_lead' => (string) ($stored['program_lead'] ?? 'UNDP Libya'),
            'duration_months' => max(1, (int) ($stored['duration_months'] ?? 3)),
            'implemented_by' => (string) ($stored['implementing_partner'] ?? $stored['implemented_by'] ?? ($project->municipality?->name ?? 'UNDP Partner')),
            'contacts' => collect($stored['contacts'] ?? [])
                ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
                ->values()
                ->all(),
            'execution_status' => $executionStatus,
            'progress_percent' => $progressPercent,
            'is_invited' => (bool) ($stored['is_invited'] ?? true),
            'expected_end_date' => $expectedEndDate,
            'expected_end_label' => $this->formatMonthYear($expectedEndDate),
        ];
    }

    protected function serializeProject(Project $project, ?User $viewer = null): array
    {
        $meta = $this->projectMeta($project);

        return [
            'id' => $project->id,
            'code' => $meta['code'],
            'name' => $project->name,
            'name_en' => $project->name_en,
            'name_ar' => $project->name_ar,
            'description' => $project->description,
            'lifecycle_status' => $project->status,
            'lifecycle_status_label' => strtoupper($project->status),
            'execution_status' => $meta['execution_status'],
            'execution_status_label' => $this->projectExecutionStatusLabel($meta['execution_status']),
            'progress_percent' => $meta['progress_percent'],
            'progress_tone' => $this->progressTone($meta['execution_status'], $meta['progress_percent']),
            'goal_area' => $meta['goal_area'],
            'location_label' => $meta['location_label'],
            'component_category' => $meta['component_category'],
            'donors' => $meta['donors'],
            'donors_label' => implode(' - ', $meta['donors']),
            'program_lead' => $meta['program_lead'],
            'duration_months' => $meta['duration_months'],
            'duration_label' => $this->durationLabel($meta['duration_months']),
            'implemented_by' => $meta['implemented_by'],
            'contacts' => $meta['contacts'],
            'is_invited' => $meta['is_invited'],
            'expected_end_date' => $meta['expected_end_date'],
            'expected_end_label' => $meta['expected_end_label'],
            'municipality' => $project->municipality ? [
                'id' => $project->municipality->id,
                'name' => $project->municipality->name,
                'name_en' => $project->municipality->name_en,
                'name_ar' => $project->municipality->name_ar,
            ] : null,
            'coordinates' => [
                'latitude' => $project->latitude,
                'longitude' => $project->longitude,
            ],
            'last_update_at' => optional($project->last_update_at)->toIso8601String(),
            'can_report' => (bool) ($viewer?->hasPermission('submissions.create') ?? false),
            'reporting_defaults' => [
                'report_type' => config('mobile.reporting.report_type'),
                'report_title' => sprintf('%s Progress Update', $project->name_en),
            ],
        ];
    }

    protected function serializeSubmissionCard(Submission $submission): array
    {
        $submission->loadMissing('project.municipality');

        $project = $submission->project;
        $projectPayload = $project ? $this->serializeProject($project, $submission->reporter) : null;
        $form = $this->submissionFormData($submission);

        return [
            'id' => $submission->id,
            'external_reference' => $this->submissionReference($submission),
            'status' => $submission->status,
            'status_label' => $this->mobileSubmissionStatusLabel($submission->status),
            'status_tone' => $this->mobileSubmissionStatusTone($submission->status),
            'title' => $submission->title,
            'details' => $submission->details,
            'project' => $projectPayload,
            'project_name' => $project?->name,
            'location_label' => (string) ($form['location_label'] ?? ($projectPayload['location_label'] ?? 'Unknown location')),
            'submitted_at' => optional($submission->submitted_at)->toIso8601String(),
            'submitted_on_label' => optional($submission->submitted_at)->format('d/m/Y'),
            'updated_at' => optional($submission->updated_at)->toIso8601String(),
            'created_at' => optional($submission->created_at)->toIso8601String(),
            'can_edit' => $this->canEditSubmission($submission),
            'reporting_period' => $form['reporting_period_label'] ?? $this->reportingPeriodLabel($submission),
        ];
    }

    protected function serializeSubmissionDetail(Submission $submission): array
    {
        $payload = $this->serializeSubmissionCard($submission);
        $form = $this->submissionFormData($submission);

        $payload['validation_comment'] = $submission->validation_comment;
        $payload['validated_at'] = optional($submission->validated_at)->toIso8601String();
        $payload['data'] = $form;
        $payload['summary'] = [
            'report_type' => $form['report_type'] ?? config('mobile.reporting.report_type'),
            'reporting_period' => $form['reporting_period_label'] ?? $this->reportingPeriodLabel($submission),
            'report_title' => $submission->title,
            'summary_of_observation' => $form['summary_of_observation'] ?? $submission->details,
            'key_updates' => array_values($form['key_updates'] ?? []),
            'submission_location' => $form['location_label'] ?? null,
            'challenges_risks_issues' => $form['challenges_risks_issues'] ?? null,
            'risk_description' => $form['risk_description'] ?? null,
            'delay_constraint' => $form['delay_constraint'] ?? null,
            'impact_description' => $form['impact_description'] ?? null,
            'notes' => $form['notes'] ?? null,
        ];

        return $payload;
    }

    protected function submissionFormData(Submission $submission): array
    {
        return is_array($submission->data) ? $submission->data : [];
    }

    protected function canEditSubmission(Submission $submission): bool
    {
        return in_array($submission->status, [
            SubmissionStatus::DRAFT->value,
            SubmissionStatus::REWORK_REQUESTED->value,
            SubmissionStatus::REJECTED->value,
        ], true);
    }

    protected function submissionReference(Submission $submission): string
    {
        return 'ER'.str_pad((string) $submission->id, 5, '0', STR_PAD_LEFT);
    }

    protected function mobileSubmissionStatusLabel(string $status): string
    {
        return match ($status) {
            SubmissionStatus::REWORK_REQUESTED->value => 'Sent Back',
            SubmissionStatus::UNDER_REVIEW->value => 'Under Review',
            SubmissionStatus::SUBMITTED->value => 'Submitted',
            SubmissionStatus::APPROVED->value => 'Approved',
            SubmissionStatus::REJECTED->value => 'Rejected',
            SubmissionStatus::DRAFT->value => 'Draft',
            SubmissionStatus::QUEUED->value => 'Queued',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    protected function mobileSubmissionStatusTone(string $status): string
    {
        return match ($status) {
            SubmissionStatus::APPROVED->value => 'success',
            SubmissionStatus::SUBMITTED->value => 'success-soft',
            SubmissionStatus::UNDER_REVIEW->value => 'warning',
            SubmissionStatus::REWORK_REQUESTED->value => 'danger-soft',
            SubmissionStatus::REJECTED->value => 'danger',
            SubmissionStatus::DRAFT->value => 'muted',
            SubmissionStatus::QUEUED->value => 'warning-soft',
            default => 'muted',
        };
    }

    protected function reportingPeriodLabel(Submission $submission): string
    {
        $date = $submission->submitted_at ?? $submission->updated_at ?? $submission->created_at ?? now();

        return sprintf('Week %s - %s', $date->format('W'), $date->format('F Y'));
    }

    protected function normalizeProjectExecutionStatus(?string $status, int $progressPercent): string
    {
        $valid = ['planned', 'in_progress', 'completed', 'not_started'];

        if (is_string($status) && in_array($status, $valid, true)) {
            return $status;
        }

        if ($progressPercent >= 100) {
            return 'completed';
        }

        if ($progressPercent > 0) {
            return 'in_progress';
        }

        return 'planned';
    }

    protected function projectExecutionStatusLabel(string $status): string
    {
        return match ($status) {
            'planned' => 'Planned',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'not_started' => 'Not Started',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    protected function progressTone(string $status, int $progressPercent): string
    {
        if ($status === 'completed' || $progressPercent >= 66) {
            return 'success';
        }

        if ($status === 'in_progress' || $progressPercent >= 1) {
            return 'warning';
        }

        return 'danger';
    }

    protected function durationLabel(int $months): string
    {
        return $months === 1 ? '1 Month' : sprintf('%d Months', $months);
    }

    protected function formatMonthYear(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        try {
            return Carbon::parse($date)->format('M Y');
        } catch (\Throwable) {
            return null;
        }
    }
}
