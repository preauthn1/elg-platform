<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Agent;
use App\Models\Member;
use App\Models\SmsGatewayInfo;
use Spatie\LaravelData\Data;

class SmsSendData extends Data
{
    public function __construct(
        public Member|Agent $member,
        public SmsGatewayInfo $smsGatewayInfo,
        public string $mobile,
        public string $content,
    ) {}
}
