<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Guard;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * base 模板收斂第二階段（安全子集）：VWorld base(group 0) 同行不同碼收斂＋停用列孤兒 cfb repoint。
 *
 * 【分類】Guard — ⚠️ 非 sync-safe 守護命令。
 *
 * ## 觸發時機
 * **執行 `cash-flow:sync` 之後**，且建議與階段一 `bank:merge-base-duplicate-banks` 成對執行
 * （階段一收「同碼多列」、階段二收「同行不同碼」與孤兒 cfb）。
 *
 * ## 冪等性與重跑安全性
 * **冪等，重跑安全。** 單一交易；PAIRS／ORPHANS 逐列防呆（含 BINARY 語意的 code 比對、
 * dead 列必須為停用中），任一不符整體攔截零寫入。預設 dry-run，`--execute` 才寫入。
 * 審計寫 `bank_dedup_logs`（`syn_*` 前綴，與其他命令隔離）。
 *
 * ## 退役條件
 * 需要**同時**達成：
 * 1. 與階段一相同——`cash-flow:sync` 改以 `standard_banks` 為單一來源；且
 * 2. 目前刻意排除的範疇（SGD 6 對＋MYR HSBC／Agro 2 對、target 碼對齊）由 `bank:rename-bank`
 *    在 agent 層統一完畢——那些對子的前置是 agent 層先有單一正確碼，不屬本命令。
 *
 * ## 關聯
 * - 階段一：`bank:merge-base-duplicate-banks`
 * - 被排除範疇由 `bank:rename-bank`（`RenameBankCommand`）在 agent 層逐組收斂：同一 group 內以 survivor
 *   既有的碼為準，把 loser 的引用與同 group cfb.bank_code 收斂過去並停用 loser，不在本命令。
 * - ⚠️ cfb.bank_code 契約（本命令一律不改）：見下方「範疇邊界」
 *
 * 背景（承階段一 bank:merge-base-duplicate-banks）：各金流商各自 seed base 列造成
 * 同一銀行以不同 code 存在多筆 active（IDR/VND 兩波、SGD legacy↔SGD00xx、MYR 家族碼），
 * 另有停用 base 列上殘留 active cash_flow_banks（孤兒）。
 *
 * ⚠️ 範疇邊界（2026-07-14 拍板，縮自全量 27 對）：
 * - cfb.bank_code 的功能契約是「對齊服務中 agent group 的 agent 層碼」（by-channel 重建以
 *   $agent->cash_flow_group_id 查 banks、代付 runtime 以會員 agent 層碼查 cfb），不是 base 碼——
 *   故本命令一律**不改 cfb.bank_code**（生產實證：cfb 438 SGD0002 正名成 base 碼 CIMBSG 會斷
 *   g99 CIMB 代付、cfb 587 WTMY91 正名成 TPMY21 會斷 g6 綁 WTMY91 會員）。
 * - SGD 6 對＋MYR 2 對（HSBC 845/873、Agro 854/1152）兩套碼在 g99/g93/g6/g70 agent 層各有
 *   活躍使用、base 不存在單一正確碼，收斂前置＝agent 層統一（`bank:rename-bank`＋各 group dedup），
 *   不在本命令；target 碼對齊同理轉 group-aware 裁決。
 * - 本命令僅處理 agent 層無人使用的 IDR 12 對＋VND 7 對，與孤兒 16 組。
 *
 * 處理（單一交易）：
 * - S1 同行不同碼雙 active 收斂（PAIRS）：loser 的 cfb 依撞鍵分流——可 repoint 者只改
 *   bank_id（純引用修正，code/status 不動）；撞鍵者若該渠道零 active ACF（休眠）則停用、
 *   活躍渠道則留置不動（報表列出）。loser bank status=0＋deleted_at。
 * - S2 孤兒 cfb repoint（ORPHANS，dead=停用列、target=同行 active 列）：同上分流；
 *   dead 列補標 deleted_at（防 name-matching sync 復活）。
 *
 * 防呆（任一不符整體攔截零寫入）：逐列比對 group 0／type／currency／兩側 code（BINARY 語意）、
 * survivor/target active 未標刪、dead 必須停用中（復活＝性質改變轉人工）；
 * loser/dead 會員綁定=0 且 withdrawal_orders=0（base 模板層預期恆 0）。
 *
 * 審計：bank_dedup_logs 以 syn_* 前綴 action（與 dedup／drift_*／base_* 隔離，
 * bank:dedup-rollback 不消費）。rollback 底稿＝依審計反向重放：
 *   syn_repoint_cfb      → 該 cfb（detail 內 cfb_id）bank_id 改回 loser_bank_id
 *   syn_deactivate_cfb   → 該 cfb status 改回 1
 *   syn_deactivate_bank  → banks.status=1、deleted_at=NULL
 *   syn_soft_delete_bank → banks.deleted_at=NULL（status 維持 0）
 *
 * php artisan bank:merge-base-synonym-banks            # dry-run（預設）
 * php artisan bank:merge-base-synonym-banks --execute  # 實際執行（需確認）
 */
class MergeBaseSynonymBanks extends Command
{
    public const AGENT_GROUP_ID = 0;

    /**
     * S1 同行不同碼雙 active 配對表（2026-07-13 盤點、2026-07-14 縮至安全子集：
     * IDR 留低號第一波、VND 留活躍 PayEssence 引用且名稱合慣例的高號波；
     * 兩幣別在本連線 agent 層均無活躍使用、FPay cf68 零 ACF 休眠）。
     *
     * @var list<array{type: int, currency_id: int, survivor_id: int, survivor_code: string, loser_id: int, loser_code: string}>
     */
    public const PAIRS = [
        // ---- VND type=4（survivor=高號波）----
        ['type' => 4, 'currency_id' => 3, 'survivor_id' => 1156, 'survivor_code' => 'VND0039', 'loser_id' => 663, 'loser_code' => 'VND0004'],
        ['type' => 4, 'currency_id' => 3, 'survivor_id' => 1159, 'survivor_code' => 'VND0042', 'loser_id' => 664, 'loser_code' => 'VND0005'],
        ['type' => 4, 'currency_id' => 3, 'survivor_id' => 1160, 'survivor_code' => 'VND0043', 'loser_id' => 667, 'loser_code' => 'VND0008'],
        ['type' => 4, 'currency_id' => 3, 'survivor_id' => 1162, 'survivor_code' => 'VND0045', 'loser_id' => 689, 'loser_code' => 'VND0030'],
        ['type' => 4, 'currency_id' => 3, 'survivor_id' => 1163, 'survivor_code' => 'VND0046', 'loser_id' => 661, 'loser_code' => 'VND0002'],
        ['type' => 4, 'currency_id' => 3, 'survivor_id' => 1165, 'survivor_code' => 'VND0048', 'loser_id' => 660, 'loser_code' => 'VND0001'],
        ['type' => 4, 'currency_id' => 3, 'survivor_id' => 1167, 'survivor_code' => 'TPVN08', 'loser_id' => 662, 'loser_code' => 'VND0003'],
        // ---- IDR type=4（survivor=低號第一波；FPay cf68 休眠，三方碼留 survivor 值，
        //      將來開通 FPay 前須依其 /wallet/withdraw_bank_list API 校正 provider_bank_code）----
        ['type' => 4, 'currency_id' => 6, 'survivor_id' => 699, 'survivor_code' => 'IDR0002', 'loser_id' => 725, 'loser_code' => 'IDR0028'],
        ['type' => 4, 'currency_id' => 6, 'survivor_id' => 701, 'survivor_code' => 'IDR0004', 'loser_id' => 723, 'loser_code' => 'IDR0026'],
        ['type' => 4, 'currency_id' => 6, 'survivor_id' => 704, 'survivor_code' => 'IDR0007', 'loser_id' => 794, 'loser_code' => 'IDR0097'],
        ['type' => 4, 'currency_id' => 6, 'survivor_id' => 708, 'survivor_code' => 'IDR0011', 'loser_id' => 792, 'loser_code' => 'IDR0095'],
        ['type' => 4, 'currency_id' => 6, 'survivor_id' => 710, 'survivor_code' => 'IDR0013', 'loser_id' => 729, 'loser_code' => 'IDR0032'],
        ['type' => 4, 'currency_id' => 6, 'survivor_id' => 711, 'survivor_code' => 'IDR0014', 'loser_id' => 730, 'loser_code' => 'IDR0033'],
        ['type' => 4, 'currency_id' => 6, 'survivor_id' => 713, 'survivor_code' => 'IDR0016', 'loser_id' => 828, 'loser_code' => 'IDR0131'],
        ['type' => 4, 'currency_id' => 6, 'survivor_id' => 714, 'survivor_code' => 'IDR0017', 'loser_id' => 746, 'loser_code' => 'IDR0049'],
        ['type' => 4, 'currency_id' => 6, 'survivor_id' => 715, 'survivor_code' => 'IDR0018', 'loser_id' => 737, 'loser_code' => 'IDR0040'],
        ['type' => 4, 'currency_id' => 6, 'survivor_id' => 716, 'survivor_code' => 'IDR0019', 'loser_id' => 799, 'loser_code' => 'IDR0102'],
        ['type' => 4, 'currency_id' => 6, 'survivor_id' => 717, 'survivor_code' => 'IDR0020', 'loser_id' => 800, 'loser_code' => 'IDR0103'],
        ['type' => 4, 'currency_id' => 6, 'survivor_id' => 720, 'survivor_code' => 'IDR0023', 'loser_id' => 814, 'loser_code' => 'IDR0117'],
    ];

    /**
     * S2 停用列孤兒配對表（dead=停用 base 列、target=同行唯一 active 列；
     * 涉及渠道：AllPay cf23（活躍）、MajesticPay cf72／FPay cf68（休眠））。
     *
     * @var list<array{type: int, currency_id: int, dead_id: int, dead_code: string, target_id: int, target_code: string}>
     */
    public const ORPHANS = [
        // ---- AllPay cf23（活躍渠道；cfb 僅 repoint bank_id，code 不動故零行為影響）----
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 921, 'dead_code' => 'MayBank', 'target_id' => 835, 'target_code' => 'MayBank'],
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 922, 'dead_code' => 'CIMB', 'target_id' => 836, 'target_code' => 'CIMB'],
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 925, 'dead_code' => 'BIMB', 'target_id' => 842, 'target_code' => 'TPMY07'],
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 926, 'dead_code' => 'AFIN', 'target_id' => 848, 'target_code' => 'TPMY08'],
        // ---- MajesticPay cf72（休眠渠道，零 ACF）----
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 863, 'dead_code' => 'MYR0021', 'target_id' => 840, 'target_code' => 'MYAB'],
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 864, 'dead_code' => 'MYR0022', 'target_id' => 846, 'target_code' => 'ABMB'],
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 865, 'dead_code' => 'MYR0023', 'target_id' => 836, 'target_code' => 'CIMB'],
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 866, 'dead_code' => 'MYR0024', 'target_id' => 837, 'target_code' => 'HongLeongBank'],
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 867, 'dead_code' => 'MayBank', 'target_id' => 835, 'target_code' => 'MayBank'],
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 868, 'dead_code' => 'MYR0026', 'target_id' => 838, 'target_code' => 'PublicBank'],
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 870, 'dead_code' => 'MYR0028', 'target_id' => 848, 'target_code' => 'TPMY08'],
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 871, 'dead_code' => 'MYR0029', 'target_id' => 842, 'target_code' => 'TPMY07'],
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 872, 'dead_code' => 'MYR0030', 'target_id' => 852, 'target_code' => 'MUAM'],
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 874, 'dead_code' => 'MYR0032', 'target_id' => 853, 'target_code' => 'OCBC'],
        ['type' => 1, 'currency_id' => 1, 'dead_id' => 875, 'dead_code' => 'TPMY13', 'target_id' => 844, 'target_code' => 'UNOB'],
        // ---- FPay cf68（休眠渠道，零 ACF）----
        ['type' => 4, 'currency_id' => 6, 'dead_id' => 770, 'dead_code' => 'IDR0073', 'target_id' => 718, 'target_code' => 'IDR0021'],
    ];

    protected $signature = 'bank:merge-base-synonym-banks
                            {--execute : 實際執行收斂（預設僅 dry-run 報表）}
                            {--force : 跳過執行前確認}';

    protected $description = 'base 模板收斂第二階段安全子集：base(group 0) IDR/VND 同行不同碼收斂＋孤兒 cfb repoint（不改 cfb.bank_code；預設 dry-run）';

    public function handle(): int
    {
        $banks = $this->loadBanks();

        $errors = $this->validate($banks);
        if ($errors !== []) {
            $this->error(sprintf('配對表與 DB 現況不符（%d 項），整體攔截、未寫入任何資料：', count($errors)));
            foreach ($errors as $error) {
                $this->line('  - ' . $error);
            }

            return self::FAILURE;
        }

        $pairPlan = $this->buildMergePlan(self::PAIRS, 'survivor_id', 'loser_id', $banks);
        $orphanPlan = $this->buildMergePlan(self::ORPHANS, 'target_id', 'dead_id', $banks);

        $this->line('S1 同行不同碼雙 active 收斂（IDR/VND 安全子集）：');
        $this->displayMergePlan($pairPlan);
        $this->line('S2 停用列孤兒 cfb repoint：');
        $this->displayMergePlan($orphanPlan);

        $pending = 0;
        foreach ([...$pairPlan, ...$orphanPlan] as $row) {
            $pending += $row['repoint_cfb'] + $row['deactivate_cfb']
                + (int) $row['deactivate_bank'] + (int) $row['soft_delete_bank'];
        }

        $keptTotal = array_sum(array_column($pairPlan, 'keep_cfb')) + array_sum(array_column($orphanPlan, 'keep_cfb'));
        if ($keptTotal > 0) {
            $this->warn(sprintf('⚠️ %d 筆撞鍵 cfb 位於活躍渠道，留置不動（收斂前置＝agent 層統一，見 rename 軌）。', $keptTotal));
        }

        if (!$this->option('execute')) {
            $this->newLine();
            $this->info(sprintf(
                '🔍 Dry-run 模式，未寫入。待處理動作合計 %d（repoint cfb %d、停用 cfb %d、停用銀行 %d、補標刪 %d；留置 %d）。加 --execute 以實際執行。',
                $pending,
                array_sum(array_column($pairPlan, 'repoint_cfb')) + array_sum(array_column($orphanPlan, 'repoint_cfb')),
                array_sum(array_column($pairPlan, 'deactivate_cfb')) + array_sum(array_column($orphanPlan, 'deactivate_cfb')),
                count(array_filter(array_column($pairPlan, 'deactivate_bank'))) + count(array_filter(array_column($orphanPlan, 'deactivate_bank'))),
                count(array_filter(array_column($pairPlan, 'soft_delete_bank'))) + count(array_filter(array_column($orphanPlan, 'soft_delete_bank'))),
                $keptTotal,
            ));

            return self::SUCCESS;
        }

        if ($pending === 0) {
            $this->newLine();
            $this->info('✅ 無需變更（已全數收斂，冪等）');

            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm('確認執行 base 同行不同碼收斂（IDR/VND 安全子集）？此操作將 repoint/停用 cash_flow_banks 並停用/標刪 base 列', false)) {
            $this->info('已取消，未寫入任何資料。');

            return self::SUCCESS;
        }

        $summary = $this->executeAll();

        $this->newLine();
        $this->info(sprintf(
            '✅ 收斂完成：repoint cfb %d、停用 cfb %d、停用銀行 %d、補標刪 %d、留置 %d（審計見 bank_dedup_logs action=syn_*）。',
            $summary['repointed_cfb'],
            $summary['deactivated_cfb'],
            $summary['deactivated_banks'],
            $summary['soft_deleted_banks'],
            $summary['kept_cfb'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int, object{id: int, agent_group_id: int, type: int, currency_id: int|null, code: string, status: int, deleted_at: string|null}>
     */
    private function loadBanks(): array
    {
        $ids = [];
        foreach (self::PAIRS as $pair) {
            $ids[] = $pair['survivor_id'];
            $ids[] = $pair['loser_id'];
        }
        foreach (self::ORPHANS as $orphan) {
            $ids[] = $orphan['dead_id'];
            $ids[] = $orphan['target_id'];
        }

        return DB::table('banks')
            ->whereIn('id', array_values(array_unique($ids)))
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
    private function validate(array $banks): array
    {
        $errors = [];

        foreach (self::PAIRS as $pair) {
            // 防未來改動配對表時把同列填成 survivor＋loser；現行常值下恆假，PHPStan 誤報為死碼
            if ($pair['loser_id'] === $pair['survivor_id']) { // @phpstan-ignore identical.alwaysFalse
                $errors[] = sprintf('pair survivor=%d：loser 與 survivor 同列', $pair['survivor_id']);

                continue;
            }

            $errors = [...$errors, ...$this->validateRow($banks, $pair['survivor_id'], $pair['type'], $pair['currency_id'], $pair['survivor_code'], 'survivor')];
            $errors = [...$errors, ...$this->validateActiveKeeper($banks, $pair['survivor_id'], 'survivor')];
            $errors = [...$errors, ...$this->validateRow($banks, $pair['loser_id'], $pair['type'], $pair['currency_id'], $pair['loser_code'], 'loser')];
            $errors = [...$errors, ...$this->validateIsolation($pair['loser_id'], 'loser')];
        }

        foreach (self::ORPHANS as $orphan) {
            if ($orphan['dead_id'] === $orphan['target_id']) { // @phpstan-ignore identical.alwaysFalse
                $errors[] = sprintf('orphan target=%d：dead 與 target 同列', $orphan['target_id']);

                continue;
            }

            $errors = [...$errors, ...$this->validateRow($banks, $orphan['target_id'], $orphan['type'], $orphan['currency_id'], $orphan['target_code'], 'target')];
            $errors = [...$errors, ...$this->validateActiveKeeper($banks, $orphan['target_id'], 'target')];
            $errors = [...$errors, ...$this->validateRow($banks, $orphan['dead_id'], $orphan['type'], $orphan['currency_id'], $orphan['dead_code'], 'dead')];
            $errors = [...$errors, ...$this->validateIsolation($orphan['dead_id'], 'dead')];

            $dead = $banks[$orphan['dead_id']] ?? null;
            if ($dead !== null && (int) $dead->status !== 0) {
                $errors[] = sprintf('dead bank_id=%d：已被復活（status=%d ≠ 0），性質改變、轉人工複核', $orphan['dead_id'], (int) $dead->status);
            }
        }

        return $errors;
    }

    /**
     * @param  array<int, object>  $banks
     * @return list<string>
     */
    private function validateRow(array $banks, int $bankId, int $type, int $currencyId, string $code, string $role): array
    {
        $bank = $banks[$bankId] ?? null;

        if ($bank === null) {
            return [sprintf('%s bank_id=%d：DB 無此列', $role, $bankId)];
        }

        $errors = [];

        if ((int) $bank->agent_group_id !== self::AGENT_GROUP_ID) {
            $errors[] = sprintf('%s bank_id=%d：agent_group_id=%d ≠ base(0)', $role, $bankId, (int) $bank->agent_group_id);
        }

        if ((int) $bank->type !== $type) {
            $errors[] = sprintf('%s bank_id=%d：type=%d ≠ 預期 %d', $role, $bankId, (int) $bank->type, $type);
        }

        if ((int) $bank->currency_id !== $currencyId) {
            $errors[] = sprintf('%s bank_id=%d：currency_id=%s ≠ 預期 %d', $role, $bankId, (string) $bank->currency_id, $currencyId);
        }

        if ((string) $bank->code !== $code) {
            $errors[] = sprintf('%s bank_id=%d：code=%s ≠ 預期 %s', $role, $bankId, (string) $bank->code, $code);
        }

        return $errors;
    }

    /**
     * survivor／target 必須 active 且未標刪（收斂後對外承接的一側）。
     *
     * @param  array<int, object>  $banks
     * @return list<string>
     */
    private function validateActiveKeeper(array $banks, int $bankId, string $role): array
    {
        $bank = $banks[$bankId] ?? null;
        if ($bank === null) {
            return [];
        }

        $errors = [];

        if ((int) $bank->status !== 1) {
            $errors[] = sprintf('%s bank_id=%d：status=%d ≠ active', $role, $bankId, (int) $bank->status);
        }

        if ($bank->deleted_at !== null) {
            $errors[] = sprintf('%s bank_id=%d：已標記刪除', $role, $bankId);
        }

        return $errors;
    }

    /**
     * base 模板層預期零會員綁定、零歷史單；非 0 即異常（配對表判讀可能過期），轉人工。
     *
     * @return list<string>
     */
    private function validateIsolation(int $bankId, string $role): array
    {
        $errors = [];

        $bindings = DB::table('member_bank_accounts')->where('bank_id', $bankId)->count();
        if ($bindings > 0) {
            $errors[] = sprintf('%s bank_id=%d：存在 %d 筆會員綁定（base 預期 0），轉人工複核', $role, $bankId, $bindings);
        }

        $orders = DB::table('withdrawal_orders')->where('bank_id', $bankId)->count();
        if ($orders > 0) {
            $errors[] = sprintf('%s bank_id=%d：存在 %d 筆出款單（base 預期 0），轉人工複核', $role, $bankId, $orders);
        }

        return $errors;
    }

    /**
     * 逐組建立處理計劃（快照統計，執行時以當下狀態為準）。
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, object>  $banks
     * @return list<array{keeper_id: int, goner_id: int, goner_status: int, repoint_cfb: int, deactivate_cfb: int, keep_cfb: int, deactivate_bank: bool, soft_delete_bank: bool}>
     */
    private function buildMergePlan(array $rows, string $keeperKey, string $gonerKey, array $banks): array
    {
        $plan = [];

        foreach ($rows as $row) {
            $keeperId = (int) $row[$keeperKey];
            $gonerId = (int) $row[$gonerKey];
            [$repointable, $deactivatable, $kept] = $this->splitGonerCashFlowBanks($keeperId, $gonerId);

            $goner = $banks[$gonerId];

            $plan[] = [
                'keeper_id' => $keeperId,
                'goner_id' => $gonerId,
                'goner_status' => (int) $goner->status,
                'repoint_cfb' => count($repointable),
                'deactivate_cfb' => count($deactivatable),
                'keep_cfb' => count($kept),
                'deactivate_bank' => (int) $goner->status === 1,
                'soft_delete_bank' => $goner->deleted_at === null && (int) $goner->status !== 1,
            ];
        }

        return $plan;
    }

    /**
     * 將 goner（loser／dead）的 cfb 三分流：
     * - keeper 無同 (cash_flow_id, currency_id) 對映 → 可 repoint（只改 bank_id）
     * - 撞鍵且該渠道零 active ACF（休眠）→ 停用（active 者）
     * - 撞鍵且渠道活躍 → 留置不動（該 cfb 的 bank_code 可能正在承接會員比對）
     *
     * `$lock` 只在 executeMerge 的交易內為 true（2026-08-25 自 bank:rename-bank 移植）：
     * `WHERE bank_id … FOR UPDATE` 走 `cash_flow_banks_bank_id_index`，鎖兩側的列＋索引 gap
     * ——keeper 側的 gap lock 順帶封掉「分流之後、repoint 之前有人 INSERT 同槽位新列」的
     * 空窗，repoint 不再可能撞唯一鍵。dry-run 計畫階段跑在交易外，鎖取得即釋放毫無作用，
     * 維持不加。
     *
     * @return array{0: list<object>, 1: list<object>, 2: list<object>} [repoint, 停用, 留置]
     */
    private function splitGonerCashFlowBanks(int $keeperId, int $gonerId, bool $lock = false): array
    {
        $gonerCfbs = DB::table('cash_flow_banks')
            ->where('bank_id', $gonerId)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->get(['id', 'cash_flow_id', 'currency_id', 'status']);

        $keeperKeys = DB::table('cash_flow_banks')
            ->where('bank_id', $keeperId)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->get(['cash_flow_id', 'currency_id'])
            ->map(static fn (object $cfb): string => $cfb->cash_flow_id . '|' . $cfb->currency_id)
            ->flip()
            ->all();

        $repointable = [];
        $deactivatable = [];
        $kept = [];

        foreach ($gonerCfbs as $cfb) {
            if (!isset($keeperKeys[$cfb->cash_flow_id . '|' . $cfb->currency_id])) {
                $repointable[] = $cfb;

                continue;
            }

            if ((int) $cfb->status !== 1) {
                continue; // 撞鍵且已停用：無需處理
            }

            if ($this->cashFlowHasActiveAcf((int) $cfb->cash_flow_id, $lock)) {
                $kept[] = $cfb;
            } else {
                $deactivatable[] = $cfb;
            }
        }

        return [$repointable, $deactivatable, $kept];
    }

    /**
     * 「休眠 ⇒ 停用」的分類依據也要在鎖內定格：不鎖的話，判定後有人把該渠道的 ACF
     * status 0→1，這邊仍按舊判定把 cfb 停用——活渠道的碼表列就這樣消失。
     * 鎖住該渠道全部 ACF 列（不分 status，翻動 status 正是要擋的操作），單一渠道列數個位數。
     */
    private function cashFlowHasActiveAcf(int $cashFlowId, bool $lock = false): bool
    {
        return DB::table('agent_cash_flows')
            ->where('cash_flow_id', $cashFlowId)
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->get(['status'])
            ->contains(static fn (object $acf): bool => (int) $acf->status === 1);
    }

    /**
     * 執行收斂（單一交易）。
     *
     * @return array{repointed_cfb: int, deactivated_cfb: int, deactivated_banks: int, soft_deleted_banks: int, kept_cfb: int}
     */
    private function executeAll(): array
    {
        $summary = [
            'repointed_cfb' => 0,
            'deactivated_cfb' => 0,
            'deactivated_banks' => 0,
            'soft_deleted_banks' => 0,
            'kept_cfb' => 0,
        ];
        $connection = (string) config('database.default');

        DB::transaction(function () use ($connection, &$summary): void {
            foreach (self::PAIRS as $pair) {
                $this->mergeGonerIntoKeeper(
                    connection: $connection,
                    keeperId: $pair['survivor_id'],
                    keeperCode: $pair['survivor_code'],
                    gonerId: $pair['loser_id'],
                    summary: $summary,
                );
            }

            foreach (self::ORPHANS as $orphan) {
                $this->mergeGonerIntoKeeper(
                    connection: $connection,
                    keeperId: $orphan['target_id'],
                    keeperCode: $orphan['target_code'],
                    gonerId: $orphan['dead_id'],
                    summary: $summary,
                );
            }
        });

        return $summary;
    }

    /**
     * 單一 goner（loser／dead）併入 keeper：cfb 三分流（repoint 只改 bank_id、休眠撞鍵停用、
     * 活躍撞鍵留置），goner bank active 則停用＋標刪、已停用未標刪則補標刪。
     *
     * @param  array{repointed_cfb: int, deactivated_cfb: int, deactivated_banks: int, soft_deleted_banks: int, kept_cfb: int}  $summary
     */
    private function mergeGonerIntoKeeper(string $connection, int $keeperId, string $keeperCode, int $gonerId, array &$summary): void
    {
        [$repointable, $deactivatable, $kept] = $this->splitGonerCashFlowBanks($keeperId, $gonerId, lock: true);

        foreach ($repointable as $cfb) {
            DB::table('cash_flow_banks')->where('id', $cfb->id)->update(['bank_id' => $keeperId]);
            $this->writeAudit(
                connection: $connection,
                code: $keeperCode,
                survivorBankId: $keeperId,
                loserBankId: $gonerId,
                action: 'syn_repoint_cfb',
                detail: sprintf('cfb_id=%d cash_flow_id=%d bank_code 不動', (int) $cfb->id, (int) $cfb->cash_flow_id),
            );
            $summary['repointed_cfb']++;
        }

        foreach ($deactivatable as $cfb) {
            DB::table('cash_flow_banks')->where('id', $cfb->id)->update(['status' => 0]);
            $this->writeAudit(
                connection: $connection,
                code: $keeperCode,
                survivorBankId: $keeperId,
                loserBankId: $gonerId,
                action: 'syn_deactivate_cfb',
                detail: sprintf('cfb_id=%d cash_flow_id=%d 休眠渠道', (int) $cfb->id, (int) $cfb->cash_flow_id),
            );
            $summary['deactivated_cfb']++;
        }

        $summary['kept_cfb'] += count($kept);

        $deactivated = DB::table('banks')
            ->where('id', $gonerId)
            ->where('status', 1)
            ->update(['status' => 0, 'deleted_at' => now()]);

        if ($deactivated > 0) {
            $this->writeAudit($connection, $keeperCode, $keeperId, $gonerId, 'syn_deactivate_bank', 'g2_phase2');
            $summary['deactivated_banks']++;

            return;
        }

        $softDeleted = DB::table('banks')
            ->where('id', $gonerId)
            ->where('status', 0)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        if ($softDeleted > 0) {
            $this->writeAudit($connection, $keeperCode, $keeperId, $gonerId, 'syn_soft_delete_bank', 'g2_phase2');
            $summary['soft_deleted_banks']++;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     */
    private function displayMergePlan(array $plan): void
    {
        $this->table(
            ['keeper', 'goner', 'goner_status', 'repoint cfb', '停用 cfb', '留置 cfb', '停用銀行', '補標刪'],
            array_map(static fn (array $row): array => [
                $row['keeper_id'],
                $row['goner_id'],
                $row['goner_status'],
                $row['repoint_cfb'],
                $row['deactivate_cfb'],
                $row['keep_cfb'],
                $row['deactivate_bank'] ? 'Y' : '-',
                $row['soft_delete_bank'] ? 'Y' : '-',
            ], $plan),
        );
    }

    private function writeAudit(string $connection, string $code, int $survivorBankId, int $loserBankId, string $action, string $detail): void
    {
        DB::table('bank_dedup_logs')->insert([
            'connection' => $connection,
            'agent_group_id' => self::AGENT_GROUP_ID,
            'code' => $code,
            'survivor_bank_id' => $survivorBankId,
            'loser_bank_id' => $loserBankId,
            'member_id' => null,
            'member_bank_account_id' => null,
            'action' => $action,
            'detail' => $detail,
        ]);
    }
}
