<?php

namespace App\Services;

use App\Models\MediaAsset;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;

class MediaStorageService
{
    public function createUploadUrl(MediaAsset $mediaAsset, int $expiresInSeconds = 900): array
    {
        $disk = $mediaAsset->disk ?: config('media.disk', 's3');
        $diskConfig = config("filesystems.disks.{$disk}");

        if (($diskConfig['driver'] ?? null) !== 's3') {
            return [
                'url' => Storage::disk($disk)->url($mediaAsset->object_key),
                'headers' => [
                    'Content-Type' => $mediaAsset->mime_type,
                ],
                'expires_in' => $expiresInSeconds,
            ];
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => $diskConfig['region'],
            'endpoint' => $diskConfig['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($diskConfig['use_path_style_endpoint'] ?? false),
            'credentials' => [
                'key' => $diskConfig['key'],
                'secret' => $diskConfig['secret'],
            ],
        ]);

        $command = $client->getCommand('PutObject', [
            'Bucket' => $diskConfig['bucket'],
            'Key' => $mediaAsset->object_key,
            'ContentType' => $mediaAsset->mime_type,
        ]);

        $request = $client->createPresignedRequest($command, '+'.$expiresInSeconds.' seconds');

        return [
            'url' => (string) $request->getUri(),
            'headers' => [
                'Content-Type' => $mediaAsset->mime_type,
            ],
            'expires_in' => $expiresInSeconds,
        ];
    }

    public function createDownloadUrl(MediaAsset $mediaAsset, int $expiresInSeconds = 600): string
    {
        try {
            return Storage::disk($mediaAsset->disk)
                ->temporaryUrl($mediaAsset->object_key, now()->addSeconds($expiresInSeconds));
        } catch (\Throwable) {
            return Storage::disk($mediaAsset->disk)->url($mediaAsset->object_key);
        }
    }
}
