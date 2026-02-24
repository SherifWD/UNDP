<?php

namespace App\Services\Otp;

use App\Contracts\OtpSender;

class LogOtpSender implements OtpSender
{
    public function send(string $phoneE164, string $message, array $context = []): void
    {
        logger()->info('OTP sent via log provider', [
            'provider' => 'log',
            'phone' => $phoneE164,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
