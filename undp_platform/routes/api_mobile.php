<?php

use App\Http\Controllers\Api\MediaController as ApiMediaController;
use App\Http\Controllers\Mobile\HomeController;
use App\Http\Controllers\Mobile\InboxController;
use App\Http\Controllers\Mobile\ProfileController;
use App\Http\Controllers\Mobile\ProjectController;
use App\Http\Controllers\Mobile\ReportingController;
use App\Http\Controllers\Mobile\SettingsController;
use App\Http\Controllers\Mobile\SubmissionController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {
        Route::get('/home', [HomeController::class, 'index']);

        Route::get('/projects', [ProjectController::class, 'index']);
        Route::get('/projects/{project}', [ProjectController::class, 'show']);

        Route::get('/reporting/options', [ReportingController::class, 'options']);

        Route::get('/submissions', [SubmissionController::class, 'index']);
        Route::post('/submissions', [SubmissionController::class, 'store']);
        Route::put('/submissions/{submission}', [SubmissionController::class, 'update']);
        Route::get('/submissions/{submission}', [SubmissionController::class, 'show']);
        Route::get('/submissions/{submission}/summary', [SubmissionController::class, 'summary']);
        Route::get('/submissions/{submission}/media', [SubmissionController::class, 'mediaIndex']);
        Route::delete('/submissions/{submission}/media/{mediaAsset}', [SubmissionController::class, 'destroyMedia']);

        Route::post('/media/presign-upload', [ApiMediaController::class, 'presignUpload'])->middleware('permission:media.upload');
        Route::post('/media/{mediaAsset}/complete', [ApiMediaController::class, 'completeUpload'])->middleware('permission:media.upload');
        Route::get('/media/{mediaAsset}/download-url', [ApiMediaController::class, 'downloadUrl']);

        Route::get('/inbox', [InboxController::class, 'index']);
        Route::patch('/inbox/{notification}/read', [InboxController::class, 'markRead']);
        Route::patch('/inbox/read-all', [InboxController::class, 'markAllRead']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);

        Route::get('/settings', [SettingsController::class, 'show']);
        Route::patch('/settings/language', [SettingsController::class, 'updateLanguage']);
    });
