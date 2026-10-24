<?php

declare(strict_types=1);

namespace App\Services\SmsGateways;

abstract class AbstractGateway
{
    /**
     * 將內部國家 ID 轉換為供應商的國家 ID
     */
    protected function mapCountryId(int $internalCountryId, array $vendorCountryMappings): string
    {
        foreach ($vendorCountryMappings as $mapping) {
            if ($mapping['COUNTRY_ELG'] === $internalCountryId) {
                return (string) $mapping['COUNTRY_Third'];
            }
        }

        return '';
    }
}
