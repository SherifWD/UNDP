<?php

namespace App\Services;

use App\Models\MediaAsset;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;

class MediaStorageService
{
    public function createUploadUrl(MediaAsset $mediaAsset, int $expiresInSeconds = 900): array
    {
        $disk = $mediaAsset->disk ?: config('media.disk', 'public');
        $diskConfig = config("filesystems.disks.{$disk}");

        if (($diskConfig['driver'] ?? null) !== 's3') {
            return [
                'url' => $this->publicUrl($mediaAsset),
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
        $disk = $mediaAsset->disk ?: config('media.disk', 'public');
        $diskConfig = config("filesystems.disks.{$disk}");

        if (($diskConfig['driver'] ?? null) !== 's3') {
            return $this->publicUrl($mediaAsset);
        }

        try {
            return Storage::disk($disk)
                ->temporaryUrl($mediaAsset->object_key, now()->addSeconds($expiresInSeconds));
        } catch (\Throwable) {
            return $this->publicUrl($mediaAsset);
        }
    }

    public function publicUrl(MediaAsset $mediaAsset): string
    {
        $disk = $mediaAsset->disk ?: config('media.disk', 'public');
        $url = Storage::disk($disk)->url($mediaAsset->object_key);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return url($url);
    }
}
