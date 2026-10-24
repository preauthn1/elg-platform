<?php

declare(strict_types=1);

namespace App\Console\Commands\Generator;

/**
 * php artisan generator:cash_flows
 *
 * 根據 countries 資料表產生 database/data/countries.php 檔案
 */
class GenerateCashFlowFile extends AbstractDataFileGenerator
{
    protected string $tableName = 'cash_flows';

    protected array $tableColumns = [
        'id',
        'third_party_code',
        'name',
        'rate',
        'percentage',
        'type', // 支付類別; 1.線上支付, 2線下支付, 3.代付, 4.自助洗分
        'remark',
    ];
}
