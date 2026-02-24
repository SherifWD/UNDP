<?php

namespace App\Enums;

enum UserStatus: string
{
    case ACTIVE = 'active';
    case DISABLED = 'disabled';

    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
