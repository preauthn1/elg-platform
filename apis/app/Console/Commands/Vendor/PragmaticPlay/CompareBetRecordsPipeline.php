<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PragmaticPlay;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 依 ppsw_bet_records 為基準，逐層比對投注紀錄資料管線的一致性
 *
 * 資料流：
 *   ppsw_bet_records（callback 寫入）
 *       ↓ 彙整
 *   total_bet_records（vendor_id=83）
 *       ↓ 以小時彙整
 *   summary_bet_records（data_time 欄位）
 *
 * 使用時機：
 *   1. 預設：比對 ppsw_bet_records → total_bet_records → summary_bet_records 三層是否一致
 *   2. 加 --with-game-rounds：三層已吻合但疑似與遊戲商後台數據不符時，
 *      額外比對 ppsw_game_rounds（官方拉取）vs ppsw_bet_records
 *
 * 使用方式：
 *   php artisan ppsw:compare-bet-pipeline 2026-04-02
 *
 *   若與遊戲商後台數字有差異，加此 flag 進一步比對官方 DataFeed
 *   但應該先執行 php artisan ppsw:game-rounds 拉取官方數據寫入 ppsw_game_rounds
 *   php artisan ppsw:compare-bet-pipeline 2026-04-02 --with-game-rounds
 */
class CompareBetRecordsPipeline extends Command
{
    private const int VENDOR_ID = 83;

    protected $signature = 'ppsw:compare-bet-pipeline
                            {date : 查詢日期 Y-m-d}
                            {--with-game-rounds : 額外比對 ppsw_game_rounds vs ppsw_bet_records}';

    protected $description = '逐層比對 ppsw_bet_records → total_bet_records → summary_bet_records 是否一致';

    public function handle(): int
    {
        ini_set('memory_limit', '-1');

        $dateStr = (string) $this->argument('date');
        $date = $this->parseDateArgument($dateStr);

        if ($date === null) {
            $this->error('❌ 日期格式錯誤，請使用 Y-m-d 格式（例如：2026-04-02）');

            return 1;
        }

        $start = $date->copy()->startOfDay()->format('Y-m-d H:i:s');
        $end = $date->copy()->addDay()->startOfDay()->format('Y-m-d H:i:s');

        $this->printHeader($dateStr, $start, $end);

        try {
            $this->showPipelineSummary($start, $end);
            $pipelineOk = $this->checkBetRecordsVsTotalBetRecords($start, $end);
            $this->checkTotalBetRecordsVsSummary($start, $end);

            if ($this->option('with-game-rounds')) {
                $this->newLine();
                $this->info('========================================');
                $this->info('🔬 進階比對：ppsw_game_rounds vs ppsw_bet_records');
                $this->info('========================================');
                $this->checkGameRoundsVsBetRecords($start, $end);
            } elseif ($pipelineOk) {
                $this->newLine();
                $this->line('💡 三層資料管線吻合。若與遊戲商後台數據仍有差異，');
                $this->line('   請加上 --with-game-rounds 進行進階比對：');
                $this->line('   php artisan ppsw:compare-bet-pipeline ' . $dateStr . ' --with-game-rounds');
            }
        } catch (Throwable $e) {
            $this->error('❌ 查詢發生例外：' . $e->getMessage());

            return 1;
        }

        return 0;
    }

    // ─────────────────────────────────────────────────────────────
    //  三層摘要並排
    // ─────────────────────────────────────────────────────────────

    private function showPipelineSummary(string $start, string $end): void
    {
        $this->info('📊 資料管線三層摘要');
        $this->info(str_repeat('─', 80));

        $br = DB::selectOne('SELECT COUNT(*) AS cnt, SUM(bet) AS total_bet, SUM(win_lose) AS total_wl FROM ppsw_bet_records WHERE payout_time >= ? AND payout_time < ?', [$start, $end]);
        $tbr = DB::selectOne('SELECT COUNT(*) AS cnt, SUM(bet) AS total_bet, SUM(win_lose) AS total_wl FROM total_bet_records WHERE vendor_id = ? AND payout_time >= ? AND payout_time < ?', [self::VENDOR_ID, $start, $end]);
        $sbr = DB::selectOne('SELECT SUM(record_count) AS cnt, SUM(bet) AS total_bet, SUM(win_lose) AS total_wl FROM summary_bet_records WHERE vendor_id = ? AND data_time >= ? AND data_time < ?', [self::VENDOR_ID, $start, $end]);

        $this->table(
            ['層級', '時間欄位', '記錄數', '總投注', '總輸贏(win_lose)'],
            [
                ['① ppsw_bet_records（基準）', 'payout_time', number_format((int) $br->cnt), number_format((float) $br->total_bet, 2), number_format((float) $br->total_wl, 2)],
                ['② total_bet_records',        'payout_time', number_format((int) $tbr->cnt), number_format((float) $tbr->total_bet, 2), number_format((float) $tbr->total_wl, 2)],
                ['③ summary_bet_records',      'data_time',   number_format((int) $sbr->cnt), number_format((float) $sbr->total_bet, 2), number_format((float) $sbr->total_wl, 2)],
            ]
        );

        $this->newLine();
    }

    // ─────────────────────────────────────────────────────────────
    //  ① ppsw_bet_records  vs  ② total_bet_records
    // ─────────────────────────────────────────────────────────────

    private function checkBetRecordsVsTotalBetRecords(string $start, string $end): bool
    {
        $this->info('🔍 【1】ppsw_bet_records → total_bet_records');
        $this->info(str_repeat('─', 80));

        // (A) ppsw_bet_records 有（payout_time 在當天），但 total_bet_records 完全找不到
        $onlyBr = DB::selectOne('
            SELECT COUNT(*) AS cnt, SUM(br.bet) AS bet_sum, SUM(br.win_lose) AS wl_sum
            FROM ppsw_bet_records br
            LEFT JOIN total_bet_records tbr ON tbr.bet_record_id = br.id AND tbr.vendor_id = ?
            WHERE br.payout_time >= ? AND br.payout_time < ?
              AND tbr.id IS NULL
        ', [self::VENDOR_ID, $start, $end]);

        // (B) total_bet_records 有（payout_time 在當天），但對應的 ppsw_bet_records.payout_time 不在當天（跨日）
        $crossDay = DB::selectOne('
            SELECT COUNT(*) AS cnt, SUM(tbr.bet) AS bet_sum, SUM(tbr.win_lose) AS wl_sum
            FROM total_bet_records tbr
            JOIN ppsw_bet_records br ON br.id = tbr.bet_record_id
            WHERE tbr.vendor_id = ?
              AND tbr.payout_time >= ? AND tbr.payout_time < ?
              AND (br.payout_time < ? OR br.payout_time >= ?)
        ', [self::VENDOR_ID, $start, $end, $start, $end]);

        // (C) 兩表都有且 payout_time 同一天，但金額不一致
        $amountDiff = DB::selectOne('
            SELECT COUNT(*) AS cnt,
                   SUM(br.bet - tbr.bet) AS bet_diff,
                   SUM(br.win_lose - tbr.win_lose) AS wl_diff
            FROM ppsw_bet_records br
            JOIN total_bet_records tbr ON tbr.bet_record_id = br.id AND tbr.vendor_id = ?
            WHERE br.payout_time >= ? AND br.payout_time < ?
              AND tbr.payout_time >= ? AND tbr.payout_time < ?
              AND (ABS(br.bet - tbr.bet) > 0.0001 OR ABS(br.win_lose - tbr.win_lose) > 0.0001)
        ', [self::VENDOR_ID, $start, $end, $start, $end]);

        // (D) ppsw_bet_records.payout_time 在當天，total_bet_records 有對應記錄但 payout_time 不在當天（反向跨日）
        //     根本原因：PPSW EndRound callback 不帶時間欄位，payout_time 以 now() 估值；
        //     網路傳輸延遲約 1 秒，跨日邊界的注單容易被記為次日。
        //     屬正常結構性現象，執行 ppsw:game-rounds + ppsw:sync-payout-time 後三表會同步修正。
        $reverseCrossDay = DB::selectOne('
            SELECT COUNT(*) AS cnt, SUM(br.bet) AS bet_sum, SUM(br.win_lose) AS wl_sum
            FROM ppsw_bet_records br
            JOIN total_bet_records tbr ON tbr.bet_record_id = br.id AND tbr.vendor_id = ?
            WHERE br.payout_time >= ? AND br.payout_time < ?
              AND (tbr.payout_time < ? OR tbr.payout_time >= ?)
        ', [self::VENDOR_ID, $start, $end, $start, $end]);

        // (A)(C) 為真正異常；(B)(D) 為跨日口徑差，屬正常現象
        $hasIssue = (int) $onlyBr->cnt > 0 || (int) $amountDiff->cnt > 0;

        $this->table(
            ['類型', '筆數', 'ppsw_bet_records.bet', 'ppsw_bet_records.win_lose', '說明'],
            [
                ['(A) ppsw_bet_records 有，total_bet_records 找不到',            number_format((int) $onlyBr->cnt),        number_format((float) $onlyBr->bet_sum, 2),        number_format((float) $onlyBr->wl_sum, 2),        '未同步至 total_bet_records'],
                ['(B) total_bet_records 算當天，但 ppsw_bet_records.payout_time 不同日', number_format((int) $crossDay->cnt),       number_format((float) $crossDay->bet_sum, 2),      number_format((float) $crossDay->wl_sum, 2),      '跨日口徑差（正常）'],
                ['(C) 同日配對但金額不一致',                                       number_format((int) $amountDiff->cnt),    number_format((float) $amountDiff->bet_diff, 2),   number_format((float) $amountDiff->wl_diff, 2),   'ppsw_bet_records.bet − total_bet_records.bet'],
                ['(D) ppsw_bet_records 算當天，但 total_bet_records.payout_time 不同日', number_format((int) $reverseCrossDay->cnt), number_format((float) $reverseCrossDay->bet_sum, 2), number_format((float) $reverseCrossDay->wl_sum, 2), 'EndRound 無時間欄位，now() 估值造成的跨日差（正常，對帳後可修正）'],
            ]
        );

        // (A)(C) 異常明細
        if ((int) $onlyBr->cnt > 0) {
            $rows = DB::select('
                SELECT br.id, br.bet_id, br.member_id, br.game_code, br.bet, br.win_lose, br.payout_time
                FROM ppsw_bet_records br
                LEFT JOIN total_bet_records tbr ON tbr.bet_record_id = br.id AND tbr.vendor_id = ?
                WHERE br.payout_time >= ? AND br.payout_time < ? AND tbr.id IS NULL
                ORDER BY br.payout_time LIMIT 20
            ', [self::VENDOR_ID, $start, $end]);
            $this->newLine();
            $this->warn('  ⚠️  (A) 未同步至 total_bet_records 的明細（前 20 筆）：');
            $this->table(
                ['ppsw_bet_records.id', 'bet_id', 'member_id', 'game_code', 'bet', 'win_lose', 'payout_time'],
                array_map(fn ($r) => [$r->id, $r->bet_id, $r->member_id, $r->game_code, $r->bet, $r->win_lose, $r->payout_time], $rows)
            );
            $this->newLine();
            $this->warn('  → 請執行以下命令補寫缺失的 total_bet_records（會走三道驗證：無對應 game_round / 未結算 / multi-bet 會 skip）：');
            $this->line('     php artisan ppsw:fix-records ' . substr($start, 0, 10));
            $this->line('  → 補寫後可重新執行本命令確認：');
            $this->line('     php artisan ppsw:compare-bet-pipeline ' . substr($start, 0, 10));
        }

        if ((int) $amountDiff->cnt > 0) {
            $rows = DB::select('
                SELECT br.id, br.bet_id, br.member_id,
                       br.bet AS ppsw_bet_records_bet, tbr.bet AS total_bet_records_bet,
                       br.win_lose AS ppsw_bet_records_win_lose, tbr.win_lose AS total_bet_records_win_lose
                FROM ppsw_bet_records br
                JOIN total_bet_records tbr ON tbr.bet_record_id = br.id AND tbr.vendor_id = ?
                WHERE br.payout_time >= ? AND br.payout_time < ?
                  AND tbr.payout_time >= ? AND tbr.payout_time < ?
                  AND (ABS(br.bet - tbr.bet) > 0.0001 OR ABS(br.win_lose - tbr.win_lose) > 0.0001)
                LIMIT 20
            ', [self::VENDOR_ID, $start, $end, $start, $end]);
            $this->newLine();
            $this->warn('  ⚠️  (C) 金額不一致明細：');
            $this->table(
                ['ppsw_bet_records.id', 'bet_id', 'member_id', 'ppsw_bet_records.bet', 'total_bet_records.bet', 'ppsw_bet_records.win_lose', 'total_bet_records.win_lose'],
                array_map(fn ($r) => [$r->id, $r->bet_id, $r->member_id, $r->ppsw_bet_records_bet, $r->total_bet_records_bet, $r->ppsw_bet_records_win_lose, $r->total_bet_records_win_lose], $rows)
            );
        }

        if (!$hasIssue) {
            $this->info('  ✅ 無異常（(A)(C) 均為 0）。');
        }

        // (B)(D) 跨日口徑差明細 — 屬正常，固定列出以解釋摘要數字差異
        if ((int) $crossDay->cnt > 0) {
            $rows = DB::select('
                SELECT br.id, br.bet_id, br.member_id, tbr.bet,
                       br.payout_time AS ppsw_bet_records_payout_time,
                       tbr.payout_time AS total_bet_records_payout_time
                FROM total_bet_records tbr
                JOIN ppsw_bet_records br ON br.id = tbr.bet_record_id
                WHERE tbr.vendor_id = ?
                  AND tbr.payout_time >= ? AND tbr.payout_time < ?
                  AND (br.payout_time < ? OR br.payout_time >= ?)
                ORDER BY tbr.payout_time LIMIT 20
            ', [self::VENDOR_ID, $start, $end, $start, $end]);
            $this->newLine();
            $this->line('  ℹ️  (B) 跨日明細（total_bet_records 算當天，但 ppsw_bet_records.payout_time 不同日）：');
            $this->table(
                ['ppsw_bet_records.id', 'bet_id', 'member_id', 'total_bet_records.bet', 'ppsw_bet_records.payout_time', 'total_bet_records.payout_time'],
                array_map(fn ($r) => [$r->id, $r->bet_id, $r->member_id, $r->bet, $r->ppsw_bet_records_payout_time, $r->total_bet_records_payout_time], $rows)
            );
        }

        if ((int) $reverseCrossDay->cnt > 0) {
            $rows = DB::select('
                SELECT br.id, br.bet_id, br.member_id, br.bet,
                       br.payout_time AS ppsw_bet_records_payout_time,
                       tbr.payout_time AS total_bet_records_payout_time
                FROM ppsw_bet_records br
                JOIN total_bet_records tbr ON tbr.bet_record_id = br.id AND tbr.vendor_id = ?
                WHERE br.payout_time >= ? AND br.payout_time < ?
                  AND (tbr.payout_time < ? OR tbr.payout_time >= ?)
                ORDER BY br.payout_time LIMIT 20
            ', [self::VENDOR_ID, $start, $end, $start, $end]);
            $this->newLine();
            $this->line('  ℹ️  (D) 反向跨日明細（EndRound now() 估值造成，執行 ppsw:game-rounds + ppsw:sync-payout-time 後可修正）：');
            $this->table(
                ['ppsw_bet_records.id', 'bet_id', 'member_id', 'bet', 'ppsw_bet_records.payout_time', 'total_bet_records.payout_time'],
                array_map(fn ($r) => [$r->id, $r->bet_id, $r->member_id, $r->bet, $r->ppsw_bet_records_payout_time, $r->total_bet_records_payout_time], $rows)
            );
        }

        $this->newLine();

        return !$hasIssue;
    }

    // ─────────────────────────────────────────────────────────────
    //  ② total_bet_records  vs  ③ summary_bet_records
    // ─────────────────────────────────────────────────────────────

    private function checkTotalBetRecordsVsSummary(string $start, string $end): void
    {
        $this->info('🔍 【2】total_bet_records → summary_bet_records（逐小時）');
        $this->info(str_repeat('─', 80));

        // 以小時為單位，比對 total_bet_records(payout_time) vs summary_bet_records(data_time)
        $tbrHourly = DB::select("
            SELECT DATE_FORMAT(payout_time, '%Y-%m-%d %H:00') AS hour_slot,
                   COUNT(*) AS cnt, SUM(bet) AS total_bet, SUM(win_lose) AS total_wl
            FROM total_bet_records
            WHERE vendor_id = ? AND payout_time >= ? AND payout_time < ?
            GROUP BY hour_slot
            ORDER BY hour_slot
        ", [self::VENDOR_ID, $start, $end]);

        $sbrHourly = DB::select("
            SELECT DATE_FORMAT(data_time, '%Y-%m-%d %H:00') AS hour_slot,
                   SUM(record_count) AS cnt, SUM(bet) AS total_bet, SUM(win_lose) AS total_wl
            FROM summary_bet_records
            WHERE vendor_id = ? AND data_time >= ? AND data_time < ?
            GROUP BY hour_slot
            ORDER BY hour_slot
        ", [self::VENDOR_ID, $start, $end]);

        // 以 hour_slot 為 key 建立索引
        $tbrMap = [];
        foreach ($tbrHourly as $r) {
            $tbrMap[$r->hour_slot] = $r;
        }
        $sbrMap = [];
        foreach ($sbrHourly as $r) {
            $sbrMap[$r->hour_slot] = $r;
        }

        $allSlots = array_unique(array_merge(array_keys($tbrMap), array_keys($sbrMap)));
        sort($allSlots);

        $diffRows = [];
        $hasIssue = false;

        foreach ($allSlots as $slot) {
            $tbr = $tbrMap[$slot] ?? null;
            $sbr = $sbrMap[$slot] ?? null;

            $tbrBet = $tbr ? (float) $tbr->total_bet : 0.0;
            $sbrBet = $sbr ? (float) $sbr->total_bet : 0.0;
            $tbrWl = $tbr ? (float) $tbr->total_wl : 0.0;
            $sbrWl = $sbr ? (float) $sbr->total_wl : 0.0;
            $betDiff = round($tbrBet - $sbrBet, 4);
            $wlDiff = round($tbrWl - $sbrWl, 4);

            if (abs($betDiff) > 0.001 || abs($wlDiff) > 0.001) {
                $hasIssue = true;
                $diffRows[] = [
                    $slot,
                    $tbr ? number_format((int) $tbr->cnt) : '—',
                    $sbr ? number_format((int) $sbr->cnt) : '—',
                    number_format($tbrBet, 2),
                    number_format($sbrBet, 2),
                    number_format($betDiff, 4),
                    number_format($wlDiff, 4),
                ];
            }
        }

        if (!$hasIssue) {
            $this->info('  ✅ 每小時資料完全一致。');
        } else {
            $this->warn('  ⚠️  以下時段 total_bet_records 與 summary_bet_records 不一致：');
            $this->table(
                ['時間段', 'TBR 筆數', 'SBR 筆數', 'TBR bet', 'SBR bet', 'bet 差', 'win_lose 差'],
                $diffRows
            );
        }

        $this->newLine();
    }

    // ─────────────────────────────────────────────────────────────
    //  進階：ppsw_game_rounds vs ppsw_bet_records
    // ─────────────────────────────────────────────────────────────

    private function checkGameRoundsVsBetRecords(string $start, string $end): void
    {
        $this->info('🔍 【3】ppsw_game_rounds（官方）vs ppsw_bet_records');
        $this->info(str_repeat('─', 80));

        $gr = DB::selectOne("
            SELECT COUNT(*) AS cnt, SUM(bet) AS total_bet, SUM(win) AS total_win
            FROM ppsw_game_rounds
            WHERE endDate >= ? AND endDate < ?
              AND (parentSessionID = 'null' OR parentSessionID = '')
        ", [$start, $end]);

        $br = DB::selectOne('SELECT COUNT(*) AS cnt, SUM(bet) AS total_bet, SUM(win) AS total_win FROM ppsw_bet_records WHERE payout_time >= ? AND payout_time < ?', [$start, $end]);

        $this->table(
            ['來源', '時間欄位', '記錄數', '總投注', '總輸贏'],
            [
                ['ppsw_game_rounds 頂層', 'endDate',     number_format((int) $gr->cnt), number_format((float) $gr->total_bet, 2), number_format((float) $gr->total_win, 2)],
                ['ppsw_bet_records',      'payout_time', number_format((int) $br->cnt), number_format((float) $br->total_bet, 2), number_format((float) $br->total_win, 2)],
            ]
        );

        // (A) bet_records 有但 game_rounds 完全找不到
        $missingInGr = DB::selectOne('
            SELECT COUNT(*) AS cnt, SUM(br.bet) AS bet_sum, COUNT(DISTINCT br.member_id) AS member_cnt
            FROM ppsw_bet_records br
            LEFT JOIN ppsw_game_rounds gr ON gr.playSessionID = br.bet_id
            WHERE br.payout_time >= ? AND br.payout_time < ? AND gr.id IS NULL
        ', [$start, $end]);

        // (B) 兩表都有，但 endDate vs payout_time 跨日
        $crossDay = DB::selectOne("
            SELECT COUNT(*) AS cnt, SUM(br.bet) AS bet_sum
            FROM ppsw_bet_records br
            JOIN ppsw_game_rounds gr ON gr.playSessionID = br.bet_id
            WHERE br.payout_time >= ? AND br.payout_time < ?
              AND (gr.endDate < ? OR gr.endDate >= ?)
              AND (gr.parentSessionID = 'null' OR gr.parentSessionID = '')
        ", [$start, $end, $start, $end]);

        // (C) game_rounds 有但 bet_records 找不到（反向）
        $onlyGr = DB::selectOne("
            SELECT COUNT(*) AS cnt, SUM(gr.bet) AS bet_sum
            FROM ppsw_game_rounds gr
            LEFT JOIN ppsw_bet_records br ON br.bet_id = gr.playSessionID
            WHERE gr.endDate >= ? AND gr.endDate < ?
              AND (gr.parentSessionID = 'null' OR gr.parentSessionID = '')
              AND br.id IS NULL
        ", [$start, $end]);

        $this->newLine();
        $this->table(
            ['類型', '筆數', 'bet 加總', '說明'],
            [
                ['(A) bet_records 有，game_rounds 找不到', number_format((int) $missingInGr->cnt), number_format((float) $missingInGr->bet_sum, 2), 'DataFeed 未包含這些回合'],
                ['(B) 兩表都有，但日期跨日', number_format((int) $crossDay->cnt), number_format((float) $crossDay->bet_sum, 2), '口徑差（正常）'],
                ['(C) game_rounds 有，bet_records 找不到', number_format((int) $onlyGr->cnt), number_format((float) $onlyGr->bet_sum, 2), '反向跨日或資料異常'],
            ]
        );

        if ((int) $missingInGr->cnt === 0 && (int) $onlyGr->cnt === 0) {
            $this->info('  ✅ game_rounds 與 bet_records 一致（跨日差異屬正常）。');
            $this->line('  → 若與遊戲商後台數字仍有差異，差異來源為跨日口徑不同，非資料遺漏。');
        } else {
            if ((int) $missingInGr->cnt > 0) {
                $this->warn(sprintf('  ⚠️  (A) %d 筆 bet_records 在 game_rounds 找不到（受影響會員：%d 人，投注：%s）',
                    (int) $missingInGr->cnt, (int) $missingInGr->member_cnt, number_format((float) $missingInGr->bet_sum, 2)));
                $this->warn('      建議重新執行 ppsw:game-rounds ' . substr($start, 0, 10) . ' 補抓。');

                $hourDist = DB::select("SELECT DATE_FORMAT(br.payout_time, '%Y-%m-%d %H:00') AS hour_slot, COUNT(*) AS cnt, SUM(br.bet) AS bet_sum FROM ppsw_bet_records br LEFT JOIN ppsw_game_rounds gr ON gr.playSessionID = br.bet_id WHERE br.payout_time >= ? AND br.payout_time < ? AND gr.id IS NULL GROUP BY hour_slot ORDER BY hour_slot", [$start, $end]);
                $this->table(['時間段', '筆數', 'bet 加總'], array_map(fn ($r) => [$r->hour_slot, $r->cnt, number_format((float) $r->bet_sum, 2)], $hourDist));
            }
            if ((int) $onlyGr->cnt > 0) {
                $this->warn(sprintf('  ⚠️  (C) %d 筆 game_rounds 在 bet_records 找不到（投注：%s）', (int) $onlyGr->cnt, number_format((float) $onlyGr->bet_sum, 2)));
            }
        }

        $this->newLine();
    }

    // ─────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────

    private function parseDateArgument(string $dateStr): ?Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $dateStr)->startOfDay();
        } catch (Exception) {
            return null;
        }
    }

    private function printHeader(string $dateStr, string $start, string $end): void
    {
        $this->info('========================================');
        $this->info('📊 PPSW 投注資料管線一致性比對');
        $this->info('========================================');
        $this->info('查詢日期：' . $dateStr);
        $this->info('時間範圍：' . $start . ' ～ ' . $end);
        $this->info('Vendor ID：' . self::VENDOR_ID);
        $this->info('========================================');
        $this->newLine();
    }
}
