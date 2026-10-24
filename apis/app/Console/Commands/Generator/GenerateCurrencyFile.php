<?php

declare(strict_types=1);

namespace App\Console\Commands\Generator;

/**
 * php artisan generator:currencies
 *
 * 根據 countries 資料表產生 database/data/currencies.php 檔案
 */
class GenerateCurrencyFile extends AbstractDataFileGenerator
{
    protected string $tableName = 'currencies';

    protected array $tableColumns = [
        'id',
        'code',
        'name',
        'rate',
    ];
}
