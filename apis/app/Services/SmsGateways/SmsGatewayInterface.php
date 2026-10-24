<?php

declare(strict_types=1);

namespace App\Services\SmsGateways;

use App\Data\SmsResultData;
use App\Data\SmsSendData;

interface SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData;
}
