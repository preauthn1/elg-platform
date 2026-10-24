<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Currency;
use Database\Seeders\Concerns\GuardsCurrencyMasterData;

/**
 * php artisan db:seed --class=SyncCurrenciesSeeder
 */
class SyncCurrenciesSeeder extends AbstractSyncSeeder
{
    use GuardsCurrencyMasterData;

    protected string $dataFile = 'currencies.php';

    protected string $modelClass = Currency::class;

    protected string $langRelation = 'names';

    protected string $langRelationKey = 'currency_id';

    protected string $uniqueKey = 'code';

    protected bool $isTest = false;

    protected array $default = [
        'code' => '',
        'name' => '',
        'rate' => '',
        'status' => 1,
        'admin' => 0,
    ];

    /**
     * 在 AbstractSyncSeeder::run() 進入交易與 try 之前先驗主檔一致性。
     *
     * 資料檔的 id 是明寫的，只補缺 code 的批次 insert 遇到 id 已被佔用會整批回滾，
     * 而 run() 的 catch 不重拋、只回傳計數——放在 try 裡面的檢查會被它吞掉，
     * app:upgrade 照樣以退出碼 0 收場。buildToInsert() 在 try 之前被呼叫，這裡拋出才會真的讓執行失敗。
     */
    protected function buildToInsert(array $source, array $existing, int &$updated): array
    {
        $this->assertCurrencyMasterDataConsistent($source);

        return parent::buildToInsert($source, $existing, $updated);
    }

    protected function isValid(array $data, $key): bool
    {
        return !empty($data['code']) && !empty($data['name']) && !empty($data['rate']);
    }

    protected function getLogMessage(array $data, $key): string
    {
        return "新增幣別 {$data['name']} ({$data['code']})";
    }
}
