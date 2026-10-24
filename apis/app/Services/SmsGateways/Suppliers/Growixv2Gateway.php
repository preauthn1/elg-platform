<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

class Growixv2Gateway extends AbstractGrowixV2Gateway
{
    protected function getMessageType(): string
    {
        return 'sms';
    }
}
