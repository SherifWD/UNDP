<?php

namespace App\Services\Otp;

use App\Contracts\OtpSender;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ISendlyOtpSender implements OtpSender
{
    public function send(string $phoneE164, string $message, array $context = []): void
    {
        $apiKey = (string) config('services.isendly.api_key');
        $baseUrl = rtrim((string) config('services.isendly.base_url'), '/');
        $senderId = (string) config('services.isendly.sender_id');

        if ($apiKey === '' || $baseUrl === '') {
            throw new RuntimeException('iSendly configuration is incomplete.');
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Accept' => 'application/json',
        ])->post($baseUrl.'/sms/send', [
            'to' => $phoneE164,
            'message' => $message,
            'sender_id' => $senderId,
            'context' => $context,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to send OTP via iSendly: '.$response->body());
        }
    }
}
