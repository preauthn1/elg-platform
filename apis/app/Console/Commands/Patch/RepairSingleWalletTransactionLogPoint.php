<?php

declare(strict_types=1);

namespace App\Console\Commands\Patch;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Helpers\Common;
use App\Models\TransactionLog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 回補單錢包現金紀錄（transaction_logs，type=27）的 point / vendor_point。
 *
 * 背景：單錢包同一遊戲商同一 session 只維持一筆進行中（status=999）紀錄，每次投注／派彩把金額
 * 「讀出 point ＋ 本次金額寫回」。在 !2166／!2152 修正前，讀出的 point 是等會員鎖之前的快照，
 * 併發下會蓋掉對手剛寫入的金額，point 因此與錢包實際變動對不上（target/tickets#4294、#4297）。
 * before_balance（建檔時鎖定後的錢包）與 balance（最後一次合併時鎖定後的錢包）都不經過那條過期讀，
 * 所以 type=27 的 point 恆等式 `point = before_balance − balance`（下注為正、派彩為負）可以用來回算。
 *
 * 規則：
 *  - 只處理 type=27（SEAMLESS_WALLET_BET_SETTLE）且 status=1000（已結算）的列；999 還在累加中不動。
 *  - 只處理 `ROUND(before_balance − balance, 4) <> ROUND(point, 4)` 的列。
 *  - **排除 session 中間夾了非遊戲異動的列**：儲值／提款／補點不會關掉進行中的遊戲紀錄，若之後又有派彩
 *    併進來，balance 就含了那筆異動，恆等式不成立。判法：同會員在 [created_at, updated_at] 內有
 *    type≠27 的紀錄，且**最早那筆的 before_balance ≠ 本列 balance**（代表它之後還有遊戲合併進來）→ 跳過，
 *    列進「需人工」清單；若相等代表非遊戲異動發生在最後一次合併之後、沒污染 balance，可回補。
 *  - vendor_point 一併重算為 point × rate；走 DB decimal 運算（精確，欄位皆 decimal(18,4)）。
 *  - 每筆變更的舊值／新值都落 log，供事後對照。
 *
 * 口徑裁決（Tim，2026-09-10）：TPower 2026-06～08 有一批 BWG（vendor 145）禮碼下注的 session，point 寫的是
 * 「總投注 − 派彩」（含禮碼支付的部分，差額為禮碼包 5 的整數倍，約 1.2 萬筆），不是現金變動。point 的定義
 * 一律是現金變動，這批一併以 before_balance − balance 回補；禮碼支付的輸贏由 rolling_chip_logs／SR21 保留。
 *
 * 用法（依白標注入 DB_CONNECTION，先 dry-run 看三個數字，再 --execute）：
 *   php artisan patch:repair-single-wallet-transaction-log-point --months=3
 *   php artisan patch:repair-single-wallet-transaction-log-point --months=3 --vendor=101
 *   php artisan patch:repair-single-wallet-transaction-log-point --months=3 --execute --reason="target/tickets#4294 回補"
 */
class RepairSingleWalletTransactionLogPoint extends Command
{
    private const string CMD_NAME = 'patch:repair-single-wallet-transaction-log-point';

    protected $signature = self::CMD_NAME . '
        {--months=3 : 回溯幾個月（以 created_at 計）}
        {--vendor= : 只處理指定 vendor_id（預設全部單錢包遊戲商）}
        {--chunk=20000 : 每批掃描的 id 區間大小（同時作為寫入批次上限）}
        {--execute : 實際寫入（不加為 dry-run）}
        {--reason= : 執行原因（execute 必填）}
        {--detail-limit=20 : 明細表格顯示筆數上限（0 = 全列）}';

    protected $description = '回補單錢包現金紀錄的 point / vendor_point（= before_balance − balance），跳過 session 中夾有非遊戲異動的列';

    public function handle(): int
    {
        $months = (int) $this->option('months');
        $vendorId = $this->option('vendor');
        $vendorId = ($vendorId === null || $vendorId === '') ? null : (int) $vendorId;
        $chunk = max(1, (int) $this->option('chunk'));
        $execute = (bool) $this->option('execute');
        $reason = trim((string) $this->option('reason'));
        $detailLimit = (int) $this->option('detail-limit');

        if ($months < 1) {
            $this->error('--months 必須是正整數');

            return self::INVALID;
        }
        if ($execute && $reason === '') {
            $this->error('--execute 模式必填 --reason="..."');

            return self::FAILURE;
        }

        $since = Carbon::now()->subMonths($months)->startOfDay();

        $this->info('====================================');
        $this->info('單錢包現金紀錄 point 回補');
        $this->info('====================================');
        $this->info('連線：' . DB::connection()->getName());
        $this->info(sprintf('範圍：created_at >= %s（UTC）%s', $since->toDateTimeString(), $vendorId !== null ? "、vendor_id={$vendorId}" : '、全部遊戲商'));
        $this->info('模式：' . ($execute ? 'EXECUTE（實際寫入）' : 'DRY-RUN'));
        $this->newLine();

        $repairable = [];   // id => [vendor_id, member_id, old point, new point]
        $manual = [];       // 需人工：session 中夾有非遊戲異動且 balance 已被污染

        // 依主鍵 id 區間分批掃：transaction_logs 沒有 (type, status, created_at) 複合索引，直接以條件掃三個月
        // 會在唯讀庫撞 max_execution_time；PK range 每批毫秒級，條件只在批內過濾。
        $firstId = $this->findFirstIdAtOrAfter($since);
        $maxId = (int) TransactionLog::query()->max('id');
        if ($firstId === null || $firstId > $maxId) {
            $this->info('範圍內沒有現金紀錄。');

            return self::SUCCESS;
        }

        $scanned = 0;
        for ($from = $firstId; $from <= $maxId; $from += $chunk) {
            $rows = $this->candidateQuery($since, $vendorId)
                ->whereBetween('id', [$from, $from + $chunk - 1])
                ->orderBy('id')
                ->get(['id', 'member_id', 'vendor_id', 'before_balance', 'balance', 'point', 'rate', 'created_at', 'updated_at']);
            $scanned++;

            foreach ($rows as $row) {
                $expected = Common::numSub((string) $row->before_balance, (string) $row->balance, 4);
                $entry = [
                    'id' => $row->id,
                    'vendor_id' => $row->vendor_id,
                    'member_id' => $row->member_id,
                    'before_balance' => (string) $row->before_balance,
                    'balance' => (string) $row->balance,
                    'old_point' => (string) $row->point,
                    'new_point' => $expected,
                ];

                if ($this->isPollutedByNonGameChange($row)) {
                    $manual[$row->id] = $entry;

                    continue;
                }

                $repairable[$row->id] = $entry;
            }
        }

        $this->info(sprintf('已掃描 id %d ～ %d（%d 批，每批 %d）', $firstId, $maxId, $scanned, $chunk));

        $this->renderSummary($repairable, $manual);
        $this->renderDetails('可回補', $repairable, $detailLimit);
        $this->renderDetails('需人工（session 內夾有非遊戲異動，balance 已被污染）', $manual, $detailLimit);

        if ($repairable === []) {
            $this->info('無可回補資料。');

            return self::SUCCESS;
        }

        if (!$execute) {
            $this->warn('⚠️  DRY RUN：不寫入資料庫。加 --execute --reason="..." 才會回補。');

            return self::SUCCESS;
        }

        $affected = 0;
        foreach (array_chunk(array_keys($repairable), $chunk) as $ids) {
            // 批次寫入走 DB decimal 運算（精確）；不經 Model 事件是刻意的：Observer 只清會員快取，回補歷史列不需要
            $affected += TransactionLog::query()
                ->whereKey($ids)
                ->where('type', TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value)
                ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
                ->toBase()
                ->update([
                    'point' => DB::raw('before_balance - balance'),
                    'vendor_point' => DB::raw('(before_balance - balance) * rate'),
                ]);

            Log::info(self::CMD_NAME . ' repaired', [
                'reason' => $reason,
                'connection' => DB::connection()->getName(),
                'rows' => array_values(array_intersect_key($repairable, array_flip($ids))),
            ]);
        }

        $this->newLine();
        $this->info(sprintf('完成：已回補 %d 筆 transaction_logs.point / vendor_point。', $affected));

        return self::SUCCESS;
    }

    /**
     * 以主鍵二分搜尋找出 created_at >= $since 的第一個 id：每步都是 PK 點查，約 30 次即可，
     * 不對 created_at 索引做大範圍掃描（MIN(id) 走 created_at 索引仍要掃完整段）。
     * id 與 created_at 在本表單調同向（自增主鍵、寫入即建檔）。
     */
    private function findFirstIdAtOrAfter(Carbon $since): ?int
    {
        $low = (int) TransactionLog::query()->min('id');
        $high = (int) TransactionLog::query()->max('id');
        if ($low === 0 || $high === 0) {
            return null;
        }

        $answer = null;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $probe = TransactionLog::query()->where('id', '>=', $mid)->orderBy('id')->first(['id', 'created_at']);
            if ($probe === null) {
                $high = $mid - 1;

                continue;
            }

            if ($probe->created_at->greaterThanOrEqualTo($since)) {
                $answer = (int) $probe->id;
                $high = $mid - 1;
            } else {
                $low = (int) $probe->id + 1;
            }
        }

        return $answer;
    }

    /**
     * 候選列：已結算的單錢包現金紀錄，且 point 與 before_balance − balance 對不上。
     *
     * @return Builder<TransactionLog>
     */
    private function candidateQuery(Carbon $since, ?int $vendorId): Builder
    {
        return TransactionLog::query()
            ->where('type', TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value)
            ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->where('created_at', '>=', $since)
            ->when($vendorId !== null, fn (Builder $q) => $q->where('vendor_id', $vendorId))
            ->whereRaw('ROUND(before_balance - balance, 4) <> ROUND(point, 4)');
    }

    /**
     * session 開著期間（created_at ～ updated_at）若有非遊戲異動（儲值／提款／補點…），
     * 且最早那筆的 before_balance ≠ 本列 balance，代表該異動之後還有遊戲合併進來、balance 已含該異動金額，
     * before_balance − balance 不再等於遊戲淨輸贏，不能機械回補。
     */
    private function isPollutedByNonGameChange(TransactionLog $row): bool
    {
        // 排序用 created_at 不用 id：ORDER BY id LIMIT 1 會讓優化器改走 PRIMARY 順序掃描（TPower 實測掃到逾時），
        // 依 created_at 排序才會用 created_at／member_id 索引，單筆 0.1 秒內
        $firstOther = TransactionLog::query()
            ->where('member_id', $row->member_id)
            ->where('type', '<>', TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value)
            ->whereBetween('created_at', [$row->created_at, $row->updated_at])
            ->orderBy('created_at')
            ->orderBy('id')
            ->first(['id', 'before_balance']);

        if ($firstOther === null) {
            return false;
        }

        return Common::numCompare((string) $firstOther->before_balance, (string) $row->balance, 4) !== 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $repairable
     * @param  array<int, array<string, mixed>>  $manual
     */
    private function renderSummary(array $repairable, array $manual): void
    {
        $byVendor = [];
        foreach ([['可回補', $repairable], ['需人工', $manual]] as [$label, $rows]) {
            foreach ($rows as $row) {
                $byVendor[$row['vendor_id']][$label] = ($byVendor[$row['vendor_id']][$label] ?? 0) + 1;
            }
        }
        ksort($byVendor);

        $rows = array_map(
            fn (int $vendorId, array $counts) => [$vendorId, $counts['可回補'] ?? 0, $counts['需人工'] ?? 0],
            array_keys($byVendor),
            $byVendor
        );
        $rows[] = ['合計', count($repairable), count($manual)];

        $this->table(['vendor_id', '可回補', '需人工'], $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderDetails(string $title, array $rows, int $limit): void
    {
        if ($rows === []) {
            return;
        }

        $shown = $limit > 0 ? array_slice($rows, 0, $limit, true) : $rows;
        $this->newLine();
        $this->line(sprintf('%s：%d 筆%s', $title, count($rows), count($shown) < count($rows) ? "（僅列前 {$limit} 筆）" : ''));
        $this->table(
            ['id', 'vendor_id', 'member_id', 'before_balance', 'balance', 'point（現值）', 'point（回補後）'],
            array_map(fn (array $r) => [$r['id'], $r['vendor_id'], $r['member_id'], $r['before_balance'], $r['balance'], $r['old_point'], $r['new_point']], $shown)
        );
    }
}
