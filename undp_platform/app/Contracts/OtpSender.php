<?php

namespace App\Contracts;

interface OtpSender
{
    public function send(string $phoneE164, string $message, array $context = []): void;
}
