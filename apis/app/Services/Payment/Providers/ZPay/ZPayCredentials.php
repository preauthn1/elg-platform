<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\ZPay;

final readonly class ZPayCredentials
{
    public function __construct(
        public string $token,
        public string $secretKey
    ) {}
}
