<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StandardBankTypeEnum;
use App\Helpers\Common;
use App\Models\Currency;
use App\Models\StandardBank;
use App\Services\CashFlow\Bank\StandardBankRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 從各幣別標準銀行分檔（app/Console/Commands/CashFlow/Data/banks/{幣別}.php）冪等
 * upsert standard_banks（rich schema）。分檔同時是名稱正規化（BankMismatchAuditor）
 * 與銀行身分的單一來源；currency 由檔名決定。
 *
 * 隨 app:upgrade 於各站部署時執行，使六站 standard_banks 內容與代碼版本同步（config 為權威）。
 * upsert 鍵為 (code, currency_id)，code 原樣沿用 config 的 `{幣別}_{名稱}` 前綴格式（DB 既有列
 * 由 2026_08_28_000001_prefix_standard_banks_code_with_currency migration 補齊前綴，兩者必須
 * 同版部署）；覆寫 currency_code / name / aliases / type（config 權威——type 六站實查
 * 全為預設值、無人工策展，覆寫安全），swift 僅於 config 提供非空值時寫入（curation 前
 * 不清空既有值）。status 只對標了 `retired_into`（已併入別家）的條目寫入停用，其餘不寫
 * （不覆蓋各站手動停用）。別名的併入與移除規則見 StandardBankRegistry::dbEntries()。
 *
 * php artisan db:seed --class=SyncStandardBanksSeeder
 */
class SyncStandardBanksSeeder extends Seeder
{
    /**
     * app:upgrade / db:seed 入口：錯誤不外拋（記 log 後回 0），對齊其他 Sync seeder 行為。
     *
     * @return int 實際變更（新增＋更新）筆數
     */
    public function run(): int
    {
        try {
            return $this->sync();
        } catch (Throwable $e) {
            Log::error('SyncStandardBanksSeeder failed', [
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);
            $this->command?->getOutput()?->writeln(static::class . ' 發生錯誤，已回滾。錯誤訊息: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * 執行同步（拋出版本），交由呼叫端決定失敗語意。
     *
     * @return int 實際變更（新增＋更新）筆數
     */
    public function sync(): int
    {
        ['rows' => $rows, 'skipped' => $skipped] = $this->buildRows();

        if ($skipped !== []) {
            Log::info('SyncStandardBanksSeeder skipped', ['skipped' => $skipped]);
        }

        $deactivated = [];

        $changed = DB::transaction(function () use ($rows, &$deactivated): int {
            $changed = 0;
            foreach ($rows as $row) {
                $values = [
                    'currency_code' => $row['currency_code'],
                    'name' => $row['name'],
                    'aliases' => $row['aliases'],
                    'type' => $row['type'],
                ];
                if ($row['swift'] !== null) {
                    $values['swift'] = $row['swift'];
                }
                if ($row['retired']) {
                    $values['status'] = 0;
                }

                $bank = StandardBank::query()->updateOrCreate(
                    ['code' => $row['code'], 'currency_id' => $row['currency_id']],
                    $values,
                );

                if ($bank->wasRecentlyCreated || $bank->wasChanged()) {
                    $changed++;
                }
                if (!$bank->wasRecentlyCreated && $bank->wasChanged('status')) {
                    $deactivated[] = $bank->id . ':' . $row['code'];
                }
            }

            return $changed;
        });

        // 舊版 seeder 不寫 status，回退發版不會把這些列改回啟用；記下 id 與 code 作為人工復原的依據
        if ($deactivated !== []) {
            Log::info('SyncStandardBanksSeeder deactivated retired entries', ['standard_banks' => $deactivated]);
        }

        return $changed;
    }

    /**
     * 讀 config 建構待 upsert 列（供 sync 與命令 dry-run 預覽共用）。
     *
     * @return array{rows: list<array{code: string, currency_id: int, currency_code: string, name: string, aliases: array, swift: string|null, type: int, retired: bool}>, skipped: list<string>}
     */
    public function buildRows(): array
    {
        $entries = StandardBankRegistry::dbEntries($this->loadEntries());
        $currencyMap = Currency::query()->pluck('id', 'code'); // code => id

        $rows = [];
        $skipped = [];
        foreach ($entries as $entry) {
            $currencyCode = $entry['currency'];
            $currencyId = $currencyMap[$currencyCode] ?? null;
            if ($currencyId === null) {
                $skipped[] = "{$entry['code']}（無 currency {$currencyCode}）";

                continue;
            }

            $swift = trim((string) ($entry['swift'] ?? ''));
            $rows[] = [
                'code' => $entry['code'],
                'currency_id' => (int) $currencyId,
                'currency_code' => $currencyCode,
                'name' => $entry['name'],
                'aliases' => $entry['aliases'] ?? [],
                'swift' => $swift === '' ? null : $swift,
                'type' => (int) ($entry['type'] ?? StandardBankTypeEnum::BANK->value),
                'retired' => $entry['retired'],
            ];
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * 載入五幣別分檔條目，currency 由檔名注入（MYR.php ⇒ 'MYR'）；抽 protected 供測試
     * 以 fixture 覆寫。只認三碼大寫檔名，README 等其他檔案不會被誤讀。
     */
    protected function loadEntries(): array
    {
        $entries = [];

        foreach (glob(app_path('Console/Commands/CashFlow/Data/banks/*.php')) ?: [] as $path) {
            $currency = basename($path, '.php');

            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                continue;
            }

            foreach (require $path as $entry) {
                $entries[] = [...$entry, 'currency' => $currency];
            }
        }

        return $entries;
    }
}
