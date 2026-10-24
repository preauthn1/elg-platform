<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Maintenance;

use App\Console\Commands\CashFlow\Concerns\ResolvesMainCurrency;
use App\Enums\WithdrawalTypeEnum;
use App\Helpers\Common;
use App\Models\Bank;
use App\Models\Currency;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * 依當前 DB_CONNECTION 對應的環境主幣，批次正規化既有 banks 表的 type 與 rate。
 *
 * 【分類】Maintenance — 可複用維運工具，開站／改主幣時跑。
 *
 * ## 發生原因
 * `SyncCashFlowsCommand` 透過 `normalizeForEnvironment` 翻轉**新進** channel.type 與
 * `cash_flows.info[CUR].rate`，但 `buildBankUpdateData` **不更新既有 banks row 的 rate／type**
 * （既有設計，避免 sync 覆寫營運手動調整）。結果是：規則只對新資料生效，**歷史列不會自動對齊**。
 *
 * ## 修正目的
 * 依 `site_environments.{conn}.main_currency` 把既有 banks 列翻回應有狀態：
 *   1. currency === main_currency 且 type === FOREIGN_BANK → type=BANK         （外國→本國）
 *   2. currency === main_currency 且 rate !== 1            → rate=1             （本國匯率歸 1）
 *   3. currency !== main_currency 且 type === BANK         → type=FOREIGN_BANK（跨幣別校正）
 *
 * ## 觸發時機
 * 1. **新白標開站**：banks 由既有站台複製而來、主幣不同時（最主要用途）
 * 2. 既有站台變更 `site_environments` 主幣設定後
 * 3. 發現前端銀行清單本國／外國分類錯亂時
 *
 * ## 冪等性與重跑安全性
 * **完全冪等，重跑安全。** 只翻轉不符規則的列，已正確者不動；規則本身是「對齊到目標狀態」
 * 而非「切換」，重跑結果相同。
 *
 * 保護層：`--dry-run` 預覽、`confirm()` 二次確認、`confirmExecutionEnvironment()` 環境確認
 * （跑錯白標 DB 會被擋，見 `tests/Unit/Console/Commands/ConfirmExecutionEnvironmentTest.php`）；
 * `--currency` 可限縮單一幣別逐步推進。
 *
 * ## 退役條件
 * 當 `SyncCashFlowsCommand::buildBankUpdateData` 改為**也更新既有 banks 的 type／rate**
 * （亦即 sync 本身就會維持對齊）後，本命令即可刪除。
 *
 * ## 關聯
 * - 缺口來源：`app/Console/Commands/CashFlow/SyncCashFlowsCommand.php`（`normalizeForEnvironment`／`buildBankUpdateData`）
 * - 環境主幣設定：`app/Console/Commands/CashFlow/Data/site_environments.php`
 *
 * 用法：
 *   php artisan fix:normalize-banks-by-environment-main-currency --dry-run
 *   php artisan fix:normalize-banks-by-environment-main-currency --currency=MYR
 *   php artisan fix:normalize-banks-by-environment-main-currency --force
 */
class NormalizeBanksByEnvironmentMainCurrency extends Command
{
    use ResolvesMainCurrency;

    protected $signature = 'fix:normalize-banks-by-environment-main-currency
                            {--currency= : 限定處理特定幣別（ISO code 或 ID），不指定則處理所有幣別}
                            {--dry-run : 乾跑模式，不寫入資料庫}
                            {--force : 跳過環境確認}';

    protected $description = '依環境主幣批次翻轉既有 banks 的 type（BANK ↔ FOREIGN_BANK）與本國幣 rate=1';

    public function handle(): int
    {
        $isTest = (bool) $this->option('dry-run');
        $isForce = (bool) $this->option('force');

        if ($isTest) {
            $this->warn('⚠️  乾跑模式：執行後不會寫入資料庫');
            $this->newLine();
        }

        $mainCurrency = $this->resolveEnvironmentMainCurrency();
        if ($mainCurrency === '') {
            $this->error('❌ 當前 DB_CONNECTION 對應的 site_environments.main_currency 未設定，無法執行');
            $this->line(sprintf('  連線: %s', config('database.default')));

            return self::FAILURE;
        }

        $this->info(sprintf('當前環境主幣：%s', $mainCurrency));
        $this->info(sprintf('DB 連線：%s', config('database.default')));
        $this->newLine();

        if (!$isForce && !$this->confirmExecutionEnvironment()) {
            return self::FAILURE;
        }

        $mainCurrencyId = $this->resolveCurrencyId($mainCurrency);
        if ($mainCurrencyId === null) {
            $this->error(sprintf('❌ 主幣 %s 在 currencies 表中找不到對應 row', $mainCurrency));

            return self::FAILURE;
        }

        $filterCurrencyId = $this->parseFilterCurrency();
        if ($this->option('currency') !== null && $filterCurrencyId === null) {
            return self::FAILURE;
        }

        $stats = [
            'foreign_to_bank' => 0,
            'rate_to_one' => 0,
            'bank_to_foreign' => 0,
        ];

        try {
            DB::beginTransaction();

            $this->processForeignToBank($mainCurrencyId, $filterCurrencyId, $stats);
            $this->processRateToOne($mainCurrencyId, $filterCurrencyId, $stats);
            $this->processBankToForeign($mainCurrencyId, $filterCurrencyId, $stats);

            if ($isTest) {
                DB::rollBack();
                $this->newLine();
                $this->warn('✓ 乾跑模式：已回滾所有變更');
            } else {
                DB::commit();
                $this->newLine();
                $this->info('✓ 所有變更已寫入資料庫');
            }

            $this->printStats($stats);

            return self::SUCCESS;
        } catch (Throwable $e) {
            DB::rollBack();
            $this->newLine();
            $this->error(sprintf('發生錯誤：%s', $e->getMessage()));
            $this->line(Common::formatTrace($e));

            return self::FAILURE;
        }
    }

    private function processForeignToBank(int $mainCurrencyId, ?int $filterCurrencyId, array &$stats): void
    {
        $query = Bank::query()
            ->where('currency_id', $mainCurrencyId)
            ->where('type', WithdrawalTypeEnum::FOREIGN_BANK->value);

        if ($filterCurrencyId !== null && $filterCurrencyId !== $mainCurrencyId) {
            return;
        }

        $count = (clone $query)->count();
        if ($count === 0) {
            $this->line('  - 無 (外國銀行 → 本國銀行) 需翻轉的 row');

            return;
        }

        $this->comment(sprintf('▶ 翻轉 %d 筆 banks: FOREIGN_BANK → BANK（主幣 currency_id=%d）', $count, $mainCurrencyId));
        $this->assertNoTypeFlipCollision($query, WithdrawalTypeEnum::BANK->value);
        $query->update(['type' => WithdrawalTypeEnum::BANK->value]);
        $stats['foreign_to_bank'] = $count;
    }

    private function processRateToOne(int $mainCurrencyId, ?int $filterCurrencyId, array &$stats): void
    {
        if ($filterCurrencyId !== null && $filterCurrencyId !== $mainCurrencyId) {
            return;
        }

        $query = Bank::query()
            ->where('currency_id', $mainCurrencyId)
            ->where('rate', '!=', 1);

        $count = (clone $query)->count();
        if ($count === 0) {
            $this->line('  - 無 (本國幣 rate ≠ 1) 需校正的 row');

            return;
        }

        $this->comment(sprintf('▶ 校正 %d 筆 banks: rate → 1（主幣 currency_id=%d）', $count, $mainCurrencyId));
        $query->update(['rate' => 1]);
        $stats['rate_to_one'] = $count;
    }

    private function processBankToForeign(int $mainCurrencyId, ?int $filterCurrencyId, array &$stats): void
    {
        $query = Bank::query()
            ->where('currency_id', '!=', $mainCurrencyId)
            ->where('type', WithdrawalTypeEnum::BANK->value);

        if ($filterCurrencyId !== null) {
            if ($filterCurrencyId === $mainCurrencyId) {
                return;
            }
            $query->where('currency_id', $filterCurrencyId);
        }

        $count = (clone $query)->count();
        if ($count === 0) {
            $this->line('  - 無 (跨幣別 BANK → FOREIGN_BANK) 需翻轉的 row');

            return;
        }

        $this->comment(sprintf('▶ 翻轉 %d 筆 banks: BANK → FOREIGN_BANK（非主幣）', $count));
        $this->assertNoTypeFlipCollision($query, WithdrawalTypeEnum::FOREIGN_BANK->value);
        $query->update(['type' => WithdrawalTypeEnum::FOREIGN_BANK->value]);
        $stats['bank_to_foreign'] = $count;
    }

    /**
     * type 是 active 唯一鍵（uk_banks_active）的成員：整批翻轉前先驗「翻過去會合流」的
     * active 列——同 group＋currency＋code 在目標 type 已有 active 列時，UPDATE 會撞唯一
     * 索引，且單條 UPDATE 具原子性＝撞一列整批失敗（連同批的 rate 等欄位都寫不進）。
     * 有衝突即列出並中止，交由外層交易回滾；處置完重複再重跑。
     *
     * @param  Builder<Bank>  $query
     */
    private function assertNoTypeFlipCollision(Builder $query, int $targetType): void
    {
        $collisions = (clone $query)
            ->where('banks.status', 1)
            ->whereExists(function ($sub) use ($targetType): void {
                $sub->from('banks as target')
                    ->whereColumn('target.agent_group_id', 'banks.agent_group_id')
                    ->whereRaw('target.currency_id <=> banks.currency_id')
                    ->whereColumn('target.code', 'banks.code')
                    ->where('target.type', $targetType)
                    ->where('target.status', 1);
            })
            ->get(['banks.id', 'banks.agent_group_id', 'banks.currency_id', 'banks.code']);

        if ($collisions->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                '翻轉 type 會與既有 active 列在唯一鍵（uk_banks_active）合流，須先處置重複再重跑：%s',
                $collisions->map(fn (Bank $row) => sprintf(
                    '(id=%d, group=%d, currency=%s, code=%s)',
                    $row->id,
                    $row->agent_group_id,
                    $row->currency_id ?? 'NULL',
                    $row->code,
                ))->implode('; '),
            ));
        }
    }

    private function resolveCurrencyId(string $isoCode): ?int
    {
        return Currency::where('code', $isoCode)->value('id');
    }

    private function parseFilterCurrency(): ?int
    {
        $input = $this->option('currency');
        if ($input === null) {
            return null;
        }

        if (is_numeric($input)) {
            $id = (int) $input;
            if (Currency::find($id)) {
                return $id;
            }
            $this->error(sprintf('❌ 幣別 ID %d 不存在', $id));

            return null;
        }

        $code = strtoupper(trim((string) $input));
        $id = Currency::where('code', $code)->value('id');
        if ($id === null) {
            $this->error(sprintf('❌ 幣別代碼 %s 不存在', $code));

            return null;
        }

        return (int) $id;
    }

    private function confirmExecutionEnvironment(): bool
    {
        $connection = config('database.default');

        if (in_array(strtolower((string) $connection), ['mysql', 'dev', 'testing'], true)) {
            return true;
        }

        $this->warn(sprintf('⚠️  當前資料庫連線：%s（疑似正式環境）', $connection));

        return $this->confirm('確認要在此環境執行批次翻轉？', false);
    }

    private function printStats(array $stats): void
    {
        $this->newLine();
        $this->table(
            ['翻轉類型', '影響 banks 數'],
            [
                ['外國銀行 → 本國銀行（FOREIGN_BANK → BANK，主幣）', $stats['foreign_to_bank']],
                ['本國幣 rate → 1', $stats['rate_to_one']],
                ['跨幣別 BANK → FOREIGN_BANK', $stats['bank_to_foreign']],
            ]
        );
    }
}
