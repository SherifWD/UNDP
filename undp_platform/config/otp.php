<?php

return [
    'expires_in_seconds' => env('OTP_EXPIRES_IN_SECONDS', 300),
    'resend_cooldown_seconds' => env('OTP_RESEND_COOLDOWN_SECONDS', 60),
    'max_attempts' => env('OTP_MAX_ATTEMPTS', 5),
    'code_digits' => env('OTP_CODE_DIGITS', 6),
];
