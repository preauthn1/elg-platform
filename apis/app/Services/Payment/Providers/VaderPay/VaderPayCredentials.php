<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\VaderPay;

final readonly class VaderPayCredentials
{
    public function __construct(
        public string $merchantCode,
        public string $apiKey,
    ) {}
}
