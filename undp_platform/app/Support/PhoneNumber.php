<?php

namespace App\Support;

class PhoneNumber
{
    public static function normalize(string $countryCode, string $phone): array
    {
        $country = preg_replace('/[^0-9+]/', '', $countryCode) ?: '+218';

        if (! str_starts_with($country, '+')) {
            $country = '+'.$country;
        }

        $cleanPhone = preg_replace('/[^0-9]/', '', $phone) ?: '';

        return [
            'country_code' => $country,
            'phone' => $cleanPhone,
            'phone_e164' => $country.$cleanPhone,
        ];
    }

    public static function mask(string $countryCode, string $phone): string
    {
        $maskedDigits = max(0, strlen($phone) - 2);

        return sprintf('%s %s%s', $countryCode, str_repeat('*', $maskedDigits), substr($phone, -2));
    }
}
