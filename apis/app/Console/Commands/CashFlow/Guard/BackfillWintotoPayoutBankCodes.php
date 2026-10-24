<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Guard;

use App\Models\AgentCashFlow;
use App\Models\Bank;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 補 Wintoto TTPay/DGPay Manual 代付渠道 available_banks 缺口的 canonical 碼條目。
 *
 * 【分類】Guard — ⚠️ 非 sync-safe 守護命令。
 *
 * ## 觸發時機
 * 1. **Wintoto（g70／g93）執行 `agent:add-agent-cash-flow`（或串起它的 `cash-flow:auto-sync`）之後**
 *    ——那支依「該渠道 `status=1` 的 `cash_flow_banks` 列」整份重算 available_banks，追加條目會被沖掉。
 *    ⚠️ **只跑 `cash-flow:sync` 不會**：該命令對 available_banks 零引用（見下方〈誰才是重建者〉）
 * 2. 新增／調整 Wintoto TTPay／DGPay 代付渠道之後
 *
 * 漏跑的後果：代付字面比對不中 → 判「不支援銀行」→ fallback KIRA 或轉人工出款。
 *
 * ## 冪等性與重跑安全性
 * **完全冪等，重跑安全。** 追加式修改、不動既有條目；預設 dry-run，`--execute` 才寫入；
 * 防呆任一不符即整體攔截零寫入（跑錯白標 DB 會被擋）。
 *
 * ## 誰才是重建者（2026-09-10 實查更正）
 *
 * ⚠️ **不是 `cash-flow:sync`。** `SyncCashFlowsCommand` 對 `available_banks` 零引用，
 * 也沒有任何寫 `agent_cash_flows` 的路徑；它建的 `banks` 列一律 `agent_group_id = 0`（base 層）。
 * 重建 available_banks 的是 `agent:add-agent-cash-flow`——`buildAvailableBanks()`
 * （`AddAgentCashFlow.php:1651`）只 map「該渠道 `status=1` 的 `cash_flow_banks` 列」，
 * **沒有對應 active cfb 列的項目會被丟掉**；該渠道 cfb 為零列時提早 return、整份不動。
 *
 * 兩支由 `cash-flow:auto-sync` 依序串起（`AutoSyncCashFlowCommand.php:309` 呼 sync、
 * `:358` 呼 add-agent-cash-flow），所以實務上「跑完 sync」多半兩者都跑到——但只跑
 * `cash-flow:sync --ids=` 時本命令的成果不會被沖掉。
 *
 * ## 退役條件
 * 當 `bank:rename-bank` 在 g70／g93 收斂完成、canonical 碼與渠道 available_banks
 * 統一為同一套碼後，本命令即可刪除。它本質是「兩套碼並存」期間的橋接。
 *
 * ## 關聯
 * - 上位路線：`docs/未完成計劃/RB/TODO.md`「金流商架構重構五軌（總綱）」與「Wintoto 代付雙碼 backlog」兩節
 * - 收斂工具＝`bank:rename-bank`（`RenameBankCommand`）：在同一 group 內以 survivor 既有的碼為準，把 loser 的
 *   引用（cfb.bank_code、available_banks、會員綁定）收斂過去並停用 loser；它不替 survivor 改碼。
 *
 * 背景：渠道 available_banks 為手工配置的完整 WTMY01-92 家族（cash_flow_banks 於 cf39 無資料、
 * 非 sync 重建），但 g70 的 Maybank canonical 碼是 `MayBank`（非 WTMY02）、OCBC 是 `OCBC`
 * （非 WTMY09）→ 代付字面比對永不中、只能 fallback KIRA／人工（單 1408515 實案）。
 * g93 的 Maybank canonical 即 WTMY02 可命中，僅 OCBC 同缺。
 *
 * 修法（追加式、最小變更）：在目標 ACF 的 info.available_banks 追加
 * {BankCode_ELG: canonical 碼, BankCode_Third: 渠道既有三方碼}，不動既有佔位條目。
 * 三方碼一律從該 ACF 既有清單「坐實」（MAYB/OCBC 本就掛在 WTMY02/WTMY09 佔位上），不憑空發明。
 *
 * 防呆（任一不符整體攔截零寫入）：ACF 存在且 group/currency 相符、canonical 碼在該 group
 * 有 active banks 列、三方碼可於既有清單坐實、同 target 既有條目三方碼不同即轉人工。
 *
 * rollback 底稿：依執行輸出的追加條目清單，自 available_banks 移除該些條目即可（其餘未動）。
 *
 * php artisan cash-flow:backfill-wintoto-payout-bankcodes            # dry-run（預設）
 * php artisan cash-flow:backfill-wintoto-payout-bankcodes --execute  # 實際執行（需確認）
 */
class BackfillWintotoPayoutBankCodes extends Command
{
    public const BANK_TYPE = 1;

    /** VWorld connection 的 MYR */
    public const CURRENCY_ID = 1;

    /**
     * 目標 ACF 與待補條目（2026-07-13 排查拍板；三方碼源自各 ACF 既有清單）。
     *
     * @var list<array{acf_id: int, agent_group_id: int, entries: list<array{target: string, third: string}>}>
     */
    public const TARGETS = [
        ['acf_id' => 179, 'agent_group_id' => 70, 'entries' => [['target' => 'MayBank', 'third' => 'MAYB'], ['target' => 'OCBC', 'third' => 'OCBC']]],
        ['acf_id' => 297, 'agent_group_id' => 70, 'entries' => [['target' => 'MayBank', 'third' => 'MAYB'], ['target' => 'OCBC', 'third' => 'OCBC']]],
        ['acf_id' => 220, 'agent_group_id' => 93, 'entries' => [['target' => 'OCBC', 'third' => 'OCBC']]],
        ['acf_id' => 298, 'agent_group_id' => 93, 'entries' => [['target' => 'OCBC', 'third' => 'OCBC']]],
    ];

    protected $signature = 'cash-flow:backfill-wintoto-payout-bankcodes
                            {--execute : 實際執行補寫（預設僅 dry-run 報表）}
                            {--force : 跳過執行前確認}';

    protected $description = '補 Wintoto TTPay/DGPay 代付渠道 available_banks 缺口的 canonical 碼條目（預設 dry-run）';

    public function handle(): int
    {
        $errors = [];
        $plan = $this->buildPlan($errors);

        if ($errors !== []) {
            $this->error(sprintf('目標與 DB 現況不符（%d 項），整體攔截、未寫入任何資料：', count($errors)));
            foreach ($errors as $error) {
                $this->line('  - ' . $error);
            }

            return self::FAILURE;
        }

        $this->table(
            ['acf_id', 'group', 'BankCode_ELG', 'BankCode_Third', 'action'],
            array_map(static fn (array $row): array => [
                $row['acf_id'],
                $row['agent_group_id'],
                $row['target'],
                $row['third'],
                $row['action'],
            ], $plan),
        );

        $creates = array_values(array_filter($plan, static fn (array $row): bool => $row['action'] === 'CREATE'));

        if (!$this->option('execute')) {
            $this->newLine();
            $this->info(sprintf('🔍 Dry-run 模式，未寫入。待追加 %d 筆條目。加 --execute 以實際執行。', count($creates)));

            return self::SUCCESS;
        }

        if ($creates === []) {
            $this->newLine();
            $this->info('✅ 無需變更（全部已就緒，冪等）');

            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm(sprintf('確認在 %d 條 ACF 的 available_banks 追加 %d 筆條目？', count(array_unique(array_column($creates, 'acf_id'))), count($creates)), false)) {
            $this->info('已取消，未寫入任何資料。');

            return self::SUCCESS;
        }

        $this->executeBackfill($creates);

        $this->newLine();
        $this->info(sprintf('✅ 完成，追加 %d 筆條目（rollback 底稿＝上表 CREATE 列，自 available_banks 移除即可還原）。', count($creates)));

        return self::SUCCESS;
    }

    /**
     * 逐目標驗證並建立計劃；驗證錯誤累積於 $errors（非空即整體攔截）。
     *
     * @param  list<string>  $errors
     * @return list<array{acf_id: int, agent_group_id: int, target: string, third: string, action: string}>
     */
    private function buildPlan(array &$errors): array
    {
        $plan = [];

        foreach (self::TARGETS as $target) {
            $agentCashFlow = AgentCashFlow::query()->find($target['acf_id']);

            if ($agentCashFlow === null) {
                $errors[] = sprintf('ACF %d：DB 無此列', $target['acf_id']);

                continue;
            }

            if ((int) $agentCashFlow->agent_group_id !== $target['agent_group_id']) {
                $errors[] = sprintf('ACF %d：agent_group_id=%d ≠ 預期 %d', $target['acf_id'], (int) $agentCashFlow->agent_group_id, $target['agent_group_id']);

                continue;
            }

            if ((int) $agentCashFlow->currency_id !== self::CURRENCY_ID) {
                $errors[] = sprintf('ACF %d：currency_id=%s ≠ 預期 %d', $target['acf_id'], (string) $agentCashFlow->currency_id, self::CURRENCY_ID);

                continue;
            }

            $availableBanks = $agentCashFlow->info['available_banks'] ?? null;

            if (!is_array($availableBanks)) {
                $errors[] = sprintf('ACF %d：info.available_banks 缺失或格式錯誤', $target['acf_id']);

                continue;
            }

            foreach ($target['entries'] as $entry) {
                $plan[] = [
                    'acf_id' => $target['acf_id'],
                    'agent_group_id' => $target['agent_group_id'],
                    'target' => $entry['target'],
                    'third' => $entry['third'],
                    'action' => $this->resolveAction($target, $entry, $availableBanks, $errors),
                ];
            }
        }

        return $plan;
    }

    /**
     * 判定單一條目動作：CREATE / SKIP（已就緒）／BLOCKED（累積錯誤）。
     *
     * @param  array{acf_id: int, agent_group_id: int}  $target
     * @param  array{target: string, third: string}  $entry
     * @param  list<array<string, mixed>>  $availableBanks
     * @param  list<string>  $errors
     */
    private function resolveAction(array $target, array $entry, array $availableBanks, array &$errors): string
    {
        // canonical 碼必須對應該 group 一列 active bank（跑錯白標 DB 在此被擋）
        $canonicalExists = Bank::query()
            ->where('agent_group_id', $target['agent_group_id'])
            ->where('type', self::BANK_TYPE)
            ->where('currency_id', self::CURRENCY_ID)
            ->where('code', $entry['target'])
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->exists();

        if (!$canonicalExists) {
            $errors[] = sprintf('ACF %d：group %d 無 active canonical bank code=%s', $target['acf_id'], $target['agent_group_id'], $entry['target']);

            return 'BLOCKED';
        }

        // 三方碼須能在該 ACF 既有清單坐實（不憑空發明三方碼）
        $thirdSubstantiated = collect($availableBanks)->contains(
            static fn (array $bank): bool => (string) ($bank['BankCode_Third'] ?? '') === $entry['third'],
        );

        if (!$thirdSubstantiated) {
            $errors[] = sprintf('ACF %d：既有清單無三方碼 %s，無法坐實', $target['acf_id'], $entry['third']);

            return 'BLOCKED';
        }

        $existing = collect($availableBanks)->first(
            static fn (array $bank): bool => (string) ($bank['BankCode_ELG'] ?? '') === $entry['target'],
        );

        if ($existing === null) {
            return 'CREATE';
        }

        if ((string) ($existing['BankCode_Third'] ?? '') !== $entry['third']) {
            $errors[] = sprintf('ACF %d：既有 %s 條目三方碼=%s ≠ 預期 %s，轉人工複核', $target['acf_id'], $entry['target'], (string) ($existing['BankCode_Third'] ?? ''), $entry['third']);

            return 'BLOCKED';
        }

        return 'SKIP';
    }

    /**
     * 執行補寫（單一交易）：逐 ACF lockForUpdate 後追加條目，只動 available_banks。
     *
     * @param  list<array{acf_id: int, agent_group_id: int, target: string, third: string, action: string}>  $creates
     */
    private function executeBackfill(array $creates): void
    {
        $byAcf = [];
        foreach ($creates as $row) {
            $byAcf[$row['acf_id']][] = $row;
        }

        DB::transaction(function () use ($byAcf): void {
            foreach ($byAcf as $acfId => $rows) {
                /** @var AgentCashFlow $agentCashFlow */
                $agentCashFlow = AgentCashFlow::query()->lockForUpdate()->findOrFail($acfId);

                $info = $agentCashFlow->info;

                foreach ($rows as $row) {
                    $info['available_banks'][] = [
                        'BankCode_ELG' => $row['target'],
                        'BankCode_Third' => $row['third'],
                    ];
                }

                $agentCashFlow->info = $info;
                $agentCashFlow->save();
            }
        });
    }
}
