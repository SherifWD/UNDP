<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ExportTask;
use App\Models\Submission;
use App\Models\User;
use App\Jobs\GenerateExportTaskJob;
use App\Services\AuditLogger;
use App\Services\SubmissionAccessService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function createTask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => ['required', 'in:csv,pdf'],
            'type' => ['nullable', 'in:submissions,audit_logs,users,summary'],
            'status' => ['nullable', 'string', 'max:100'],
            'project_id' => ['nullable', 'integer'],
            'municipality_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'action' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'in:'.implode(',', UserRole::values())],
            'sort_by' => ['nullable', 'in:name,email,phone,role,status,created_at,last_login_at'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
        ]);

        $format = $validated['format'];
        $type = $validated['type'] ?? ($format === 'pdf' ? 'summary' : 'submissions');

        if ($format === 'csv' && ! $request->user()->hasPermission('reports.export.csv')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ($format === 'pdf' && ! $request->user()->hasPermission('reports.export.pdf')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ($format === 'pdf') {
            $type = 'summary';
        }

        if ($type === 'audit_logs' && ! $request->user()->hasPermission('audit.view')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ($type === 'users' && ! $request->user()->hasPermission('users.view')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $task = ExportTask::query()->create([
            'user_id' => $request->user()->id,
            'format' => $format,
            'type' => $type,
            'status' => 'queued',
            'progress' => 0,
            'filters' => collect($validated)
                ->except(['format', 'type'])
                ->filter(fn ($value) => $value !== null && $value !== '')
                ->all(),
        ]);

        GenerateExportTaskJob::dispatch($task->id)->onQueue('exports');

        AuditLogger::log(
            action: 'exports.task_created',
            entityType: 'export_tasks',
            entityId: $task->id,
            metadata: [
                'format' => $task->format,
                'type' => $task->type,
            ],
            request: $request,
        );

        return response()->json([
            'message' => 'Export task queued successfully.',
            'task' => $this->serializeTask($task),
        ], 202);
    }

    public function task(Request $request, ExportTask $exportTask): JsonResponse
    {
        if ((int) $exportTask->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if (! $this->hasTaskPermission($request->user(), $exportTask)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return response()->json([
            'task' => $this->serializeTask($exportTask),
        ]);
    }

    public function downloadTask(Request $request, ExportTask $exportTask)
    {
        if ((int) $exportTask->user_id !== (int) $request->user()->id) {
            abort(403, 'Access denied.');
        }

        if (! $this->hasTaskPermission($request->user(), $exportTask)) {
            abort(403, 'Access denied.');
        }

        if ($exportTask->status !== 'ready' || ! $exportTask->file_path) {
            abort(409, 'Export file is not ready yet.');
        }

        $disk = $exportTask->file_disk ?: 'local';

        if (! Storage::disk($disk)->exists($exportTask->file_path)) {
            abort(410, 'Export file no longer exists.');
        }

        return Storage::disk($disk)->download(
            $exportTask->file_path,
            $exportTask->file_name ?: basename($exportTask->file_path),
            array_filter([
                'Content-Type' => $exportTask->mime_type,
            ]),
        );
    }

    public function csv(Request $request): StreamedResponse
    {
        if (! $request->user()->hasPermission('reports.export.csv')) {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'type' => ['nullable', 'in:submissions,audit_logs,users'],
            'status' => ['nullable', 'string', 'max:100'],
            'project_id' => ['nullable', 'integer'],
            'municipality_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'action' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'in:'.implode(',', UserRole::values())],
            'sort_by' => ['nullable', 'in:name,email,phone,role,status,created_at,last_login_at'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
        ]);

        $type = $validated['type'] ?? 'submissions';

        if ($type === 'audit_logs') {
            if (! $request->user()->hasPermission('audit.view')) {
                abort(403, 'Access denied.');
            }

            return $this->exportAuditLogsCsv($validated);
        }

        if ($type === 'users') {
            if (! $request->user()->hasPermission('users.view')) {
                abort(403, 'Access denied.');
            }

            return $this->exportUsersCsv($validated);
        }

        return $this->exportSubmissionsCsv($request, $validated);
    }

    public function pdf(Request $request)
    {
        if (! $request->user()->hasPermission('reports.export.pdf')) {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:100'],
            'project_id' => ['nullable', 'integer'],
            'municipality_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $query = Submission::query();

        SubmissionAccessService::scope($request->user(), $query);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }

        if (! empty($validated['municipality_id'])) {
            $query->where('municipality_id', $validated['municipality_id']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

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

        $pdf = Pdf::loadView('exports.summary', [
            'generatedAt' => now(),
            'generatedBy' => $request->user(),
            'summary' => $summary,
            'statusBreakdown' => $statusBreakdown,
            'filters' => $validated,
        ])->setPaper('a4', 'portrait');

        return $pdf->download('undp-summary-report-'.now()->format('Ymd_His').'.pdf');
    }

    private function exportSubmissionsCsv(Request $request, array $filters): StreamedResponse
    {
        $query = Submission::query()->with(['reporter:id,name', 'project:id,name_en,name_ar', 'municipality:id,name_en,name_ar']);

        SubmissionAccessService::scope($request->user(), $query);

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

        $filename = 'submissions-export-'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, [
                'Submission ID',
                'Title',
                'Status',
                'Reporter',
                'Project',
                'Municipality',
                'Submitted At',
                'Created At',
            ]);

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

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function exportAuditLogsCsv(array $filters): StreamedResponse
    {
        $query = AuditLog::query()->with('actor:id,name,role');

        if (! empty($filters['action'])) {
            $query->where('action', 'like', '%'.$filters['action'].'%');
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $filename = 'audit-log-export-'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, [
                'Log ID',
                'Timestamp',
                'Action',
                'Actor',
                'Role',
                'Entity Type',
                'Entity ID',
            ]);

            $query->latest('id')->chunk(300, function ($rows) use ($out): void {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->id,
                        optional($row->created_at)->toDateTimeString(),
                        $row->action,
                        $row->actor?->name,
                        $row->actor?->role,
                        $row->entity_type,
                        $row->entity_id,
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function exportUsersCsv(array $filters): StreamedResponse
    {
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
        $query->orderBy($sortBy, $sortDir);

        $filename = 'users-export-'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, [
                'User ID',
                'Name',
                'Email',
                'Phone',
                'Role',
                'Status',
                'Municipality',
                'Last Login',
                'Created At',
            ]);

            $query->chunk(300, function ($rows) use ($out): void {
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

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function serializeTask(ExportTask $task): array
    {
        return [
            'id' => $task->id,
            'format' => $task->format,
            'type' => $task->type,
            'status' => $task->status,
            'progress' => (int) $task->progress,
            'error_message' => $task->error_message,
            'file_name' => $task->file_name,
            'size_bytes' => $task->size_bytes,
            'mime_type' => $task->mime_type,
            'created_at' => optional($task->created_at)->toIso8601String(),
            'started_at' => optional($task->started_at)->toIso8601String(),
            'completed_at' => optional($task->completed_at)->toIso8601String(),
            'expires_at' => optional($task->expires_at)->toIso8601String(),
            'download_url' => $task->status === 'ready'
                ? route('exports.tasks.download', ['exportTask' => $task->id])
                : null,
        ];
    }

    private function hasTaskPermission(User $user, ExportTask $task): bool
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
}
