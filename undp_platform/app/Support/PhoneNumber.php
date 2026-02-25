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

        $countryDigits = ltrim($country, '+');
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone) ?: '';

        // Remove leading international prefix if the number is entered as 00XXXXXXXX.
        if (str_starts_with($cleanPhone, '00')) {
            $cleanPhone = substr($cleanPhone, 2);
        }

        // If the user includes country digits in the phone field while also selecting country code,
        // strip duplicated country prefix once so we can match existing stored users.
        if ($countryDigits !== '' && str_starts_with($cleanPhone, $countryDigits)) {
            $cleanPhone = substr($cleanPhone, strlen($countryDigits));
        }

        // Libya local numbers are often entered with a trunk 0 (e.g., 0910000001); normalize to 910000001.
        if ($countryDigits === '218') {
            $cleanPhone = ltrim($cleanPhone, '0');
        }

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
