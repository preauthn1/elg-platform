<?php

declare(strict_types=1);

namespace App\Console\Commands\Generator;

/**
 * php artisan generator:sms_gateways
 *
 * 根據 sms_gateways 資料表產生 database/data/sms_gateways.php 檔案
 */
class GenerateSmsGatewayFile extends AbstractDataFileGenerator
{
    protected string $tableName = 'sms_gateways';

    protected array $tableColumns = [
        'name',
        'code',
        'info',
        'url',
        'memo',
    ];
}
