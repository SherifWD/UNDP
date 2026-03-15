<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'municipality_id',
        'name_en',
        'name_ar',
        'description',
        'status',
        'latitude',
        'longitude',
        'last_update_at',
        'mobile_meta',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'last_update_at' => 'datetime',
            'mobile_meta' => 'array',
        ];
    }

    protected $appends = [
        'name',
    ];

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function fundingRequests(): HasMany
    {
        return $this->hasMany(FundingRequest::class);
    }

    public function assignedReporters(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_reporter_assignments', 'project_id', 'reporter_id')
            ->withPivot('assigned_by')
            ->withTimestamps();
    }

    public function getNameAttribute(): string
    {
        return app()->getLocale() === 'ar' && $this->name_ar ? $this->name_ar : $this->name_en;
    }
}
