<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Services\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMediaAssetJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $mediaAssetId)
    {
    }

    public function handle(): void
    {
        $mediaAsset = MediaAsset::query()->find($this->mediaAssetId);

        if (! $mediaAsset) {
            return;
        }

        $mediaAsset->forceFill([
            'status' => 'processing',
        ])->save();

        $baseFolder = sprintf(
            '%s/processed/%s',
            dirname($mediaAsset->object_key),
            pathinfo($mediaAsset->object_key, PATHINFO_FILENAME),
        );

        if ($mediaAsset->media_type === 'image') {
            $variants = [
                'thumb' => $baseFolder.'/thumb.jpg',
                'medium' => $baseFolder.'/medium.jpg',
                'large' => $baseFolder.'/large.jpg',
            ];
        } else {
            $variants = [
                'processed' => $baseFolder.'/processed.mp4',
                'poster' => $baseFolder.'/poster.jpg',
            ];
        }

        // Placeholder processing pipeline: enqueue/transcoding workers should replace this block.
        $mediaAsset->forceFill([
            'status' => 'ready',
            'variants' => $variants,
            'processed_at' => now(),
        ])->save();

        AuditLogger::log(
            action: 'media.processing_ready',
            entityType: 'media_assets',
            entityId: $mediaAsset->id,
            metadata: [
                'submission_id' => $mediaAsset->submission_id,
                'media_type' => $mediaAsset->media_type,
                'variants' => $variants,
            ],
        );
    }
}
