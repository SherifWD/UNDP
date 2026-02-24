<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\Submission;
use App\Services\AuditLogger;
use App\Services\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MediaController extends Controller
{
    public function __construct(private readonly MediaStorageService $mediaStorageService)
    {
    }

    public function presignUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'submission_id' => ['required', 'integer', 'exists:submissions,id'],
            'client_media_id' => ['nullable', 'string', 'max:120'],
            'media_type' => ['required', Rule::in(['image', 'video'])],
            'mime_type' => ['required', 'string', Rule::in(config('media.allowed_mime_types', []))],
            'original_filename' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'checksum_sha256' => ['nullable', 'regex:/^[A-Fa-f0-9]{64}$/'],
        ]);

        $submission = Submission::query()->findOrFail($validated['submission_id']);

        $this->authorize('view', $submission);

        if (! $this->withinMediaLimits($submission, $validated['media_type'], (int) $validated['size_bytes'])) {
            return response()->json([
                'message' => 'Media limits exceeded for this submission.',
            ], 422);
        }

        if (! empty($validated['client_media_id'])) {
            $existing = MediaAsset::query()
                ->where('submission_id', $submission->id)
                ->where('client_media_id', $validated['client_media_id'])
                ->first();

            if ($existing) {
                $payload = $this->mediaStorageService->createUploadUrl(
                    $existing,
                    (int) config('media.presigned_upload_expiry_seconds', 900),
                );

                return response()->json([
                    'media_asset_id' => $existing->id,
                    'media_uuid' => $existing->uuid,
                    'object_key' => $existing->object_key,
                    'upload' => $payload,
                    'idempotent_reuse' => true,
                ]);
            }
        }

        $extension = pathinfo($validated['original_filename'], PATHINFO_EXTENSION) ?: ($validated['media_type'] === 'video' ? 'mp4' : 'jpg');
        $uuid = (string) Str::uuid();

        $objectKey = sprintf(
            'evidence/raw/%d/%s/original.%s',
            $submission->id,
            $uuid,
            strtolower($extension),
        );

        $mediaAsset = MediaAsset::query()->create([
            'uuid' => $uuid,
            'submission_id' => $submission->id,
            'uploaded_by' => $request->user()->id,
            'client_media_id' => $validated['client_media_id'] ?? null,
            'disk' => config('media.disk', 's3'),
            'bucket' => config('filesystems.disks.'.config('media.disk', 's3').'.bucket'),
            'object_key' => $objectKey,
            'media_type' => $validated['media_type'],
            'mime_type' => $validated['mime_type'],
            'original_filename' => $validated['original_filename'],
            'size_bytes' => $validated['size_bytes'],
            'checksum_sha256' => $validated['checksum_sha256'] ?? null,
            'status' => 'pending',
        ]);

        $upload = $this->mediaStorageService->createUploadUrl(
            $mediaAsset,
            (int) config('media.presigned_upload_expiry_seconds', 900),
        );

        AuditLogger::log(
            action: 'media.presigned_upload_created',
            entityType: 'media_assets',
            entityId: $mediaAsset->id,
            metadata: [
                'submission_id' => $submission->id,
                'media_type' => $mediaAsset->media_type,
                'object_key' => $mediaAsset->object_key,
            ],
            request: $request,
        );

        return response()->json([
            'media_asset_id' => $mediaAsset->id,
            'media_uuid' => $mediaAsset->uuid,
            'object_key' => $mediaAsset->object_key,
            'upload' => $upload,
            'idempotent_reuse' => false,
        ], 201);
    }

    public function completeUpload(Request $request, MediaAsset $mediaAsset): JsonResponse
    {
        $validated = $request->validate([
            'size_bytes' => ['nullable', 'integer', 'min:1'],
            'checksum_sha256' => ['nullable', 'regex:/^[A-Fa-f0-9]{64}$/'],
        ]);

        $mediaAsset->load('submission');

        $this->authorize('view', $mediaAsset->submission);

        $mediaAsset->forceFill([
            'status' => 'uploaded',
            'size_bytes' => $validated['size_bytes'] ?? $mediaAsset->size_bytes,
            'checksum_sha256' => $validated['checksum_sha256'] ?? $mediaAsset->checksum_sha256,
            'uploaded_at' => now(),
        ])->save();

        ProcessMediaAssetJob::dispatch($mediaAsset->id)
            ->onQueue(config('media.processing_queue', 'media'));

        AuditLogger::log(
            action: 'media.upload_completed',
            entityType: 'media_assets',
            entityId: $mediaAsset->id,
            metadata: [
                'submission_id' => $mediaAsset->submission_id,
                'object_key' => $mediaAsset->object_key,
            ],
            request: $request,
        );

        return response()->json([
            'message' => 'Media upload completed and processing queued.',
            'media_asset' => $this->serializeMediaAsset($mediaAsset->fresh()),
        ]);
    }

    public function downloadUrl(Request $request, MediaAsset $mediaAsset): JsonResponse
    {
        $mediaAsset->load('submission');

        $this->authorize('view', $mediaAsset->submission);

        if ($request->user()->hasRole('partner_donor_viewer')) {
            return response()->json([
                'message' => 'Raw media is not available in donor view.',
            ], 403);
        }

        $url = $this->mediaStorageService->createDownloadUrl(
            $mediaAsset,
            (int) config('media.presigned_download_expiry_seconds', 600),
        );

        AuditLogger::log(
            action: 'media.download_url_issued',
            entityType: 'media_assets',
            entityId: $mediaAsset->id,
            metadata: [
                'submission_id' => $mediaAsset->submission_id,
                'expires_in' => (int) config('media.presigned_download_expiry_seconds', 600),
            ],
            request: $request,
        );

        return response()->json([
            'media_asset_id' => $mediaAsset->id,
            'url' => $url,
            'expires_in' => (int) config('media.presigned_download_expiry_seconds', 600),
        ]);
    }

    private function withinMediaLimits(Submission $submission, string $mediaType, int $sizeBytes): bool
    {
        $existingCount = MediaAsset::query()
            ->where('submission_id', $submission->id)
            ->where('media_type', $mediaType)
            ->count();

        if ($mediaType === 'image') {
            $maxCount = (int) config('media.images.max_count', 10);
            $maxBytes = (int) config('media.images.max_upload_mb', 15) * 1024 * 1024;

            return $existingCount < $maxCount && $sizeBytes <= $maxBytes;
        }

        $maxCount = (int) config('media.videos.max_count', 1);
        $maxBytes = (int) config('media.videos.max_upload_mb', 300) * 1024 * 1024;

        return $existingCount < $maxCount && $sizeBytes <= $maxBytes;
    }

    private function serializeMediaAsset(MediaAsset $mediaAsset): array
    {
        return [
            'id' => $mediaAsset->id,
            'uuid' => $mediaAsset->uuid,
            'submission_id' => $mediaAsset->submission_id,
            'media_type' => $mediaAsset->media_type,
            'mime_type' => $mediaAsset->mime_type,
            'status' => $mediaAsset->status,
            'object_key' => $mediaAsset->object_key,
            'size_bytes' => $mediaAsset->size_bytes,
            'variants' => $mediaAsset->variants,
            'uploaded_at' => optional($mediaAsset->uploaded_at)->toIso8601String(),
            'processed_at' => optional($mediaAsset->processed_at)->toIso8601String(),
        ];
    }
}
