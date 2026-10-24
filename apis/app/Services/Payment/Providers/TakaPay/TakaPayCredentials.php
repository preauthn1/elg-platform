<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TakaPay;

final class TakaPayCredentials
{
    public function __construct(
        public readonly string $merchantCode,
        public readonly string $secretKey,
    ) {}
}
