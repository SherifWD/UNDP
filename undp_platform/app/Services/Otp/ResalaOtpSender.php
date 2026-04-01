<?php

namespace App\Services\Otp;

use App\Contracts\OtpSender;
use App\Support\OtpSendResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ResalaOtpSender implements OtpSender
{
    public function send(string $phoneE164, array $context = []): OtpSendResult
    {
        $token = trim((string) config('services.resala.token'));
        $baseUrl = rtrim((string) config('services.resala.base_url'), '/');
        $serviceName = trim((string) ($context['service_name'] ?? config('services.resala.service_name')));
        $autofillSignature = trim((string) ($context['autofill_signature'] ?? config('services.resala.autofill_signature')));
        $testMode = (bool) ($context['test_mode'] ?? config('services.resala.test_mode', false));
        $timeoutSeconds = max(1, (int) config('services.resala.timeout_seconds', 10));
        $digits = max(4, min((int) ($context['digits'] ?? config('otp.code_digits', 6)), 6));
        $locale = $this->normalizeLocale((string) ($context['locale'] ?? app()->getLocale()));
        $region = trim((string) ($context['region'] ?? config('services.resala.region', '')));

        if ($token === '' || $baseUrl === '' || $serviceName === '') {
            throw new RuntimeException('Resala OTP configuration is incomplete.');
        }

        $query = array_filter([
            'service_name' => $serviceName,
            'len' => (string) $digits,
            'autofill' => $autofillSignature !== '' ? $autofillSignature : null,
            'lang' => $locale,
            'test' => $testMode ? 'test' : null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $payload = array_filter([
            'phone' => ltrim($phoneE164, '+'),
            'region' => $region !== '' ? $region : null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $response = Http::acceptJson()
            ->asJson()
            ->withToken($token)
            ->withHeaders([
                'Accept-Language' => $locale,
            ])
            ->timeout($timeoutSeconds)
            ->post($baseUrl.'/pins?'.http_build_query($query), $payload);

        if ($response->status() !== 201) {
            throw new RuntimeException('Failed to send OTP via Resala: '.$response->body());
        }

        $body = $response->json();
        $pin = trim((string) data_get($body, 'pin'));

        if ($pin === '') {
            throw new RuntimeException('Resala OTP response did not include a pin.');
        }

        return new OtpSendResult(
            code: $pin,
            provider: 'resala',
            providerReference: data_get($body, 'id'),
            message: data_get($body, 'content'),
            payload: is_array($body) ? $body : [],
        );
    }

    private function normalizeLocale(string $locale): string
    {
        return in_array($locale, ['ar', 'en'], true) ? $locale : 'en';
    }
}
