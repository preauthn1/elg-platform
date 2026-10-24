<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Patch;

use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 移除新組 DGPAY「代收」binding 殘留的動態解析 key（PROVIDER_ID / PROVIDER_TYPE / PAYMENT_CODE）。
 *
 * 【分類】Patch — 一次性，各站跑完即待退役。
 *
 * ## 觸發時機
 * 各白標各跑一次即可。**來源已斷**：`app/Console/Commands/CashFlow/Data/providers/DgPay.php` 已從 credentials 模板移除這三個 key，
 * 之後新建的 binding 天生不帶，因此本命令只需清理「移除前已 seed」的存量。
 *
 * ## 冪等性與重跑安全性
 * **冪等，重跑安全。** key 不存在即 no-op；支援 `--dry-run`、`--cashflow` 限縮。
 *
 * ## 退役條件
 * 六站各跑一次、`--dry-run` 皆回報零筆後即可刪除本命令（來源已斷，不會再生）。
 *
 * ## 關聯
 * - 動態解析實作：`Deposit::resolveProviderPair()`／`resolvePaymentType()`
 * - 為什麼用 Eloquent `info` array cast 的 unset 而不是 raw `JSON_REMOVE`：避開「禁未綁定 DB::raw」紅線，
 *   且 unset 天然冪等。
 * - credentials 模板移除三 key 與本 patch 必須一起上：只改模板會留下存量殘留；只跑 patch，
 *   重跑 seed 會從 credentials 把三 key 補回來。
 *
 * 背景：這三 key 在代收恆為 0/空，實際 provider pair 與 paymentType 全由 order_types.key 動態解析
 * （Deposit::resolveProviderPair() / resolvePaymentType()）。DgPay 的 providers Data 檔已從 credentials 模板
 * 移除三 key（未來新建 binding 天生乾淨），本補丁清掉「移除前已 seed」殘留在 agent_cash_flows.info
 * 的舊值，從結構根絕「binding 帶靜態 5/10 → 繞過動態路由 → 把 channel key 當 fromBankCode 送出」的漂移。
 *
 * 守備範圍（舊組 DG 與代付 120 絕不碰，守門結構化落實而非只靠 WHERE）：
 *   - 僅處理 DgPay「代收」cash flow（type=1 且 third_party_code 去前綴 = dgpay）。
 *   - 舊組 DG（third_party_code=D_DG → 去前綴=dg）與代付 120（type=3）天然被拒，其靜態 pair 是必要的。
 *
 * 冪等：info 以 array cast 讀出，unset 後 save；key 不存在即 no-op，可重複執行。
 *
 * 使用範例：
 *   php artisan fix:dgpay-deposit-strip-dynamic-keys --dry-run
 *   php artisan fix:dgpay-deposit-strip-dynamic-keys --cashflow=119
 */
class StripDgPayDepositDynamicKeys extends Command
{
    protected $signature = 'fix:dgpay-deposit-strip-dynamic-keys
                            {--cashflow=119 : DgPay 代收 cash_flow ID（預設 119 = D_DGPAY）}
                            {--dry-run : 乾跑模式，不寫入資料庫}
                            {--force : 跳過環境確認}';

    protected $description = '移除新組 DGPAY 代收 binding 殘留的 PROVIDER_ID / PROVIDER_TYPE / PAYMENT_CODE（代收恆走 channel key 動態解析）';

    /** @var array<int, string> 代收恆為 0/空、應由 channel key 動態解析的 key */
    private const KEYS_TO_STRIP = ['PROVIDER_ID', 'PROVIDER_TYPE', 'PAYMENT_CODE'];

    public function handle(): int
    {
        $isTest = (bool) $this->option('dry-run');
        $isForce = (bool) $this->option('force');

        if ($isTest) {
            $this->warn('⚠️  乾跑模式：執行後不會寫入資料庫');
            $this->newLine();
        }

        if (!$isForce && !$this->confirmExecutionEnvironment()) {
            return self::FAILURE;
        }

        $cashFlow = $this->resolveDepositCashFlow((int) $this->option('cashflow'));
        if (!$cashFlow) {
            return self::FAILURE;
        }

        $this->info(sprintf('金流商: %s (ID: %d, third_party_code: %s)', $cashFlow->name, $cashFlow->id, (string) $cashFlow->third_party_code));
        $this->newLine();

        $bindings = AgentCashFlow::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->get();

        if ($bindings->isEmpty()) {
            $this->warn('⚠️  找不到任何代收 binding，無需處理');

            return self::SUCCESS;
        }

        $plan = $this->buildPlan($bindings);
        $this->displayPlan($plan);

        if ($plan['toStrip'] === []) {
            $this->info(sprintf('✓ 全部 %d 筆 binding 皆已乾淨，無殘留 key（冪等 no-op）', $bindings->count()));

            return self::SUCCESS;
        }

        if (!$isTest && !$isForce && $this->input->isInteractive()) {
            if (!$this->confirm(sprintf('確定移除 %d 筆 binding 的殘留 key？此操作會修改資料庫', count($plan['toStrip'])), false)) {
                $this->info('✓ 已取消');

                return self::SUCCESS;
            }
        }

        try {
            DB::beginTransaction();

            foreach ($plan['toStrip'] as $row) {
                /** @var AgentCashFlow $binding */
                $binding = $row['binding'];
                $info = $binding->info ?? [];

                foreach (self::KEYS_TO_STRIP as $key) {
                    unset($info[$key]);
                }

                $binding->info = $info;
                $binding->save();

                $this->line(sprintf('  ✓ id=%d「%s」移除 [%s]', $binding->id, $binding->name, implode(', ', $row['present'])));
            }

            if ($isTest) {
                DB::rollBack();
                $this->newLine();
                $this->warn('✓ 乾跑模式：已 rollback，未寫入資料庫');
            } else {
                DB::commit();
                $this->newLine();
                $this->info(sprintf('✓ 完成：%d 筆 binding 已移除殘留 key', count($plan['toStrip'])));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error(sprintf('❌ 執行錯誤: %s', $e->getMessage()));
            $this->line(sprintf('   位置: %s:%d', $e->getFile(), $e->getLine()));

            return self::FAILURE;
        }
    }

    /**
     * 規劃：分出「有殘留 key 需處理」與「已乾淨」。
     *
     * @param  \Illuminate\Support\Collection<int, AgentCashFlow>  $bindings
     * @return array{toStrip: array<int, array{binding: AgentCashFlow, present: array<int, string>}>, clean: int}
     */
    private function buildPlan($bindings): array
    {
        $toStrip = [];
        $clean = 0;

        foreach ($bindings as $binding) {
            $info = $binding->info ?? [];
            $present = array_values(array_filter(
                self::KEYS_TO_STRIP,
                static fn (string $key): bool => array_key_exists($key, $info),
            ));

            if ($present === []) {
                $clean++;

                continue;
            }

            $toStrip[] = ['binding' => $binding, 'present' => $present];
        }

        return ['toStrip' => $toStrip, 'clean' => $clean];
    }

    /** 顯示處理計畫 */
    private function displayPlan(array $plan): void
    {
        $this->info('📋 掃描結果：');

        $rows = [];
        foreach ($plan['toStrip'] as $row) {
            /** @var AgentCashFlow $binding */
            $binding = $row['binding'];
            $rows[] = [$binding->id, $binding->name, implode(', ', $row['present'])];
        }

        if ($rows !== []) {
            $this->table(['AgentCashFlow ID', 'name', '殘留 key（將移除）'], $rows);
        }

        $this->line(sprintf('  待處理 %d 筆、已乾淨 %d 筆', count($plan['toStrip']), $plan['clean']));
        $this->newLine();
    }

    /**
     * 解析並守門：必須是 DgPay「代收」cash flow。
     *
     * 守 type=1（代付 120 走 type=3 被拒）＋ third_party_code 去前綴 = dgpay（舊組 DG 被拒），
     * 結構性落實「舊組 DG 與代付 120 絕不碰」的守備範圍，避免 --cashflow 誤指到不該清的 cash flow。
     */
    private function resolveDepositCashFlow(int $cashFlowId): ?CashFlow
    {
        $cashFlow = CashFlow::find($cashFlowId);
        if (!$cashFlow) {
            $this->error(sprintf('❌ 金流商不存在: %d', $cashFlowId));

            return null;
        }

        if ($cashFlow->type !== 1) {
            $this->error(sprintf('❌ cash_flow %d 非代收（type=%d）；本補丁只處理代收 binding，代付靜態 pair 是必要的不可清', $cashFlowId, $cashFlow->type));

            return null;
        }

        $providerName = strtolower((string) preg_replace('/^[DW]_/', '', (string) $cashFlow->third_party_code));
        if ($providerName !== 'dgpay') {
            $this->error(sprintf('❌ cash_flow %d 非 DgPay（third_party_code=%s）；舊組 DG 等其他金流商不在守備範圍', $cashFlowId, (string) $cashFlow->third_party_code));

            return null;
        }

        return $cashFlow;
    }

    /** 環境確認（沿用其他 CashFlow patch：放行 mysql / dev / testing） */
    private function confirmExecutionEnvironment(): bool
    {
        $dbConnection = config('database.default');
        if (in_array(strtolower((string) $dbConnection), ['mysql', 'dev', 'testing'], true)) {
            return true;
        }

        $this->warn(sprintf('⚠️  當前資料庫連線: %s', $dbConnection));

        return $this->confirm('確定要在此環境執行？', false);
    }
}
