<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\ExportTask;
use App\Models\Submission;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuditLogPresenter;
use App\Services\SubmissionAccessService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateExportTaskJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $taskId)
    {
    }

    public function handle(): void
    {
        $task = ExportTask::query()->with('user')->find($this->taskId);

        if (! $task || ! $task->user) {
            return;
        }

        if (! $this->isTaskAuthorized($task->user, $task)) {
            $this->markFailed($task, 'You are no longer authorized to generate this export.');

            return;
        }

        $task->forceFill([
            'status' => 'processing',
            'progress' => 10,
            'started_at' => now(),
            'error_message' => null,
        ])->save();

        try {
            $relativePath = $this->buildRelativePath($task);
            $absolutePath = Storage::disk('local')->path($relativePath);
            $directory = dirname($absolutePath);

            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            [$mimeType, $sizeBytes] = $task->format === 'pdf'
                ? $this->generatePdf($task, $relativePath)
                : $this->generateCsv($task, $absolutePath);

            $task->forceFill([
                'status' => 'ready',
                'progress' => 100,
                'file_disk' => 'local',
                'file_path' => $relativePath,
                'file_name' => basename($relativePath),
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'completed_at' => now(),
                'expires_at' => now()->addDays(7),
            ])->save();

            AuditLogger::log(
                action: 'exports.task_ready',
                entityType: 'export_tasks',
                entityId: $task->id,
                metadata: [
                    'format' => $task->format,
                    'type' => $task->type,
                    'file_name' => $task->file_name,
                    'size_bytes' => $sizeBytes,
                ],
                actor: $task->user,
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->markFailed($task, $exception->getMessage());
        }
    }

    /**
     * @return array{0:string,1:int}
     */
    private function generateCsv(ExportTask $task, string $absolutePath): array
    {
        $filters = (array) $task->filters;
        $out = fopen($absolutePath, 'wb');

        if ($out === false) {
            throw new \RuntimeException('Unable to open export file for writing.');
        }

        if ($task->type === 'audit_logs') {
            fputcsv($out, [
                'Log ID',
                'Timestamp',
                'Summary',
                'Action',
                'Module',
                'Affected Record',
                'Actor',
                'Role',
                'Page / Source',
                'Entity Type',
                'Entity ID',
            ]);

            $query = AuditLog::query()->with('actor:id,name,role');
            $presenter = app(AuditLogPresenter::class);

            if (! empty($filters['action'])) {
                $query->where('action', 'like', '%'.$filters['action'].'%');
            }
            if (! empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }
            if (! empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }

            $query->latest('id')->chunk(300, function ($rows) use ($out, $presenter): void {
                foreach ($rows as $row) {
                    $presented = $presenter->present($row);

                    fputcsv($out, [
                        $row->id,
                        optional($row->created_at)->toDateTimeString(),
                        $presented['summary'] ?? '',
                        $presented['action_label'] ?? $row->action,
                        $presented['module_label'] ?? '',
                        $presented['subject_label'] ?? '',
                        $row->actor?->name,
                        $row->actor?->role,
                        $presented['page_label'] ?? '',
                        $row->entity_type,
                        $row->entity_id,
                    ]);
                }
            });
        } elseif ($task->type === 'users') {
            fputcsv($out, ['User ID', 'Name', 'Email', 'Phone', 'Role', 'Status', 'Municipality', 'Last Login', 'Created At']);

            $query = User::query()->with('municipality:id,name_en,name_ar');

            if (! empty($filters['search'])) {
                $search = $filters['search'];
                $query->where(function ($builder) use ($search): void {
                    $builder
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone_e164', 'like', "%{$search}%");
                });
            }
            if (! empty($filters['role'])) {
                $query->where('role', $filters['role']);
            }
            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (! empty($filters['municipality_id'])) {
                $query->where('municipality_id', $filters['municipality_id']);
            }

            $sortBy = $filters['sort_by'] ?? 'created_at';
            $sortDir = $filters['sort_dir'] ?? 'desc';
            $query->orderBy($sortBy, $sortDir)->chunk(300, function ($rows) use ($out): void {
                foreach ($rows as $user) {
                    fputcsv($out, [
                        $user->id,
                        $user->name,
                        $user->email,
                        $user->phone_e164,
                        $user->role,
                        $user->status,
                        $user->municipality?->name,
                        optional($user->last_login_at)->toDateTimeString(),
                        optional($user->created_at)->toDateTimeString(),
                    ]);
                }
            });
        } else {
            fputcsv($out, ['Submission ID', 'Title', 'Status', 'Reporter', 'Project', 'Municipality', 'Submitted At', 'Created At']);

            $query = Submission::query()->with([
                'reporter:id,name',
                'project:id,name_en,name_ar',
                'municipality:id,name_en,name_ar',
            ]);

            SubmissionAccessService::scope($task->user, $query);
            $this->applySubmissionFilters($query, $task->user, $filters);

            $query->orderBy('id')->chunk(300, function ($rows) use ($out): void {
                foreach ($rows as $submission) {
                    fputcsv($out, [
                        $submission->id,
                        $submission->title,
                        $submission->status,
                        $submission->reporter?->name,
                        $submission->project?->name,
                        $submission->municipality?->name,
                        optional($submission->submitted_at)->toDateTimeString(),
                        optional($submission->created_at)->toDateTimeString(),
                    ]);
                }
            });
        }

        fclose($out);

        $this->refreshProgress($task, 80);

        return ['text/csv', (int) filesize($absolutePath)];
    }

    /**
     * @return array{0:string,1:int}
     */
    private function generatePdf(ExportTask $task, string $relativePath): array
    {
        $filters = (array) $task->filters;
        $query = Submission::query();

        SubmissionAccessService::scope($task->user, $query);
        $this->applySubmissionFilters($query, $task->user, $filters);

        $summary = [
            'total_submissions' => (clone $query)->count(),
            'approved' => (clone $query)->where('status', 'approved')->count(),
            'under_review' => (clone $query)->where('status', 'under_review')->count(),
            'rework_requested' => (clone $query)->where('status', 'rework_requested')->count(),
            'rejected' => (clone $query)->where('status', 'rejected')->count(),
        ];

        $statusBreakdown = (clone $query)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $pdfBinary = Pdf::loadView('exports.summary', [
            'generatedAt' => now(),
            'generatedBy' => $task->user,
            'summary' => $summary,
            'statusBreakdown' => $statusBreakdown,
            'filters' => $filters,
        ])->setPaper('a4', 'portrait')->output();

        Storage::disk('local')->put($relativePath, $pdfBinary);
        $this->refreshProgress($task, 80);

        return ['application/pdf', strlen($pdfBinary)];
    }

    private function applySubmissionFilters(Builder $query, User $user, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (! empty($filters['municipality_id'])) {
            $query->where('municipality_id', $filters['municipality_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
    }

    private function isTaskAuthorized(User $user, ExportTask $task): bool
    {
        if ($task->format === 'csv') {
            if (! $user->hasPermission('reports.export.csv')) {
                return false;
            }

            if ($task->type === 'audit_logs' && ! $user->hasPermission('audit.view')) {
                return false;
            }

            if ($task->type === 'users' && ! $user->hasPermission('users.view')) {
                return false;
            }

            return true;
        }

        return $user->hasPermission('reports.export.pdf');
    }

    private function buildRelativePath(ExportTask $task): string
    {
        $extension = $task->format === 'pdf' ? 'pdf' : 'csv';
        $timestamp = now()->format('Ymd_His');
        $normalizedType = in_array($task->type, ['submissions', 'audit_logs', 'users', 'summary'], true)
            ? $task->type
            : 'submissions';

        return sprintf(
            'exports/async/%d/undp-%s-%d-%s.%s',
            $task->user_id,
            $normalizedType,
            $task->id,
            $timestamp,
            $extension,
        );
    }

    private function refreshProgress(ExportTask $task, int $progress): void
    {
        $task->forceFill([
            'progress' => max(0, min($progress, 99)),
        ])->save();
    }

    private function markFailed(ExportTask $task, string $message): void
    {
        $task->forceFill([
            'status' => 'failed',
            'progress' => 100,
            'error_message' => mb_substr($message, 0, 1500),
            'completed_at' => now(),
        ])->save();

        AuditLogger::log(
            action: 'exports.task_failed',
            entityType: 'export_tasks',
            entityId: $task->id,
            metadata: [
                'format' => $task->format,
                'type' => $task->type,
                'error' => $task->error_message,
            ],
            actor: $task->user,
        );
    }
}
