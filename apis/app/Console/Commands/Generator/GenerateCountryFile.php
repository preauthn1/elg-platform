<?php

declare(strict_types=1);

namespace App\Console\Commands\Generator;

/**
 * php artisan generator:countries
 *
 * 根據 countries 資料表產生 database/data/countries.php 檔案
 */
class GenerateCountryFile extends AbstractDataFileGenerator
{
    protected string $tableName = 'countries';

    protected array $tableColumns = [
        'code',
        'name',
        'country_code',
        'phone_rule',
    ];
}
