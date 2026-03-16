<?php

return [
    'disk' => env('MEDIA_DISK', 'public'),
    'direct_upload_disk' => env('MEDIA_DIRECT_UPLOAD_DISK', env('MEDIA_DISK', 'public')),

    'presigned_upload_expiry_seconds' => env('MEDIA_PRESIGNED_UPLOAD_EXPIRY_SECONDS', 900),
    'presigned_download_expiry_seconds' => env('MEDIA_PRESIGNED_DOWNLOAD_EXPIRY_SECONDS', 600),

    'images' => [
        'max_count' => 10,
        'max_upload_mb' => 15,
        'max_after_compression_mb' => 4,
        'target_min_mb' => 0.8,
        'target_max_mb' => 2.0,
        'max_long_side_px' => 2048,
    ],

    'videos' => [
        'max_count' => 1,
        'max_upload_mb' => 300,
        'target_max_mb' => 200,
    ],

    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'video/mp4',
        'video/quicktime',
    ],

    'processing_queue' => env('MEDIA_PROCESSING_QUEUE', 'media'),
];
