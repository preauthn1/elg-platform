<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PlayTech;

use App\Enums\BetRecordRecoveryTypeEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\BetRecordRecoveryLog;
use App\Models\TotalBetRecord;
use App\Vendors\Models\PtswBetRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PTSW Multi-bet 投注額補帳。
 *
 * 對應 issue：PlayTechController::bet() 在 fix/ptsw-bet-multibet-accumulation 之前
 * BR.bet/valid_bet 是覆寫而非累加，造成 multi-bet 注單投注額偏低、SR11 與
 * vendor 後台 turnover 報表少算。
 *
 * 補帳來源：ptsw_deal_records.bet_amount（type='bet'）為事件級真值，按 bet_id 累計。
 * 玩家錢包不變（PtswBetRecord::getWin() = win_lose + bet 設計讓 walletChange 與 bet 大小無關）。
 *
 * 修復本體：commit 0e82fe915（placeBet 同 round 多次下注累加 bet/valid_bet）。
 */
class ReconcileBetAccumulation extends Command
{
    private const int VENDOR_ID = VendorIdEnum::PTSW->value;

    private const string CMD_NAME = 'playtech:reconcile-bet-accumulation';

    private const string SOURCE_TAG = 'ptsw-multibet-accumulation-fix-2026-05';

    protected $signature = self::CMD_NAME . '
        {--from= : Asia/Taipei 起始日 YYYY-MM-DD（含）}
        {--to= : Asia/Taipei 結束日 YYYY-MM-DD（不含）}
        {--execute : 實際寫入（不加為 dry-run）}
        {--reason= : 執行原因（execute 必填）}
        {--max-bet-diff=1000 : 單筆漏記金額上限保險閥（超過則 skip）}
        {--detail-limit=20 : 明細表格顯示筆數上限（0 = 全列）}';

    protected $description = 'PTSW 修復 multi-bet 投注額漏記（用 deal_records 重建 BR.bet/valid_bet 與 TBR.valid_bet）';

    /** @var array<string, bool> hour bucket UTC 'Y-m-d H:00' → true */
    private array $affectedHours = [];

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $execute = (bool) $this->option('execute');
        $reason = (string) $this->option('reason');
        $maxDiff = (string) $this->option('max-bet-diff');
        $detailLimit = (int) $this->option('detail-limit');

        if ($from === '' || $to === '') {
            $this->error('--from 與 --to 必填 (YYYY-MM-DD, Asia/Taipei)');

            return self::FAILURE;
        }
        if ($execute && trim($reason) === '') {
            $this->error('--execute 模式必填 --reason="..."');

            return self::FAILURE;
        }

        $utcStart = Carbon::createFromFormat('Y-m-d H:i:s', $from . ' 00:00:00', 'Asia/Taipei')
            ->setTimezone('UTC')->format('Y-m-d H:i:s');
        $utcEnd = Carbon::createFromFormat('Y-m-d H:i:s', $to . ' 00:00:00', 'Asia/Taipei')
            ->setTimezone('UTC')->format('Y-m-d H:i:s');

        $this->info('====================================');
        $this->info('PTSW Multi-bet 投注額補帳');
        $this->info('====================================');
        $this->info("Taipei [{$from}, {$to}) = UTC [{$utcStart}, {$utcEnd})");
        $this->info('模式: ' . ($execute ? 'EXECUTE (實際寫入)' : 'DRY-RUN'));
        $this->info("單筆漏記上限保險閥: {$maxDiff}");
        $this->newLine();

        $candidates = $this->collectAffectedBrs($utcStart, $utcEnd);
        if ($candidates->isEmpty()) {
            $this->info('沒有受影響的注單');

            return self::SUCCESS;
        }

        $this->info(sprintf('找到 %d 筆候選注單', $candidates->count()));

        $stats = [
            'processed' => 0,
            'fixed' => 0,
            'skipped_max_diff' => 0,
            'skipped_already_recovered' => 0,
            'skipped_no_tbr' => 0,
            'failed' => 0,
            'sum_missing_bet' => '0',
        ];

        /** @var list<array{bet_record_id: int, bet_id: string, username: string, current_bet: string, expected_bet: string, missing: string, action: string}> */
        $details = [];

        $bar = $this->output->createProgressBar($candidates->count());
        $bar->start();

        foreach ($candidates as $row) {
            $stats['processed']++;
            try {
                $this->processBetRecord(
                    betRecordId: (int) $row->bet_record_id,
                    betId: (string) $row->bet_id,
                    username: (string) $row->username,
                    expectedBet: (string) $row->expected_bet,
                    currentBet: (string) $row->current_bet,
                    maxDiff: $maxDiff,
                    reason: $reason,
                    execute: $execute,
                    stats: $stats,
                    details: $details,
                );
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::error(self::CMD_NAME . ' failed', [
                    'bet_record_id' => $row->bet_record_id,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);
                $this->newLine();
                $this->error(sprintf('BR id=%s 失敗: %s', $row->bet_record_id, $e->getMessage()));
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->renderDetailsTable($details, $detailLimit, $execute);

        $this->info('====================================');
        $this->info('執行結果');
        $this->info('====================================');
        $this->table(['指標', '值'], [
            ['BR 處理', $stats['processed']],
            ['BR ' . ($execute ? '修復' : '待修復'), $stats['fixed']],
            ['skip：單筆 > ' . $maxDiff, $stats['skipped_max_diff']],
            ['skip：已被 recover', $stats['skipped_already_recovered']],
            ['skip：找不到 TBR', $stats['skipped_no_tbr']],
            ['失敗', $stats['failed']],
            ['補帳 bet 合計', '+' . $stats['sum_missing_bet']],
        ]);

        $this->renderSbrRebuildHints();

        if (!$execute) {
            $this->newLine();
            $this->warn('Dry-run 結束，無實際變動。加 --execute --reason="..." 執行修復。');
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 用 deal_records (含 refund 沖銷事件) SUM(bet_amount) JOIN BR.bet，找出 BR.bet < expected_bet 的 BR。
     *
     * 為何含 type='gameRoundResult'：
     *   PlayTechController createDealRecord 在 REFUND 路徑寫 bet_amount = -placeBet 金額
     *   作為沖銷事件（type='gameRoundResult'，applied_at=NULL），全 SUM 後 refund 注單
     *   expected_bet 自然為 0，不會被誤列為「漏記」。
     *
     * 為何 type='bet' 仍須 applied_at 限制：
     *   placeBet 餘額不足 / errorResponse 早退時，deal_record 已建但 applied_at 仍為 NULL，
     *   不應算入 expected_bet（玩家錢沒被扣、BR.bet 也沒更新）。
     *
     * 為何 type='gameRoundResult' 要排除 raw 帶 _rejected：
     *   settlement 拒絕 REFUND（缺 relatedTransactionCode／原下注未 applied／已退過／找不到注單）時
     *   deal_record 同樣已建且 bet_amount 為負，但錢包與 BR 都沒動；算進去會把 expected_bet 沖成 0，
     *   真正漏記的注單就漏列（PlayTechController::rejectRefund）。gameRoundResult 事件無 applied_at 可用，只能看標記。
     *
     * 時間 filter 對兩種 type 用不同欄位：
     *   - type='bet'：applied_at（玩家成功扣餘額的時刻）
     *   - type='gameRoundResult'：created_at（事件寫入時刻，applied_at 永遠 NULL）
     */
    private function collectAffectedBrs(string $utcStart, string $utcEnd): Collection
    {
        return DB::table('ptsw_bet_records as b')
            ->joinSub(
                DB::table('ptsw_deal_records')
                    ->select('bet_id', DB::raw('SUM(bet_amount) AS expected_bet'))
                    ->where(function ($q) use ($utcStart, $utcEnd): void {
                        $q->where(function ($q2) use ($utcStart, $utcEnd): void {
                            $q2->where('type', 'bet')
                                ->whereNotNull('applied_at')
                                ->where('applied_at', '>=', $utcStart)
                                ->where('applied_at', '<', $utcEnd);
                        })->orWhere(function ($q2) use ($utcStart, $utcEnd): void {
                            $q2->where('type', 'gameRoundResult')
                                ->where('created_at', '>=', $utcStart)
                                ->where('created_at', '<', $utcEnd)
                                // 被 settlement 拒絕的 REFUND 留下的事件（raw 帶 _rejected）沒動過錢包與 BR，不得沖掉 expected_bet；
                                // raw 是 TEXT 存 JSON，用 LIKE 排除（\_ 讓底線不被當單字元萬用字元；依賴連線 sql_mode 不含
                                // NO_BACKSLASH_ESCAPES，config/database.php 的 modes 明列且不含它），NULL raw 視為未標記——
                                // 少了 whereNull，NOT LIKE 對 NULL 回 NULL 會把生效的沖銷事件濾掉、把 refund 局補過頭
                                ->where(function ($q3): void {
                                    $q3->whereNull('raw')->orWhere('raw', 'not like', '%"\\_rejected"%');
                                });
                        });
                    })
                    ->groupBy('bet_id'),
                'd', 'd.bet_id', '=', 'b.bet_id'
            )
            ->where('b.bet_time', '>=', $utcStart)
            ->where('b.bet_time', '<', $utcEnd)
            ->whereRaw('ROUND(d.expected_bet - b.bet, 4) > 0.01')
            ->select(
                'b.id AS bet_record_id',
                'b.bet_id',
                'b.username',
                'b.bet AS current_bet',
                'd.expected_bet'
            )
            ->orderBy('b.id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  list<array<string, mixed>>  $details
     */
    private function processBetRecord(
        int $betRecordId,
        string $betId,
        string $username,
        string $expectedBet,
        string $currentBet,
        string $maxDiff,
        string $reason,
        bool $execute,
        array &$stats,
        array &$details,
    ): void {
        $missing = Common::numSub($expectedBet, $currentBet, 4);

        if (Common::numCompare($missing, $maxDiff, 4) > 0) {
            $stats['skipped_max_diff']++;
            $details[] = [
                'bet_record_id' => $betRecordId,
                'bet_id' => $betId,
                'username' => $username,
                'current_bet' => $currentBet,
                'expected_bet' => $expectedBet,
                'missing' => $missing,
                'action' => 'SKIP (>max)',
            ];
            Log::warning(self::CMD_NAME . ' skip：單筆漏記超過保險閥', [
                'bet_record_id' => $betRecordId,
                'current_bet' => $currentBet,
                'expected_bet' => $expectedBet,
                'missing' => $missing,
                'max' => $maxDiff,
            ]);

            return;
        }

        if (BetRecordRecoveryLog::isCurrentlyRecovered(self::VENDOR_ID, $betRecordId)) {
            $stats['skipped_already_recovered']++;
            $details[] = [
                'bet_record_id' => $betRecordId,
                'bet_id' => $betId,
                'username' => $username,
                'current_bet' => $currentBet,
                'expected_bet' => $expectedBet,
                'missing' => $missing,
                'action' => 'SKIP (recovered)',
            ];

            return;
        }

        $br = PtswBetRecord::find($betRecordId);
        $tbrExists = $br
            ? TotalBetRecord::where('vendor_id', self::VENDOR_ID)
                ->where('bet_record_id', $br->id)
                ->exists()
            : false;

        if (!$br || !$tbrExists) {
            $stats['skipped_no_tbr']++;
            $details[] = [
                'bet_record_id' => $betRecordId,
                'bet_id' => $betId,
                'username' => $username,
                'current_bet' => $currentBet,
                'expected_bet' => $expectedBet,
                'missing' => $missing,
                'action' => 'SKIP (no TBR)',
            ];

            return;
        }

        $details[] = [
            'bet_record_id' => $betRecordId,
            'bet_id' => $betId,
            'username' => $username,
            'current_bet' => $currentBet,
            'expected_bet' => $expectedBet,
            'missing' => '+' . $missing,
            'action' => $execute ? 'FIXED' : 'WILL FIX',
        ];

        if ($br->payout_time) {
            $hourKey = Carbon::parse($br->payout_time)->format('Y-m-d H:00');
            $this->affectedHours[$hourKey] = true;
        }

        if (!$execute) {
            $stats['fixed']++;
            $stats['sum_missing_bet'] = Common::numAdd($stats['sum_missing_bet'], $missing, 4);

            return;
        }

        DB::transaction(function () use ($betRecordId, $expectedBet, $missing, $reason, &$stats): void {
            $br = PtswBetRecord::where('id', $betRecordId)->lockForUpdate()->firstOrFail();

            $tbr = TotalBetRecord::where('vendor_id', self::VENDOR_ID)
                ->where('bet_record_id', $br->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = [
                'br' => [
                    'id' => $br->id,
                    'bet' => (string) $br->bet,
                    'valid_bet' => (string) $br->valid_bet,
                    'win_lose' => (string) $br->win_lose,
                    'status' => $br->status,
                ],
                'tbr' => [
                    'id' => $tbr->id,
                    'bet' => (string) $tbr->bet,
                    'valid_bet' => (string) $tbr->valid_bet,
                    'win_lose' => (string) $tbr->win_lose,
                ],
            ];

            $br->bet = $expectedBet;
            $br->valid_bet = $expectedBet;
            $br->save();

            DB::table('total_bet_records')
                ->where('id', $tbr->id)
                ->where('vendor_id', self::VENDOR_ID)
                ->update([
                    'bet' => $expectedBet,
                    'valid_bet' => $expectedBet,
                    'updated_at' => now(),
                ]);

            BetRecordRecoveryLog::create([
                'vendor_id' => self::VENDOR_ID,
                'bet_record_id' => $br->id,
                'bet_id' => $br->bet_id,
                'member_id' => $br->member_id,
                'type' => BetRecordRecoveryTypeEnum::RECOVER,
                'performed_by' => self::CMD_NAME,
                'reason' => $reason,
                'source' => self::SOURCE_TAG,
                'before' => $before,
                'after' => [
                    'br' => [
                        'id' => $br->id,
                        'bet' => $expectedBet,
                        'valid_bet' => $expectedBet,
                        'win_lose' => (string) $br->win_lose,
                        'status' => $br->status,
                    ],
                    'tbr' => [
                        'id' => $tbr->id,
                        'bet' => $expectedBet,
                        'valid_bet' => $expectedBet,
                        'win_lose' => (string) $tbr->win_lose,
                        '_meta' => ['total_bet_record_id' => $tbr->id],
                    ],
                ],
                'balance_diff' => '0.0000',
            ]);

            $stats['fixed']++;
            $stats['sum_missing_bet'] = Common::numAdd($stats['sum_missing_bet'], $missing, 4);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $details
     */
    private function renderDetailsTable(array $details, int $detailLimit, bool $execute): void
    {
        if ($details === []) {
            return;
        }

        $title = $execute ? '補帳明細（已執行）' : '補帳明細（DRY-RUN，未寫入）';
        $this->info($title);

        $rows = $detailLimit > 0 ? array_slice($details, 0, $detailLimit) : $details;
        $this->table(
            ['BR id', 'bet_id', 'username', '目前 bet', '正確 bet', '漏記', '動作'],
            array_map(static fn (array $r): array => [
                $r['bet_record_id'],
                $r['bet_id'],
                $r['username'],
                $r['current_bet'],
                $r['expected_bet'],
                $r['missing'],
                $r['action'],
            ], $rows),
        );

        $total = count($details);
        if ($detailLimit > 0 && $total > $detailLimit) {
            $this->line(sprintf('… 還有 %d 筆未顯示（加 --detail-limit=0 列出全部）', $total - $detailLimit));
        }
        $this->newLine();
    }

    private function renderSbrRebuildHints(): void
    {
        if ($this->affectedHours === []) {
            return;
        }

        $hours = array_keys($this->affectedHours);
        sort($hours);

        $daysSet = [];
        foreach ($hours as $h) {
            $sgt = Carbon::createFromFormat('Y-m-d H:i', $h, 'UTC')->setTimezone('Asia/Taipei');
            $daysSet[$sgt->format('Y-m-d')] = true;
        }

        $this->newLine();
        $this->warn(sprintf(
            '受影響 hour bucket %d 個、跨 %d 天，補帳後需重建 SBR：',
            count($hours),
            count($daysSet),
        ));
        foreach (array_keys($daysSet) as $d) {
            $this->line(sprintf(
                '   php artisan summary_bet_record --vendor=%d --date=%s',
                self::VENDOR_ID,
                $d,
            ));
        }
    }
}
