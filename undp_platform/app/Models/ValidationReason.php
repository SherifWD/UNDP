<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ValidationReason extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'action',
        'label_en',
        'label_ar',
        'is_active',
        'sort_order',
    ];

    protected $appends = [
        'label',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getLabelAttribute(): string
    {
        return app()->getLocale() === 'ar' && $this->label_ar ? $this->label_ar : $this->label_en;
    }
}
