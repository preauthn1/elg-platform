<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\SmsGatewayInfo;
use Spatie\LaravelData\Data;

class SmsGatewayInfoData extends Data
{
    public function __construct(
        public SmsGatewayInfo $smsGatewayInfo,
        public int $countryId,
        public int $countryCode, // 三方的交易ID
    ) {}
}
