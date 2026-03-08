<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'submission_id',
        'uploaded_by',
        'client_media_id',
        'label',
        'display_order',
        'disk',
        'bucket',
        'object_key',
        'media_type',
        'mime_type',
        'original_filename',
        'size_bytes',
        'checksum_sha256',
        'status',
        'variants',
        'metadata',
        'uploaded_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'metadata' => 'array',
            'display_order' => 'integer',
            'uploaded_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
