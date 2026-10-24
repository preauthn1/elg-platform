<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Country;

/**
 * php artisan db:seed --class=SyncCountriesSeeder
 */
class SyncCountriesSeeder extends AbstractSyncSeeder
{
    protected string $dataFile = 'countries.php';

    protected string $modelClass = Country::class;

    protected string $langRelation = 'countryNames';

    protected string $langRelationKey = 'country_id';

    protected string $uniqueKey = 'code';

    protected bool $isTest = false;

    protected array $default = [
        'code' => '',
        'name' => '',
        'country_code' => '',
        'phone_rule' => '',
        'status' => 1,
        'sort' => 0,
        'admin' => 0,
    ];

    protected function isValid(array $data, $key): bool
    {
        return !empty($data['code']) && !empty($data['name']) && !empty($data['phone_rule']);
    }

    protected function getLogMessage(array $data, $key): string
    {
        return "新增國家 {$data['name']} ({$data['code']})";
    }
}
