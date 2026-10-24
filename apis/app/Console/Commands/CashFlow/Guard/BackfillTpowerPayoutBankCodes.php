<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Guard;

use App\Models\Bank;
use App\Models\CashFlowBank;
use App\Models\Currency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 補 TPower paypro(cf102) IDR 本地碼→代付銀行碼的 cash_flow_banks 對映，是 cash-flow:sync 名稱比對漏掉時的安全網。
 *
 * 【分類】Guard — ⚠️ 非 sync-safe 守護命令。
 *
 * ## 觸發時機
 * **對 TPower 執行 `cash-flow:sync`（PayPro、幣別 IDR 或不指定）之後跑，跑完一定要抽驗**
 * （`cash-flow:verify-payout-bank-parity`、`withdrawal:check-payout-channels`）。
 * sync 會先停用 PayPro 的全部對映，再依銀行名稱比對重新啟用；本地碼的對映能不能恢復，完全看名稱對不對得上。
 * - 現況（2026-09-15 TPower 資料）：TPID51、TPID53 的 base(group=0) 銀行名稱與 PayPro 資料相同（只差大小寫也算），
 *   TPID06 靠 `app/Console/Commands/CashFlow/Data/banks/IDR.php` 的銀行別名對上，sync 會自己重新啟用，本命令執行時全數跳過。
 *   這是讀代碼、查資料推論的結論，沒有實跑 sync 驗證。
 * - 會漏的情況：base(group=0) 銀行改名、PayPro 資料改名，或銀行別名被改、被刪，名稱對不上，對映就維持停用，
 *   代付判「不支援」、出款失敗或退款。2026-07-11 生產事故即此：當時 TPID53 的銀行名稱是「(IDR) - DANA eWallet」，
 *   PayPro 資料是「(IDR) - Dana」。
 *
 * ## 冪等性與重跑安全性
 * **完全冪等，重跑安全。** 缺漏就新建，被停用或碼不符就更正，已就緒的跳過；沒有可寫入項目時，不出確認提示就結束。
 * `--dry-run` 可先預覽。
 *
 * ## 退役條件
 * 任一達成即可刪除：
 * 1. TPower IDR 的 banks 收斂為**單套碼**（本地碼 TPIDxx 退場，會員綁定改綁標準碼）；或
 * 2. `cash-flow:sync` 改為以 `standard_bank_id`／`cash_flow_banks` 外鍵重建，**不再靠 name matching**。
 *
 * 在那之前**不可刪**：現況名稱對得上只是暫時的，sync 仍靠名稱比對，名稱一變，就沒有別的機制補回對映。
 *
 * ## 為什麼補 cash_flow_banks 而不是只改 available_banks
 * 出款對映（`WithdrawalPayoutTrait::convertPayoutBankCode()`）依 `config('payment.payout_bank_lookup_providers')`
 * allowlist 分兩條來源：provider 未灰度時讀 ACF 的 available_banks JSON，已灰度時查 cash_flow_banks 表；
 * 而 `withdrawal:check-payout-channels` 永遠只讀 available_banks。只改 JSON 在已灰度的站會出現
 * 「check 顯示可用、出款仍失敗」的假象，比原事故更難查。本命令自己只寫 cash_flow_banks；
 * 補完要再跑 `agent:add-agent-cash-flow` 由表重建該渠道的 available_banks，兩條路徑才都成立。
 * 事故本尊：2026-07-11 TPower 訂單 2136988（agent_group 674、DANA 本地碼 TPID53）退款，
 * 同期近 30 天 DANA 出款受阻 77 張；當時 11 家 IDR 雙碼銀行中 7 家已有對映，真正缺口只有本命令補的 4 家。
 * 本方案把「一家兩個 target 碼」暫時固定下來，與根治方向（一家一套碼）相反，屬過渡止血。
 *
 * ## 關聯
 * - 同缺口未修的 backlog：FPay(68)／SurePay(98)
 * - 會停用本命令的對映、再依名稱比對決定是否重新啟用的：`SyncCashFlowsCommand`（`disableAllCashFlowBanks` + name matching 重建）
 *
 * 背景：banks 表同一家銀行存兩套碼（本地碼 TPIDxx / 標準碼 IDRxxxx）；
 * 會員綁本地碼，但 cash_flow_banks 由 cash-flow:sync 靠 name matching 重建，
 * 名稱比對不中的本地碼在 cash_flow_banks 就沒有啟用中的對映 → 代付判「不支援」、出款失敗/退款。
 *
 * 本命令補齊這批本地碼的 cash_flow_banks 對映（冪等、可重跑）。
 *
 * ⚠️ 非 sync-safe：sync 不保證重建這批對映。名稱比對不中的本地碼，對映會被 disableAllCashFlowBanks 沖為 status=0
 * 且不會重新啟用，需重跑本命令補回。
 *
 * php artisan cash-flow:backfill-tpower-payout-bankcodes --dry-run
 */
class BackfillTpowerPayoutBankCodes extends Command
{
    protected $signature = 'cash-flow:backfill-tpower-payout-bankcodes
                            {--dry-run : 只顯示計劃，不實際寫入}
                            {--cash-flow-id=102 : 目標 cash_flow_id（預設 102=PayPro 代付）}
                            {--currency-code=IDR : 目標幣別代碼}';

    protected $description = '補 TPower paypro 缺口的本地碼→三方碼 cash_flow_banks 對映（冪等、非 sync-safe 需守護）';

    /**
     * 本地碼 → 代付銀行碼對映（代付銀行碼 authoritative source：app/Console/Commands/CashFlow/Data/providers/PayPro.php）。
     *
     * TPID05 CIMB Bank Indonesia → 0010（CIMB Niaga）：TPower 的 base(group=0) 銀行 2026-08-14 已停用並標刪，
     *   在 TPower 本筆一律跳過；CIMB 的對映已由標準碼 IDR0024 承接。
     * TPID06 Maybank Indonesia   → 0008（BII Maybank）
     * TPID51 Link Aja            → linkaja
     * TPID53 DANA eWallet        → dana
     */
    private const MAPPINGS = [
        'TPID05' => '0010',
        'TPID06' => '0008',
        'TPID51' => 'linkaja',
        'TPID53' => 'dana',
    ];

    public function handle(): int
    {
        $cashFlowId = (int) $this->option('cash-flow-id');
        $currencyCode = strtoupper(trim((string) $this->option('currency-code')));
        $isDryRun = (bool) $this->option('dry-run');

        $currencyId = (int) Currency::query()->where('code', $currencyCode)->value('id');
        if ($currencyId <= 0) {
            $this->error("找不到幣別代碼 {$currencyCode} 對應的 currency_id");

            return self::FAILURE;
        }

        $this->info(sprintf('目標：cash_flow_id=%d, currency=%s(%d)', $cashFlowId, $currencyCode, $currencyId));
        $this->newLine();

        $plan = $this->buildPlan($cashFlowId, $currencyId);
        $this->displayPlan($plan);

        $actionable = array_values(array_filter(
            $plan,
            static fn (array $row): bool => in_array($row['action'], ['CREATE', 'UPDATE'], true),
        ));

        if ($isDryRun) {
            $this->newLine();
            $this->info('🔍 Dry-run 模式，未實際寫入');

            return self::SUCCESS;
        }

        if ($actionable === []) {
            $this->newLine();
            $this->info('✅ 無需變更（全部已就緒或無可補項）');

            return self::SUCCESS;
        }

        if (!$this->confirm('確認補寫上述 cash_flow_banks？', false)) {
            $this->info('已取消');

            return self::SUCCESS;
        }

        $this->executeBackfill($actionable, $cashFlowId, $currencyId);

        $this->newLine();
        $this->info(sprintf('✅ 完成，寫入 %d 筆', count($actionable)));

        return self::SUCCESS;
    }

    /**
     * 建立補寫計劃：逐筆解析 base(group=0) bank_id 與現況，判定 CREATE/UPDATE/SKIP。
     *
     * @return list<array{bank_code: string, provider_bank_code: string, bank_id: int|null, action: string, note: string}>
     */
    private function buildPlan(int $cashFlowId, int $currencyId): array
    {
        $plan = [];

        foreach (self::MAPPINGS as $bankCode => $providerBankCode) {
            // bank_id 一律取 group=0 的平台銀行主檔（cash_flow_banks.bank_id 慣例）
            $bankId = (int) Bank::query()
                ->where('agent_group_id', 0)
                ->where('currency_id', $currencyId)
                ->where('code', $bankCode)
                ->where('status', 1)
                ->value('id');

            if ($bankId <= 0) {
                $plan[] = [
                    'bank_code' => $bankCode,
                    'provider_bank_code' => $providerBankCode,
                    'bank_id' => null,
                    'action' => 'SKIP',
                    'note' => '缺 base(group=0) bank，跳過（不建錯 bank_id）',
                ];

                continue;
            }

            $existing = CashFlowBank::query()
                ->where('cash_flow_id', $cashFlowId)
                ->where('currency_id', $currencyId)
                ->where('bank_id', $bankId)
                ->first();

            if ($existing === null) {
                $action = 'CREATE';
                $note = '新建';
            } elseif ((int) $existing->status !== 1
                || (string) $existing->provider_bank_code !== $providerBankCode
                || (string) $existing->bank_code !== $bankCode) {
                $action = 'UPDATE';
                $note = sprintf('更新（現況 status=%d, provider=%s）', (int) $existing->status, $existing->provider_bank_code);
            } else {
                $action = 'SKIP';
                $note = '已就緒';
            }

            $plan[] = [
                'bank_code' => $bankCode,
                'provider_bank_code' => $providerBankCode,
                'bank_id' => $bankId,
                'action' => $action,
                'note' => $note,
            ];
        }

        return $plan;
    }

    /**
     * @param  list<array{bank_code: string, provider_bank_code: string, bank_id: int|null, action: string, note: string}>  $plan
     */
    private function displayPlan(array $plan): void
    {
        $this->table(
            ['bank_code', 'provider_bank_code', 'bank_id', 'action', 'note'],
            array_map(static fn (array $row): array => [
                $row['bank_code'],
                $row['provider_bank_code'],
                $row['bank_id'] ?? '-',
                $row['action'],
                $row['note'],
            ], $plan),
        );
    }

    /**
     * 執行補寫（transaction 包裹）：以唯一鍵 (cash_flow_id, currency_id, bank_id) upsert，status=1。
     *
     * @param  list<array{bank_code: string, provider_bank_code: string, bank_id: int|null, action: string, note: string}>  $actionable
     */
    private function executeBackfill(array $actionable, int $cashFlowId, int $currencyId): void
    {
        DB::transaction(function () use ($actionable, $cashFlowId, $currencyId): void {
            foreach ($actionable as $row) {
                CashFlowBank::updateOrCreate(
                    [
                        'cash_flow_id' => $cashFlowId,
                        'currency_id' => $currencyId,
                        'bank_id' => $row['bank_id'],
                    ],
                    [
                        'bank_code' => $row['bank_code'],
                        'provider_bank_code' => $row['provider_bank_code'],
                        'status' => 1,
                    ],
                );
            }
        });
    }
}
