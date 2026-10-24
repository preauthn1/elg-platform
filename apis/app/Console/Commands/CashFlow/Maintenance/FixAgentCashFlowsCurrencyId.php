<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Maintenance;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 補齊代付渠道（cash_flows.type=3）的 agent_cash_flows.currency_id。
 *
 * 【分類】Maintenance — 可複用維運工具，需要時才跑。
 *
 * ## 發生原因
 * `agent_cash_flows.currency_id` 在 schema 上是 **nullable**（見 Model `@property int|null`），
 * 代付渠道的幣別資訊實際散落在 `cash_flow_banks` 與 `info.available_banks[]`，ACF 層這個
 * 欄位是後來才要求填的便利欄位，因此存在 NULL／0 的歷史列。
 *
 * 現行主要建立路徑 `agent:add-agent-cash-flow` **已會帶入** currency_id
 * （`AddAgentCashFlow.php:573`、`:577`、`:1215`、`:1225`），所以新資料通常不缺；
 * 缺口來自欄位要求填入之前的歷史列，以及不經該命令的其他寫入路徑。
 *
 * ## 修正目的
 * 為 status=1 且 currency_id 為 NULL／0 的代付渠道推導並補上幣別，來源鏈由可靠到次要：
 * 1. **`cash_flow_banks`**（最可靠）：該 cash_flow 底下 active 的 cfb 若幣別唯一（`COUNT(DISTINCT currency_id) = 1`）即採用
 * 2. **base banks 反查**（fallback）：由 `info.available_banks[].BankCode_ELG` → `banks`（agent_group_id=0）
 *    取幣別，同樣**只在推導結果唯一時**才採用
 *
 * 任一列推不出唯一解就跳過（不猜、不寫入），列在報表由人工處理。
 *
 * ## 觸發時機
 * 1. 代付渠道查詢／報表出現「幣別空白」時
 * 2. 從舊系統匯入或人工建立 ACF 之後的配置健檢
 * 3. 新白標建置完成後
 *
 * ## 冪等性與重跑安全性
 * **完全冪等，重跑安全。** 只補 NULL／0 的列，**不覆寫任何已有值**；有 `--dry-run` 預覽與
 * `confirm()` 二次確認；`--agent-group-id` 可限縮範圍逐群組跑。
 * 自帶自我檢測：待修筆數為 0 時直接回「✅ 所有代付渠道都已有 currency_id，無需修復」並結束。
 *
 * ## 退役條件
 * 當 `agent_cash_flows.currency_id` 改為 **NOT NULL**（由 schema 層強制、寫入端全數補齊）後，
 * 本命令即可刪除。在那之前它是持續有效的補值工具。
 *
 * ## 關聯
 * - 建立端：`app/Console/Commands/CashFlow/AddAgentCashFlow.php`
 * - schema 治理：`docs/未完成計劃/金流商架構重構/denormalization-設計原則.md`（上位規範，原 5-schema治理/ 已攤平）
 *
 * 用法：
 *   php artisan cash-flow:fix-currency-id --dry-run                    # 預覽
 *   php artisan cash-flow:fix-currency-id --agent-group-id=70          # 限定群組
 *   php artisan cash-flow:fix-currency-id                              # 實際修復（需確認）
 */
class FixAgentCashFlowsCurrencyId extends Command
{
    protected $signature = 'cash-flow:fix-currency-id
                            {--dry-run : 只顯示需要修復的資料，不實際更新}
                            {--agent-group-id= : 只處理指定的代理群組}';

    protected $description = '修復代付渠道（cash_flows.type=3）的 agent_cash_flows.currency_id';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $agentGroupId = $this->option('agent-group-id');

        $this->info('開始檢查代付渠道 agent_cash_flows 的 currency_id...');
        $this->newLine();

        // 統計資料
        $stats = $this->getStatistics($agentGroupId);
        $this->displayStatistics($stats);

        if ($stats['null_count'] === 0) {
            $this->info('✅ 所有代付渠道都已有 currency_id，無需修復');

            return self::SUCCESS;
        }

        $this->newLine();

        // 取得需要修復的代付資料
        $recordsToFix = $this->getPayoutRecordsToFix($agentGroupId);

        if ($recordsToFix->isEmpty()) {
            $this->warn('⚠️  沒有找到可以自動修復的代付渠道資料');

            return self::SUCCESS;
        }

        $this->info(sprintf('找到 %d 筆可以自動修復的資料', $recordsToFix->count()));
        $this->newLine();

        // 顯示修復計劃
        $this->displayFixPlan($recordsToFix);

        if ($isDryRun) {
            $this->info('🔍 Dry-run 模式，未實際執行更新');

            return self::SUCCESS;
        }

        // 確認是否執行
        if (!$this->confirm('是否執行修復？', true)) {
            $this->info('已取消');

            return self::SUCCESS;
        }

        // 執行修復
        $result = $this->executeFix($recordsToFix);

        $this->newLine();
        $this->displayResult($result);

        return self::SUCCESS;
    }

    /**
     * 取得統計資料（只統計 cash_flows.type=3 的代付渠道）
     */
    protected function getStatistics(?string $agentGroupId): array
    {
        $query = DB::table('agent_cash_flows as acf')
            ->join('cash_flows as cf', 'acf.cash_flow_id', '=', 'cf.id')
            ->where('acf.status', 1)
            ->where('cf.type', 3)
            ->whereNotIn('acf.cash_flow_id', [5, 6]);

        if ($agentGroupId !== null) {
            $query->where('acf.agent_group_id', (int) $agentGroupId);
        }

        $total = $query->count();
        $hasValue = (clone $query)->where('acf.currency_id', '>', 0)->count();
        $nullCount = $total - $hasValue;

        return [
            'total' => $total,
            'has_value' => $hasValue,
            'null_count' => $nullCount,
        ];
    }

    /**
     * 顯示統計資料
     */
    protected function displayStatistics(array $stats): void
    {
        $this->table(
            ['項目', '數量'],
            [
                ['代付渠道（type=3）總數', $stats['total']],
                ['已有 currency_id', $stats['has_value']],
                ['currency_id 為 NULL 或 0', $stats['null_count']],
            ]
        );
    }

    /**
     * 取得需要修復的代付資料（type=3）
     *
     * 優先順序：
     *   1. cash_flow_banks 直接儲存 currency_id，最可靠
     *   2. fallback：info.available_banks[].BankCode_ELG → banks（agent_group_id=0）
     */
    protected function getPayoutRecordsToFix(?string $agentGroupId)
    {
        $query = DB::table('agent_cash_flows as acf')
            ->join('cash_flows as cf', 'acf.cash_flow_id', '=', 'cf.id')
            ->where('acf.status', 1)
            ->where('cf.type', 3)
            ->where(fn ($q) => $q->whereNull('acf.currency_id')->orWhere('acf.currency_id', 0))
            ->whereNotIn('acf.cash_flow_id', [5, 6]);

        if ($agentGroupId !== null) {
            $query->where('acf.agent_group_id', (int) $agentGroupId);
        }

        $records = $query->select(
            'acf.id',
            'acf.agent_group_id',
            'acf.cash_flow_id',
            'acf.name',
            'example.invalid'
        )->get();

        // 預載 cash_flow_id → currency_id 對應（從 cash_flow_banks，最可靠來源）
        $cashFlowIds = $records->pluck('cash_flow_id')->unique()->values()->all();
        $cfbCurrencyMap = DB::table('cash_flow_banks')
            ->whereIn('cash_flow_id', $cashFlowIds)
            ->where('status', 1)
            ->select('cash_flow_id', DB::raw('COUNT(DISTINCT currency_id) as cnt'), DB::raw('MIN(currency_id) as currency_id'))
            ->groupBy('cash_flow_id')
            ->get()
            ->keyBy('cash_flow_id');

        // 預載 base banks（agent_group_id=0）bank_code → currency_id（fallback 用）
        $baseBankCurrencyMap = DB::table('banks')
            ->where('agent_group_id', 0)
            ->whereNotNull('currency_id')
            ->where('currency_id', '>', 0)
            ->select('code', 'currency_id')
            ->get()
            ->pluck('currency_id', 'code')
            ->all();

        $result = collect();

        foreach ($records as $record) {
            $currencyId = null;
            $source = '';

            // 優先：cash_flow_banks 直接有 currency_id
            $cfbRow = $cfbCurrencyMap->get($record->cash_flow_id);
            if ($cfbRow && $cfbRow->cnt === 1 && $cfbRow->currency_id > 0) {
                $currencyId = (int) $cfbRow->currency_id;
                $source = 'cash_flow_banks';
            }

            // Fallback：從 info.available_banks → base banks（agent_group_id=0）反查
            if ($currencyId === null) {
                $info = json_decode($record->info, true);
                $bankCodes = array_filter(array_column($info['available_banks'] ?? [], 'BankCode_ELG'));

                if (!empty($bankCodes)) {
                    $currencyIds = array_unique(array_filter(
                        array_map(fn ($code) => $baseBankCurrencyMap[$code] ?? null, $bankCodes)
                    ));

                    if (count($currencyIds) === 1) {
                        $currencyId = (int) reset($currencyIds);
                        $source = 'base_banks';
                    }
                }
            }

            if ($currencyId === null || $currencyId <= 0) {
                continue;
            }

            $result->push((object) [
                'id' => $record->id,
                'agent_group_id' => $record->agent_group_id,
                'cash_flow_id' => $record->cash_flow_id,
                'name' => $record->name,
                'currency_id' => $currencyId,
                'source' => $source,
            ]);
        }

        return $result;
    }

    /**
     * 顯示修復計劃
     */
    protected function displayFixPlan($records): void
    {
        $this->info('修復計劃：');
        $this->newLine();

        $rows = $records->take(20)->map(fn ($record) => [
            $record->id,
            $record->agent_group_id,
            $record->name,
            $record->currency_id,
            $record->source ?? '-',
        ]);

        $this->table(
            ['Agent CashFlow ID', '代理群組 ID', '名稱', '將設定的 currency_id', '來源'],
            $rows
        );

        if ($records->count() > 20) {
            $this->info(sprintf('... 還有 %d 筆資料', $records->count() - 20));
        }
    }

    /**
     * 執行修復
     */
    protected function executeFix($records): array
    {
        $successCount = 0;
        $failCount = 0;
        $errors = [];

        $this->info('開始執行修復...');
        $progressBar = $this->output->createProgressBar($records->count());

        foreach ($records as $record) {
            try {
                DB::table('agent_cash_flows')
                    ->where('id', $record->id)
                    ->update(['currency_id' => $record->currency_id]);

                $successCount++;
            } catch (\Exception $e) {
                $failCount++;
                $errors[] = sprintf(
                    'ID %d: %s',
                    $record->id,
                    $e->getMessage()
                );
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        return [
            'success' => $successCount,
            'fail' => $failCount,
            'errors' => $errors,
        ];
    }

    /**
     * 顯示執行結果
     */
    protected function displayResult(array $result): void
    {
        $this->info('修復完成！');
        $this->newLine();

        $this->table(
            ['項目', '數量'],
            [
                ['成功修復', $result['success']],
                ['失敗', $result['fail']],
            ]
        );

        if (!empty($result['errors'])) {
            $this->newLine();
            $this->error('錯誤訊息：');
            foreach ($result['errors'] as $error) {
                $this->error('  ' . $error);
            }
        }
    }
}
