<?php

namespace App\Services\Otp;

use App\Contracts\OtpSender;
use App\Support\OtpSendResult;

class LogOtpSender implements OtpSender
{
    public function send(string $phoneE164, array $context = []): OtpSendResult
    {
        $digits = max(4, min((int) ($context['digits'] ?? config('otp.code_digits', 6)), 8));
        $max = (10 ** min($digits, 8)) - 1;
        $code = str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);
        $message = sprintf('Your UNDP verification code is %s', $code);

        logger()->info('OTP sent via log provider', [
            'provider' => 'log',
            'phone' => $phoneE164,
            'message' => $message,
            'context' => $context,
        ]);

        return new OtpSendResult(
            code: $code,
            provider: 'log',
            message: $message,
            payload: [
                'phone' => $phoneE164,
                'message' => $message,
            ],
        );
    }
}
