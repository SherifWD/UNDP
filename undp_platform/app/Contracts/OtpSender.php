<?php

namespace App\Contracts;

use App\Support\OtpSendResult;

interface OtpSender
{
    public function send(string $phoneE164, array $context = []): OtpSendResult;
}
