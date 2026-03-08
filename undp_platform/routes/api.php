<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MunicipalityController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RealtimeController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SubmissionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/live/events', [RealtimeController::class, 'stream'])->middleware('throttle:api');

Route::prefix('auth')->group(function (): void {
    Route::post('/request-otp', [AuthController::class, 'requestOtp'])->middleware('throttle:otp');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp');

    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/device-token', [AuthController::class, 'updateDeviceToken']);
    });
});

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function (): void {
    Route::get('/roles', [UserController::class, 'roles'])->middleware('permission:users.view');

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
    Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->middleware('permission:users.toggle_status');
    Route::get('/users/{user}/audit', [UserController::class, 'audit'])->middleware('permission:audit.view');

    Route::get('/municipalities', [MunicipalityController::class, 'index'])->middleware('permission:municipalities.view');
    Route::post('/municipalities', [MunicipalityController::class, 'store'])->middleware('permission:municipalities.manage');
    Route::put('/municipalities/{municipality}', [MunicipalityController::class, 'update'])->middleware('permission:municipalities.manage');

    Route::get('/projects', [ProjectController::class, 'index'])->middleware('permission:projects.view');
    Route::get('/projects/options', [ProjectController::class, 'options'])->middleware('permission:projects.view');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->middleware('permission:projects.view');
    Route::post('/projects', [ProjectController::class, 'store'])->middleware('permission:projects.manage');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->middleware('permission:projects.manage');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->middleware('permission:projects.manage');

    Route::get('/workflow/statuses', [WorkflowController::class, 'statuses']);
    Route::get('/workflow/reasons', [WorkflowController::class, 'reasons']);

    Route::get('/submissions', [SubmissionController::class, 'index']);
    Route::get('/submissions/pending', [SubmissionController::class, 'pending'])->middleware('permission:submissions.validate');
    Route::post('/submissions', [SubmissionController::class, 'store'])->middleware('permission:submissions.create');
    Route::get('/submissions/{submission}', [SubmissionController::class, 'show']);
    Route::get('/submissions/{submission}/timeline', [SubmissionController::class, 'timeline']);
    Route::post('/submissions/{submission}/approve', [SubmissionController::class, 'approve'])->middleware('permission:submissions.approve');
    Route::post('/submissions/{submission}/reject', [SubmissionController::class, 'reject'])->middleware('permission:submissions.reject');
    Route::post('/submissions/{submission}/rework', [SubmissionController::class, 'requestRework'])->middleware('permission:submissions.rework');

    Route::post('/media/presign-upload', [MediaController::class, 'presignUpload'])->middleware('permission:media.upload');
    Route::post('/media/{mediaAsset}/complete', [MediaController::class, 'completeUpload'])->middleware('permission:media.upload');
    Route::get('/media/{mediaAsset}/download-url', [MediaController::class, 'downloadUrl']);

    Route::get('/dashboard/kpis', [DashboardController::class, 'kpis'])
        ->middleware('any_permission:dashboards.view.system,dashboards.view.municipality,dashboards.view.partner,dashboards.view.own');
    Route::get('/dashboard/municipal-overview', [DashboardController::class, 'municipalOverview'])
        ->middleware('any_permission:dashboards.view.system,dashboards.view.municipality');
    Route::get('/dashboard/map', [DashboardController::class, 'mapData'])
        ->middleware('any_permission:dashboards.view.system,dashboards.view.municipality,dashboards.view.partner,dashboards.view.own');
    Route::get('/dashboard/partner', [DashboardController::class, 'partnerOverview'])
        ->middleware('any_permission:dashboards.view.system,dashboards.view.partner');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->middleware('permission:audit.view');

    Route::get('/settings', [SettingController::class, 'show']);
    Route::put('/settings', [SettingController::class, 'update'])->middleware('permission:workflow.manage');

    Route::post('/exports/tasks', [ExportController::class, 'createTask'])
        ->middleware('any_permission:reports.export.csv,reports.export.pdf');
    Route::get('/exports/tasks/{exportTask}', [ExportController::class, 'task'])
        ->middleware('any_permission:reports.export.csv,reports.export.pdf');
    Route::get('/exports/tasks/{exportTask}/download', [ExportController::class, 'downloadTask'])
        ->middleware('any_permission:reports.export.csv,reports.export.pdf')
        ->name('exports.tasks.download');

    Route::get('/exports/csv', [ExportController::class, 'csv'])->middleware('permission:reports.export.csv');
    Route::get('/exports/pdf', [ExportController::class, 'pdf'])->middleware('permission:reports.export.pdf');
});

require __DIR__.'/api_mobile.php';
