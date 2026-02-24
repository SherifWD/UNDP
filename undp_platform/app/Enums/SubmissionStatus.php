<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    case DRAFT = 'draft';
    case QUEUED = 'queued';
    case SUBMITTED = 'submitted';
    case UNDER_REVIEW = 'under_review';
    case APPROVED = 'approved';
    case REWORK_REQUESTED = 'rework_requested';
    case REJECTED = 'rejected';

    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::QUEUED => 'Queued',
            self::SUBMITTED => 'Submitted',
            self::UNDER_REVIEW => 'Under Review',
            self::APPROVED => 'Approved',
            self::REWORK_REQUESTED => 'Rework Requested',
            self::REJECTED => 'Rejected',
        };
    }
}
