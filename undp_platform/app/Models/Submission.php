<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Submission extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_uuid',
        'reporter_id',
        'project_id',
        'municipality_id',
        'status',
        'title',
        'details',
        'data',
        'media',
        'latitude',
        'longitude',
        'submitted_at',
        'validated_at',
        'validated_by',
        'validation_comment',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'media' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'submitted_at' => 'datetime',
            'validated_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(SubmissionStatusEvent::class)->latest('created_at');
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class)->latest('created_at');
    }

    public function isApproved(): bool
    {
        return $this->status === SubmissionStatus::APPROVED->value;
    }
}
