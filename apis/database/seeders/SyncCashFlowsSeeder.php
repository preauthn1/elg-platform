<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CashFlow;

/**
 * php artisan db:seed --class=SyncCashFlowsSeeder
 */
class SyncCashFlowsSeeder extends AbstractSyncSeeder
{
    protected string $dataFile = 'app/Console/Commands/CashFlow/Data/cash_flows.php';

    protected string $modelClass = CashFlow::class;

    protected string $uniqueKey = 'id';

    protected bool $isTest = false;

    protected array $default = [
        'id' => '',
        'third_party_code' => '',
        'name' => '',
        'rate' => 1.0,
        'percentage' => 0.0,
        'type' => 0,
        'status' => 1,
        'remark' => '', // 備註
        'info' => '',
        'admin' => 0, // 預設管理者
    ];

    protected function isValid(array $data, $key): bool
    {
        return !empty($data['id'])
            && !empty($data['third_party_code'])
            && !empty($data['name'])
            && !empty($data['type']);
    }

    protected function formatInsertData(array $data, $key): array
    {
        // 移除不在 $default 中的欄位
        foreach ($data as $index => $value) {
            if (!array_key_exists($index, $this->default)) {
                unset($data[$index]);
            }
        }
        $info = $data['info'] ?? '';
        $data['info'] = is_string($info) ? $info : json_encode($info, JSON_UNESCAPED_UNICODE);

        return array_merge($this->default, $data);
    }

    protected function getLogMessage(array $data, $key): string
    {
        return sprintf('新增 CashFlow %s %s ', $data['id'], $data['name']);
    }

    /**
     * 載入資料來源
     *
     * @return array 資料陣列
     */
    protected function loadSource(): array
    {
        return require $this->dataFile;
    }
}
