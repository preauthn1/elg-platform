<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

/**
 * Growix v2 WhatsApp OTP
 */
class Growixv2waGateway extends AbstractGrowixV2Gateway
{
    protected function getMessageType(): string
    {
        return 'whatsapp';
    }
}
