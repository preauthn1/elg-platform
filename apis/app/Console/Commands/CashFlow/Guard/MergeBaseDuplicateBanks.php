<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Guard;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * G2 階段一：VWorld base(group 0) 同碼多 active 銀行列收斂。
 *
 * 【分類】Guard — ⚠️ 非 sync-safe 守護命令。
 *
 * ## 觸發時機
 * **執行 `cash-flow:sync` 之後**——各金流商 sync 會各自 seed base 列，同一
 * `(type, currency, code)` 的重複 active 列會再度長出來。
 * 漏跑的後果：base 污染經 `syncBanksToAgentGroup` 複製進 agent 層 ＝ drift 雙列源頭
 * （Wintoto 事故的上游成因）。
 *
 * ## 冪等性與重跑安全性
 * **冪等，重跑安全。** 配對表寫死，逐列比對 group 0／type／currency／code 與 survivor 狀態，
 * 任一不符整體攔截零寫入。預設 dry-run，`--execute` 才寫入。
 * 審計寫 `bank_dedup_logs`（`base_*` 前綴，與 `bank:dedup-active`／`drift_*` 隔離，
 * `bank:dedup-rollback` 不會誤消費）。
 *
 * ## 退役條件
 * 當 `cash-flow:sync` 改為**以 `standard_banks` 為單一來源、不再各金流商各自 seed base 列**後，
 * 重複列不會再生，本命令即可刪除。這是根治方向；在那之前只能靠本命令週期收斂。
 *
 * ## 關聯
 * - 階段二（同行不同碼）：`bank:merge-base-synonym-banks`
 * - 下游受害：`bank:merge-wintoto-drift-banks`（agent 層 drift）
 * - 上位路線：`docs/未完成計劃/RB/TODO.md` §3.1（五軌總綱）＋ §2.5（rollout 三連）
 *
 * 背景：各金流商 sync 各自 seed base 列，同一 (type, currency, code) 存在 2-3 筆 active，
 * base 污染經 syncBanksToAgentGroup 複製進 agent 層＝drift 雙列源頭（Wintoto 事故 Plan C／G2）。
 * base 列零會員綁定（會員只綁 agent 層），耦合點在 cash_flow_banks（渠道銀行 mapping）。
 *
 * 收斂（配對表寫死，survivor 取名稱合 `(CUR) - {Name}` 慣例者、次取 active cfb 引用多者）：
 * - loser 的 cash_flow_banks：survivor 無同 (cash_flow_id, currency_id) 對映 → repoint bank_id；
 *   已有 → 停用 loser 側 cfb（repoint 會撞 uk (cash_flow_id, currency_id, bank_id)，
 *   且該情況本身就是「渠道清單同銀行雙列」的既有 bug）
 * - loser bank：status=0 ＋ 標 deleted_at（防 name-matching sync 復活；不硬刪）
 * - survivor 完全不動（含名稱——階段一不改名，避免牽動 name-matching；正名屬階段二）
 *
 * 防呆（任一不符整體攔截零寫入）：逐列比對 group 0／type／currency／code、survivor≠loser、
 * survivor 未標刪；loser 會員綁定=0 且 withdrawal_orders=0（base 模板層預期恆為 0，非 0 即異常轉人工）。
 *
 * 審計：bank_dedup_logs 以 base_* 前綴 action（與 bank:dedup-active／drift_* 隔離，
 * bank:dedup-rollback 不消費）。rollback 底稿＝依審計反向重放：
 *   base_repoint_cfb    → 該 cfb（detail 內 cfb_id）bank_id 改回 loser_bank_id
 *   base_deactivate_cfb → 該 cfb status 改回 1
 *   base_deactivate_bank→ banks.status=1、deleted_at=NULL
 *
 * php artisan bank:merge-base-duplicate-banks            # dry-run（預設）
 * php artisan bank:merge-base-duplicate-banks --execute  # 實際執行（需確認）
 */
class MergeBaseDuplicateBanks extends Command
{
    public const AGENT_GROUP_ID = 0;

    /**
     * 同碼多 active 配對表（2026-07-13 盤點拍板；survivor←losers，同組同 code）。
     *
     * @var list<array{type: int, currency_id: int, code: string, survivor_id: int, loser_ids: list<int>}>
     */
    public const GROUPS = [
        // ---- MYR type=1（provider 不相交，預期全 repoint）----
        ['type' => 1, 'currency_id' => 1, 'code' => 'HSBC', 'survivor_id' => 845, 'loser_ids' => [924]],
        ['type' => 1, 'currency_id' => 1, 'code' => 'MUFG', 'survivor_id' => 1155, 'loser_ids' => [941]],
        ['type' => 1, 'currency_id' => 1, 'code' => 'MYR0013', 'survivor_id' => 1153, 'loser_ids' => [847]],
        ['type' => 1, 'currency_id' => 1, 'code' => 'RHB', 'survivor_id' => 869, 'loser_ids' => [839, 923]],
        ['type' => 1, 'currency_id' => 1, 'code' => 'RYT BANK', 'survivor_id' => 1148, 'loser_ids' => [945]],
        ['type' => 1, 'currency_id' => 1, 'code' => 'VWMY33', 'survivor_id' => 1147, 'loser_ids' => [943]],
        // ---- SGD type=4（多與 survivor 同 provider 重複，預期多為停用 cfb）----
        ['type' => 4, 'currency_id' => 2, 'code' => 'BKCHSG', 'survivor_id' => 881, 'loser_ids' => [952]],
        ['type' => 4, 'currency_id' => 2, 'code' => 'OCBCSG', 'survivor_id' => 879, 'loser_ids' => [950, 990]],
        ['type' => 4, 'currency_id' => 2, 'code' => 'SGD0001', 'survivor_id' => 876, 'loser_ids' => [991]],
        ['type' => 4, 'currency_id' => 2, 'code' => 'SGD0003', 'survivor_id' => 878, 'loser_ids' => [949]],
        ['type' => 4, 'currency_id' => 2, 'code' => 'SGD0008', 'survivor_id' => 883, 'loser_ids' => [954]],
        ['type' => 4, 'currency_id' => 2, 'code' => 'SGD0011', 'survivor_id' => 886, 'loser_ids' => [957]],
        ['type' => 4, 'currency_id' => 2, 'code' => 'SGD0012', 'survivor_id' => 887, 'loser_ids' => [958]],
        ['type' => 4, 'currency_id' => 2, 'code' => 'SGD0013', 'survivor_id' => 888, 'loser_ids' => [959]],
        ['type' => 4, 'currency_id' => 2, 'code' => 'SGD0014', 'survivor_id' => 889, 'loser_ids' => [960]],
        ['type' => 4, 'currency_id' => 2, 'code' => 'SGD0016', 'survivor_id' => 891, 'loser_ids' => [961]],
        ['type' => 4, 'currency_id' => 2, 'code' => 'SGD0018', 'survivor_id' => 893, 'loser_ids' => [963]],
        ['type' => 4, 'currency_id' => 2, 'code' => 'SGD0020', 'survivor_id' => 895, 'loser_ids' => [965]],
    ];

    protected $signature = 'bank:merge-base-duplicate-banks
                            {--execute : 實際執行收斂（預設僅 dry-run 報表）}
                            {--force : 跳過執行前確認}';

    protected $description = 'G2 階段一：base(group 0) 同碼多 active 銀行收斂（cfb repoint/停用分流；預設 dry-run）';

    public function handle(): int
    {
        $banks = $this->loadBanks();

        $errors = $this->validateGroups($banks);
        if ($errors !== []) {
            $this->error(sprintf('配對表與 DB 現況不符（%d 項），整體攔截、未寫入任何資料：', count($errors)));
            foreach ($errors as $error) {
                $this->line('  - ' . $error);
            }

            return self::FAILURE;
        }

        $plan = $this->buildPlan($banks);
        $this->displayPlan($plan);

        $actionable = array_values(array_filter(
            $plan,
            static fn (array $row): bool => $row['repoint_cfb'] > 0 || $row['deactivate_cfb'] > 0 || $row['deactivate_bank'],
        ));

        if (!$this->option('execute')) {
            $this->newLine();
            $this->info(sprintf(
                '🔍 Dry-run 模式，未寫入。待處理：repoint cfb %d、停用 cfb %d、停用銀行 %d。加 --execute 以實際執行。',
                array_sum(array_column($plan, 'repoint_cfb')),
                array_sum(array_column($plan, 'deactivate_cfb')),
                count(array_filter(array_column($plan, 'deactivate_bank'))),
            ));

            return self::SUCCESS;
        }

        if ($actionable === []) {
            $this->newLine();
            $this->info('✅ 無需變更（已全數收斂，冪等）');

            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm('確認執行 base 同碼銀行收斂？此操作將 repoint/停用 cash_flow_banks 並停用重複 base 列', false)) {
            $this->info('已取消，未寫入任何資料。');

            return self::SUCCESS;
        }

        $summary = $this->executeMerge();

        $this->newLine();
        $this->info(sprintf(
            '✅ 收斂完成：repoint cfb %d、停用 cfb %d、停用銀行 %d（審計見 bank_dedup_logs action=base_*）。',
            $summary['repointed_cfb'],
            $summary['deactivated_cfb'],
            $summary['deactivated_banks'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int, object{id: int, agent_group_id: int, type: int, currency_id: int|null, code: string, status: int, deleted_at: string|null}>
     */
    private function loadBanks(): array
    {
        $ids = [];
        foreach (self::GROUPS as $group) {
            $ids[] = $group['survivor_id'];
            foreach ($group['loser_ids'] as $loserId) {
                $ids[] = $loserId;
            }
        }

        return DB::table('banks')
            ->whereIn('id', $ids)
            ->get(['id', 'agent_group_id', 'type', 'currency_id', 'code', 'status', 'deleted_at'])
            ->keyBy('id')
            ->all();
    }

    /**
     * 逐列比對配對表與 DB 現況，回傳全部不符項（空陣列 = 通過）。
     *
     * @param  array<int, object>  $banks
     * @return list<string>
     */
    private function validateGroups(array $banks): array
    {
        $errors = [];

        foreach (self::GROUPS as $group) {
            $errors = [...$errors, ...$this->validateRow($banks, $group, $group['survivor_id'], 'survivor')];

            $survivor = $banks[$group['survivor_id']] ?? null;
            if ($survivor !== null && $survivor->deleted_at !== null) {
                $errors[] = sprintf('survivor bank_id=%d：已標記刪除', $group['survivor_id']);
            }

            foreach ($group['loser_ids'] as $loserId) {
                // 防未來改動配對表時把同列填成 survivor＋loser；現行常值下恆假，PHPStan 誤報為死碼
                if ($loserId === $group['survivor_id']) { // @phpstan-ignore identical.alwaysFalse
                    $errors[] = sprintf('group code=%s：loser 與 survivor 同列（%d）', $group['code'], $loserId);

                    continue;
                }

                $errors = [...$errors, ...$this->validateRow($banks, $group, $loserId, 'loser')];
                $errors = [...$errors, ...$this->validateLoserIsolation($loserId)];
            }
        }

        return $errors;
    }

    /**
     * @param  array<int, object>  $banks
     * @param  array{type: int, currency_id: int, code: string}  $group
     * @return list<string>
     */
    private function validateRow(array $banks, array $group, int $bankId, string $role): array
    {
        $bank = $banks[$bankId] ?? null;

        if ($bank === null) {
            return [sprintf('%s bank_id=%d：DB 無此列', $role, $bankId)];
        }

        $errors = [];

        if ((int) $bank->agent_group_id !== self::AGENT_GROUP_ID) {
            $errors[] = sprintf('%s bank_id=%d：agent_group_id=%d ≠ base(0)', $role, $bankId, (int) $bank->agent_group_id);
        }

        if ((int) $bank->type !== $group['type']) {
            $errors[] = sprintf('%s bank_id=%d：type=%d ≠ 預期 %d', $role, $bankId, (int) $bank->type, $group['type']);
        }

        if ((int) $bank->currency_id !== $group['currency_id']) {
            $errors[] = sprintf('%s bank_id=%d：currency_id=%s ≠ 預期 %d', $role, $bankId, (string) $bank->currency_id, $group['currency_id']);
        }

        if ((string) $bank->code !== $group['code']) {
            $errors[] = sprintf('%s bank_id=%d：code=%s ≠ 預期 %s', $role, $bankId, (string) $bank->code, $group['code']);
        }

        return $errors;
    }

    /**
     * base 模板層預期零會員綁定、零歷史單；非 0 即異常（配對表判讀可能過期），轉人工。
     *
     * @return list<string>
     */
    private function validateLoserIsolation(int $loserId): array
    {
        $errors = [];

        $bindings = DB::table('member_bank_accounts')->where('bank_id', $loserId)->count();
        if ($bindings > 0) {
            $errors[] = sprintf('loser bank_id=%d：存在 %d 筆會員綁定（base 預期 0），轉人工複核', $loserId, $bindings);
        }

        $orders = DB::table('withdrawal_orders')->where('bank_id', $loserId)->count();
        if ($orders > 0) {
            $errors[] = sprintf('loser bank_id=%d：存在 %d 筆出款單（base 預期 0），轉人工複核', $loserId, $orders);
        }

        return $errors;
    }

    /**
     * 逐 loser 建立處理計劃（快照統計，執行時以當下狀態為準）。
     *
     * @param  array<int, object>  $banks
     * @return list<array{code: string, survivor_id: int, loser_id: int, loser_status: int, repoint_cfb: int, deactivate_cfb: int, deactivate_bank: bool}>
     */
    private function buildPlan(array $banks): array
    {
        $plan = [];

        foreach (self::GROUPS as $group) {
            foreach ($group['loser_ids'] as $loserId) {
                [$repointable, $collidable] = $this->splitLoserCashFlowBanks($group['survivor_id'], $loserId);

                $plan[] = [
                    'code' => $group['code'],
                    'survivor_id' => $group['survivor_id'],
                    'loser_id' => $loserId,
                    'loser_status' => (int) $banks[$loserId]->status,
                    'repoint_cfb' => count($repointable),
                    'deactivate_cfb' => count(array_filter($collidable, static fn (object $cfb): bool => (int) $cfb->status === 1)),
                    'deactivate_bank' => (int) $banks[$loserId]->status === 1,
                ];
            }
        }

        return $plan;
    }

    /**
     * 將 loser 的 cfb 依「survivor 是否已有同 (cash_flow_id, currency_id) 對映」分流。
     *
     * `$lock` 只在 executeMerge 的交易內為 true（2026-08-25 自 bank:rename-bank 移植）：
     * `WHERE bank_id … FOR UPDATE` 走 `cash_flow_banks_bank_id_index`，鎖兩側的列＋索引 gap
     * ——survivor 側的 gap lock 順帶封掉「分流之後、repoint 之前有人 INSERT 同槽位新列」的
     * 空窗，repoint 不再可能撞唯一鍵。dry-run 計畫階段跑在交易外，鎖取得即釋放毫無作用，
     * 維持不加。
     *
     * @return array{0: list<object>, 1: list<object>} [可 repoint, 撞鍵（待停用）]
     */
    private function splitLoserCashFlowBanks(int $survivorId, int $loserId, bool $lock = false): array
    {
        $loserCfbs = DB::table('cash_flow_banks')
            ->where('bank_id', $loserId)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->get(['id', 'cash_flow_id', 'currency_id', 'status']);

        $survivorKeys = DB::table('cash_flow_banks')
            ->where('bank_id', $survivorId)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->get(['cash_flow_id', 'currency_id'])
            ->map(static fn (object $cfb): string => $cfb->cash_flow_id . '|' . $cfb->currency_id)
            ->flip()
            ->all();

        $repointable = [];
        $collidable = [];

        foreach ($loserCfbs as $cfb) {
            if (isset($survivorKeys[$cfb->cash_flow_id . '|' . $cfb->currency_id])) {
                $collidable[] = $cfb;
            } else {
                $repointable[] = $cfb;
            }
        }

        return [$repointable, $collidable];
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     */
    private function displayPlan(array $plan): void
    {
        $this->table(
            ['code', 'survivor', 'loser', 'loser_status', 'repoint cfb', '停用 cfb', '停用銀行'],
            array_map(static fn (array $row): array => [
                $row['code'],
                $row['survivor_id'],
                $row['loser_id'],
                $row['loser_status'],
                $row['repoint_cfb'],
                $row['deactivate_cfb'],
                $row['deactivate_bank'] ? 'Y' : '-',
            ], $plan),
        );
    }

    /**
     * 執行收斂（單一交易）：逐 loser 先分流處理 cfb、再停用並標刪 loser 銀行列。
     *
     * @return array{repointed_cfb: int, deactivated_cfb: int, deactivated_banks: int}
     */
    private function executeMerge(): array
    {
        $summary = ['repointed_cfb' => 0, 'deactivated_cfb' => 0, 'deactivated_banks' => 0];
        $connection = (string) config('database.default');

        DB::transaction(function () use ($connection, &$summary): void {
            foreach (self::GROUPS as $group) {
                foreach ($group['loser_ids'] as $loserId) {
                    [$repointable, $collidable] = $this->splitLoserCashFlowBanks($group['survivor_id'], $loserId, lock: true);

                    foreach ($repointable as $cfb) {
                        DB::table('cash_flow_banks')->where('id', $cfb->id)->update(['bank_id' => $group['survivor_id']]);
                        $this->writeAudit($connection, $group, $loserId, 'base_repoint_cfb', sprintf('cfb_id=%d cash_flow_id=%d', (int) $cfb->id, (int) $cfb->cash_flow_id));
                        $summary['repointed_cfb']++;
                    }

                    foreach ($collidable as $cfb) {
                        if ((int) $cfb->status !== 1) {
                            continue;
                        }

                        DB::table('cash_flow_banks')->where('id', $cfb->id)->update(['status' => 0]);
                        $this->writeAudit($connection, $group, $loserId, 'base_deactivate_cfb', sprintf('cfb_id=%d cash_flow_id=%d', (int) $cfb->id, (int) $cfb->cash_flow_id));
                        $summary['deactivated_cfb']++;
                    }

                    $deactivated = DB::table('banks')
                        ->where('id', $loserId)
                        ->where('status', 1)
                        ->update(['status' => 0, 'deleted_at' => now()]);

                    if ($deactivated > 0) {
                        $this->writeAudit($connection, $group, $loserId, 'base_deactivate_bank', 'g2_phase1');
                        $summary['deactivated_banks']++;
                    }
                }
            }
        });

        return $summary;
    }

    /**
     * @param  array{code: string, survivor_id: int}  $group
     */
    private function writeAudit(string $connection, array $group, int $loserId, string $action, string $detail): void
    {
        DB::table('bank_dedup_logs')->insert([
            'connection' => $connection,
            'agent_group_id' => self::AGENT_GROUP_ID,
            'code' => $group['code'],
            'survivor_bank_id' => $group['survivor_id'],
            'loser_bank_id' => $loserId,
            'member_id' => null,
            'member_bank_account_id' => null,
            'action' => $action,
            'detail' => $detail,
        ]);
    }
}
