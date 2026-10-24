<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SmsGateway;

/**
 * php artisan db:seed --class=SyncSmsGatewaySeeder
 */
class SyncSmsGatewaySeeder extends AbstractSyncSeeder
{
    protected string $dataFile = 'sms_gateways.php';

    protected string $modelClass = SmsGateway::class;

    protected string $uniqueKey = 'code';

    protected bool $isTest = false;

    protected array $default = [
        'status' => 1,
        'admin' => 0,
    ];

    protected function isValid(array $data, $key): bool
    {
        return !empty($data) && isset($data['code']) && $data['code'];
    }

    protected function getLogMessage(array $data, $key): string
    {
        return "新增簡訊商 {$data['name']} ({$data['code']})";
    }
}
