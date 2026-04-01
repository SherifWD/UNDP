<?php

namespace App\Support;

class OtpSendResult
{
    public function __construct(
        public readonly string $code,
        public readonly string $provider,
        public readonly ?string $providerReference = null,
        public readonly ?string $message = null,
        public readonly array $payload = [],
    ) {
    }
}
