<?php

namespace App\Enums;

enum UserRole: string
{
    case REPORTER = 'reporter';
    case MUNICIPAL_FOCAL_POINT = 'municipal_focal_point';
    case UNDP_ADMIN = 'undp_admin';
    case PARTNER_DONOR_VIEWER = 'partner_donor_viewer';
    case AUDITOR = 'auditor';

    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::REPORTER => 'Reporter',
            self::MUNICIPAL_FOCAL_POINT => 'Municipal Focal Point',
            self::UNDP_ADMIN => 'UNDP Admin',
            self::PARTNER_DONOR_VIEWER => 'Partner/Donor Viewer',
            self::AUDITOR => 'Auditor',
        };
    }
}
