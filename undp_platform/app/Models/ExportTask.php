<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'format',
        'type',
        'status',
        'progress',
        'filters',
        'file_disk',
        'file_path',
        'file_name',
        'mime_type',
        'size_bytes',
        'error_message',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'size_bytes' => 'integer',
            'filters' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
