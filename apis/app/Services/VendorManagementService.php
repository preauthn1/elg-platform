<?php

declare(strict_types=1);

namespace App\Services;

use App\CacheKeys\CacheKeys;
use App\Enums\AdminOperationEnum;
use App\Enums\StatusEnum;
use App\Helpers\Common;
use App\Models\Vendor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Olympus「遊戲商管理」的寫入服務：修改、停用、啟用 `vendors`。
 *
 * 硬規則：
 * - 寫入與 {@see AdminOperationService::audit()} 必須在同一個交易內，稽核寫不進去整筆回滾。
 * - 讀取一律 `lockForUpdate()` 重取，比對改前改後以鎖定後的值為準，兩人同時編輯不會記錯改前值。
 * - 禁止刪除：歷史注單以 vendor_id 關聯，刪了對不到遊戲商，一律以停用（status=0）取代。
 * - `admin` 欄位不寫，操作者以操作紀錄為準。
 * - 不可修改的欄位（{@see self::NON_EDITABLE_COLUMNS}、{@see self::OUT_OF_SCOPE_COLUMNS}）一律丟棄：編輯頁不顯示它們，
 *   但請求內容可被竄改，服務層不能只靠表單沒放這些欄位。
 * - 快取 `Vendor:{id}`（{@see \App\Vendors\BaseAbstractVendor} 快取 1 小時）：交易內由
 *   VendorObserver::updated() 清一次；commit 後本服務再清一次，堵住「清掉到 commit 之間
 *   有請求把舊值寫回快取」的空檔。
 */
readonly class VendorManagementService
{
    /** decimal 欄位與其小數位數：表單送 1、DB 讀出 "1.00000"，Eloquent 字串比對會誤判有變動，須以數值比 */
    private const array DECIMAL_COLUMNS = ['valid_bet_rate' => 2];

    /**
     * 後台不可修改的欄位：
     * - code、tname：程式拿來組命令前綴、資料表名稱與快取鍵，改了會查不到既有資料。
     * - is_single_wallet：錢包流程依它分岔，切錯會讓轉帳錢包與單一錢包的流程對調。
     * - info：多數是商戶憑證，改錯直接影響對接；維護時段改用 tool:update-vendor-maintenance-window。
     * - rate、percentage：分潤、盤費、點數換算都不讀遊戲商層的這兩個值（換算用 vendor_infos.rate，
     *   拆帳用代理的 percentage），後台不顯示也不開放修改，避免被誤以為會影響計算。
     */
    private const array NON_EDITABLE_COLUMNS = ['code', 'tname', 'is_single_wallet', 'info', 'rate', 'percentage'];

    /**
     * 不屬於維運權責、暫不開放修改的欄位（編輯頁的「顯示開關」「結算與比例」兩段已註解）。
     * 要恢復時先補上依角色區分的修改權限，再從這裡移除並取消表單註解。
     */
    private const array OUT_OF_SCOPE_COLUMNS = [
        'is_new', 'is_hot', 'is_event', 'show_on_mobile', 'show_on_pc', 'show_on_app',
        'show_on_profit_share', 'open_window', 'need_login', 'only_one_game',
        'valid_bet_rate', 'summary_daily', 'calc_time_zone', 'calc_on_profit_share',
        'calc_flow_limit', 'event_lock', 'is_free', 'unbind',
    ];

    public function __construct(private AdminOperationService $adminOperationService) {}

    /**
     * 修改遊戲商資訊；沒有任何欄位變動時不寫入、不留紀錄。
     * 不改 status：狀態一律走 {@see setStatus()}，傳進來的 status 直接丟棄。
     *
     * @param  array<string, mixed>  $data  表單資料（欄位名 => 值）
     * @return array<string, array{from: mixed, to: mixed}> 有變動的欄位
     */
    public function update(Vendor $vendor, array $data, int $userId): array
    {
        unset($data['id'], $data['status'], $data['admin'], $data['created_at'], $data['updated_at']);
        $data = array_diff_key($data, array_flip([...self::NON_EDITABLE_COLUMNS, ...self::OUT_OF_SCOPE_COLUMNS]));

        return DB::transaction(function () use ($vendor, $data, $userId): array {
            $locked = Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();

            $locked->fill($this->normalize($locked, $data));
            $changes = $this->describeChanges($locked);

            if ($changes === []) {
                return [];
            }

            $locked->save();
            $this->adminOperationService->audit(
                operation: AdminOperationEnum::UpdateVendor,
                userId: $userId,
                params: ['vendor_id' => $locked->id, 'vendor_name' => $locked->name, 'changes' => $changes],
                output: sprintf('修改遊戲商 %s (ID: %d)：%s', $locked->name, $locked->id, implode('、', array_keys($changes))),
            );
            $this->forgetCacheAfterCommit($locked->id);

            return $changes;
        });
    }

    /**
     * 停用／啟用遊戲商；狀態已是目標值時不寫入、不留紀錄，回傳 false。
     */
    public function setStatus(Vendor $vendor, StatusEnum $status, int $userId): bool
    {
        return DB::transaction(function () use ($vendor, $status, $userId): bool {
            $locked = Vendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            $from = (int) $locked->status;

            if ($from === $status->value) {
                return false;
            }

            $locked->status = $status->value;
            $locked->save();

            $operation = $status === StatusEnum::ENABLE ? AdminOperationEnum::EnableVendor : AdminOperationEnum::DisableVendor;
            $this->adminOperationService->audit(
                operation: $operation,
                userId: $userId,
                params: ['vendor_id' => $locked->id, 'vendor_name' => $locked->name, 'changes' => ['status' => ['from' => $from, 'to' => $status->value]]],
                output: sprintf('%s %s (ID: %d)', $operation->label(), $locked->name, $locked->id),
            );
            $this->forgetCacheAfterCommit($locked->id);

            return true;
        });
    }

    /**
     * 把表單值整理成與 DB 讀出值可比對的形式，避免沒改的欄位被判成有變動：
     * - Toggle 送 bool、DB 讀出是 "0"／"1"，一律轉 int。
     * - 表單留空送 null、原值是空字串時沿用空字串。
     * - decimal 欄位數值相同（1 與 "1.00000"）時沿用原值。
     * - 白名單去掉每一筆的前後空白，留空存 null（留空＝不檢查來源 IP）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(Vendor $vendor, array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $data[$key] = (int) $value;
            } elseif ($value === null && $vendor->getOriginal($key) === '') {
                // 表單把空字串送成 null，原值是空字串時視為沒改
                $data[$key] = '';
            }
        }

        foreach (self::DECIMAL_COLUMNS as $key => $scale) {
            $original = $vendor->getOriginal($key);
            if (isset($data[$key]) && is_numeric($data[$key]) && is_numeric($original)
                && Common::numCompare((string) $data[$key], (string) $original, $scale) === 0) {
                $data[$key] = $original;
            }
        }

        if (array_key_exists('whitelist', $data)) {
            $whitelist = trim((string) $data['whitelist']);
            $data['whitelist'] = $whitelist === ''
                ? null
                : implode(',', array_map(trim(...), explode(',', $whitelist)));
        }

        return $data;
    }

    /**
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function describeChanges(Vendor $vendor): array
    {
        $changes = [];
        foreach (array_keys($vendor->getDirty()) as $key) {
            $from = $vendor->getOriginal($key);
            $to = $vendor->getAttribute($key);

            $changes[$key] = ['from' => $from, 'to' => $to];
        }

        return $changes;
    }

    /**
     * 還原後仍是遮罩字串的憑證一律擋下，不可寫入（會蓋掉真實憑證）；錯誤掛在表單的 info 欄位上。
     *
     * @param  array<array-key, mixed>  $restored
     *
     * @throws ValidationException
     */
    public static function assertNoUnrestoredMaskedSecrets(array $restored): void
    {
        $keys = Common::unrestoredMaskedVendorInfoKeys($restored);
        if ($keys !== []) {
            throw ValidationException::withMessages([
                'example.invalid' => '以下憑證仍是遮罩值、無法對應原值，請整段填入完整的新值：' . implode('、', $keys),
            ]);
        }
    }

    private function forgetCacheAfterCommit(int $vendorId): void
    {
        DB::afterCommit(static function () use ($vendorId): void {
            try {
                Cache::forget(CacheKeys::vendor($vendorId));
            } catch (Throwable $e) {
                // 資料已 commit，清不掉只影響快取到期前讀到舊值，不可讓例外中斷後續 afterCommit callback
                Log::error('VendorManagementService forget cache failed', [
                    'vendor_id' => $vendorId,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);
            }
        });
    }
}
