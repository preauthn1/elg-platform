<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\KissH5SW;

use App\Helpers\Common;
use App\Vendors\Models\KissH5SWBetRecord;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 將 KissH5 後台匯出的玩家投注 CSV 與我方 kissh5sw_bet_records 對帳。
 *
 * ⚠️  時區重點（容易踩雷）：
 *   - 單筆 callback 時間（TranDateTime）本身就是 UTC，我方 DB 也存 UTC，這部分沒問題
 *   - 但 KissH5 後台所有「日為單位」的資料 day boundary 都是 UTC+8（SGT）：
 *       後台報表、CSV 匯出、GetDaySummary 一律 SGT 日界
 *       （即便 GetDaySummary 文件寫 Date 用 UTC datetime，內部仍轉 SGT 算）
 *   - 對帳時必須把指定日期視為 SGT，換算成對應 UTC 區間再查 DB
 *       例：KissH5 的「2026-05-01」= UTC [2026-04-30 16:00, 2026-05-01 16:00)
 *   - 預設 --tz=Asia/Singapore；若日後對方換時區再改參數
 *
 * CSV 欄位（KissH5 格式）：
 *   Player Name, Currency, No Of Games, Valid Bet, Bet Amount,
 *   Return Amount, Game Income, Game Income (MYR), Game Payout
 *
 * 我方欄位對應：
 *   No Of Games   → COUNT(*)         （type=N, status=1, payout_time 在指定日）
 *   Bet Amount    → SUM(bet)
 *   Valid Bet     → SUM(valid_bet)
 *   Return Amount → SUM(win)
 *   Game Income   → -SUM(win_lose)   （KissH5 的 GameIncome 是業者視角）
 *
 * 使用方式：
 * php artisan kissh5sw:compare-players app/Console/Commands/Vendor/KissH5SW/Kiss_0501_players.csv 2026-05-01
 */
class ComparePlayers extends Command
{
    protected $signature = 'kissh5sw:compare-players
        {csv : KissH5 玩家投注 CSV 路徑（相對 base_path 或絕對路徑）}
        {date : 對應日期 Y-m-d}
        {--tz=Asia/Singapore : 日期所屬時區（KissH5 後台採 SGT，DB 存 UTC）}';

    protected $description = '比對 KissH5 後台玩家投注 CSV 與我方 kissh5sw_bet_records，列出有差異的玩家';

    private const string TOLERANCE = '0.0001';

    public function handle(): int
    {
        $csvArg = (string) $this->argument('csv');
        $csvPath = str_starts_with($csvArg, '/') ? $csvArg : base_path($csvArg);

        $tz = (string) $this->option('tz');
        $date = $this->parseDate((string) $this->argument('date'), $tz);
        if ($date === null) {
            $this->error('❌ 日期格式錯誤，請使用 Y-m-d');

            return self::FAILURE;
        }

        if (!is_file($csvPath)) {
            $this->error('❌ 找不到 CSV：' . $csvPath);

            return self::FAILURE;
        }

        // 把指定時區的整日邊界轉成 UTC（DB 存 UTC）
        $startUtc = $date->copy()->utc()->format('Y-m-d H:i:s');
        $endUtc = $date->copy()->addDay()->utc()->format('Y-m-d H:i:s');

        $this->info('========================================');
        $this->info('📊 KissH5SW 玩家對帳');
        $this->info('========================================');
        $this->info('日期: ' . $date->format('Y-m-d') . ' (' . $tz . ')');
        $this->info('查詢 UTC 範圍: ' . $startUtc . ' ~ ' . $endUtc);
        $this->info('CSV : ' . $csvPath);
        $this->newLine();

        try {
            $kiss = $this->parseCsv($csvPath);
            $local = $this->loadLocalAggregates($startUtc, $endUtc);
        } catch (Throwable $e) {
            $this->error('❌ 讀取資料失敗：' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('CSV 玩家數: ' . count($kiss) . '（不含 Total/Grand Total）');
        $this->info('我方玩家數: ' . count($local));
        $this->newLine();

        $diff = $this->computeDifferences($kiss, $local);

        $this->renderTotals($kiss, $local);
        $this->renderDiscrepancies($diff);

        if (!empty($diff)) {
            $reportPath = $this->writeReport($diff, $date->format('Y-m-d'));
            $this->newLine();
            $this->info('📄 差異清單已匯出：' . $reportPath);
            $this->line('   下一步：用此清單 × 玩家逐筆比對，找出有問題的注單');
        }

        return self::SUCCESS;
    }

    private function parseDate(string $s, string $tz): ?Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $s, $tz)->startOfDay();
        } catch (Exception) {
            return null;
        }
    }

    /**
     * 解析 KissH5 CSV，回傳 [username => ['cnt' => int, 'bet' => string, 'valid' => string, 'payout' => string, 'win_lose' => string]]
     * 注意：KissH5 的 Game Income 為業者視角；轉換成我方 win_lose（玩家視角）= -GameIncome
     */
    private function parseCsv(string $path): array
    {
        $fp = fopen($path, 'r');
        if ($fp === false) {
            throw new \RuntimeException('無法開啟 CSV');
        }

        $rows = [];
        $header = fgetcsv($fp); // 跳過標題列

        while (($row = fgetcsv($fp)) !== false) {
            if (count($row) < 7) {
                continue;
            }

            // 跳過 "Total" / "Grand Total" 列
            $name = trim((string) $row[0]);
            if ($name === '' || $name === 'Total' || $name === 'Grand Total') {
                continue;
            }

            $rows[$name] = [
                'cnt' => (int) $this->normalizeNumber((string) $row[2]),
                'valid' => $this->normalizeNumber((string) $row[3]),
                'bet' => $this->normalizeNumber((string) $row[4]),
                'payout' => $this->normalizeNumber((string) $row[5]),
                // 我方 win_lose（玩家視角）= -GameIncome（業者視角）
                'win_lose' => Common::numSub('0', $this->normalizeNumber((string) $row[6]), 4),
            ];
        }

        fclose($fp);

        return $rows;
    }

    /**
     * 把 "1,234.56" 轉為 "1234.5600"（4 位小數，便於與 decimal(18,4) 對齊）
     */
    private function normalizeNumber(string $s): string
    {
        $clean = str_replace([',', ' '], '', trim($s));
        if ($clean === '' || $clean === '-') {
            return '0.0000';
        }

        return Common::numAdd('0', $clean, 4);
    }

    /**
     * 從 DB 撈出當日各玩家的彙總（type=N, status=1，按 payout_time）
     *
     * @return array<string, array{cnt:int, bet:string, valid:string, payout:string, win_lose:string}>
     */
    private function loadLocalAggregates(string $startDate, string $endDate): array
    {
        $rows = KissH5SWBetRecord::query()
            ->where('type', 'N')
            ->where('status', 1)
            ->where('payout_time', '>=', $startDate)
            ->where('payout_time', '<', $endDate)
            ->selectRaw('username, COUNT(*) as cnt, SUM(bet) as bet, SUM(valid_bet) as valid, SUM(win) as payout, SUM(win_lose) as win_lose')
            ->groupBy('username')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->username] = [
                'cnt' => (int) $r->cnt,
                'bet' => Common::numAdd('0', (string) $r->bet, 4),
                'valid' => Common::numAdd('0', (string) $r->valid, 4),
                'payout' => Common::numAdd('0', (string) $r->payout, 4),
                'win_lose' => Common::numAdd('0', (string) $r->win_lose, 4),
            ];
        }

        return $out;
    }

    /**
     * 比對兩邊資料，回傳有差異的玩家陣列。
     */
    private function computeDifferences(array $kiss, array $local): array
    {
        $diff = [];
        $allUsernames = array_unique(array_merge(array_keys($kiss), array_keys($local)));

        foreach ($allUsernames as $username) {
            $k = $kiss[$username] ?? null;
            $l = $local[$username] ?? null;

            $kCnt = $k['cnt'] ?? 0;
            $lCnt = $l['cnt'] ?? 0;
            $kBet = $k['bet'] ?? '0.0000';
            $lBet = $l['bet'] ?? '0.0000';
            $kPayout = $k['payout'] ?? '0.0000';
            $lPayout = $l['payout'] ?? '0.0000';
            $kWl = $k['win_lose'] ?? '0.0000';
            $lWl = $l['win_lose'] ?? '0.0000';

            $betDiff = Common::numSub($kBet, $lBet, 4);
            $payoutDiff = Common::numSub($kPayout, $lPayout, 4);
            $wlDiff = Common::numSub($kWl, $lWl, 4);

            $cntDiff = $kCnt - $lCnt;
            $hasMoneyDiff = Common::numCompare(ltrim($betDiff, '-'), self::TOLERANCE) > 0
                || Common::numCompare(ltrim($payoutDiff, '-'), self::TOLERANCE) > 0
                || Common::numCompare(ltrim($wlDiff, '-'), self::TOLERANCE) > 0;

            if ($cntDiff === 0 && !$hasMoneyDiff) {
                continue;
            }

            $diff[] = [
                'username' => $username,
                'kiss_cnt' => $kCnt,
                'local_cnt' => $lCnt,
                'cnt_diff' => $cntDiff,
                'kiss_bet' => $kBet,
                'local_bet' => $lBet,
                'bet_diff' => $betDiff,
                'kiss_payout' => $kPayout,
                'local_payout' => $lPayout,
                'payout_diff' => $payoutDiff,
                'kiss_win_lose' => $kWl,
                'local_win_lose' => $lWl,
                'win_lose_diff' => $wlDiff,
                'only_in_kiss' => $l === null,
                'only_in_local' => $k === null,
            ];
        }

        // 按 bet_diff 絕對值大到小排序
        usort($diff, fn ($a, $b) => Common::numCompare(ltrim($b['bet_diff'], '-'), ltrim($a['bet_diff'], '-')));

        return $diff;
    }

    private function renderTotals(array $kiss, array $local): void
    {
        $sumK = ['cnt' => 0, 'bet' => '0', 'valid' => '0', 'payout' => '0', 'win_lose' => '0'];
        foreach ($kiss as $r) {
            $sumK['cnt'] += $r['cnt'];
            $sumK['bet'] = Common::numAdd($sumK['bet'], $r['bet'], 4);
            $sumK['valid'] = Common::numAdd($sumK['valid'], $r['valid'], 4);
            $sumK['payout'] = Common::numAdd($sumK['payout'], $r['payout'], 4);
            $sumK['win_lose'] = Common::numAdd($sumK['win_lose'], $r['win_lose'], 4);
        }

        $sumL = ['cnt' => 0, 'bet' => '0', 'valid' => '0', 'payout' => '0', 'win_lose' => '0'];
        foreach ($local as $r) {
            $sumL['cnt'] += $r['cnt'];
            $sumL['bet'] = Common::numAdd($sumL['bet'], $r['bet'], 4);
            $sumL['valid'] = Common::numAdd($sumL['valid'], $r['valid'], 4);
            $sumL['payout'] = Common::numAdd($sumL['payout'], $r['payout'], 4);
            $sumL['win_lose'] = Common::numAdd($sumL['win_lose'], $r['win_lose'], 4);
        }

        $this->info('📊 全日總計');
        $this->table(
            ['項目', 'KissH5', '我方', '差異 (K - 我)'],
            [
                ['注單數', number_format($sumK['cnt']), number_format($sumL['cnt']), number_format($sumK['cnt'] - $sumL['cnt'])],
                ['Bet', $sumK['bet'], $sumL['bet'], Common::numSub($sumK['bet'], $sumL['bet'], 4)],
                ['Valid Bet', $sumK['valid'], $sumL['valid'], Common::numSub($sumK['valid'], $sumL['valid'], 4)],
                ['Payout', $sumK['payout'], $sumL['payout'], Common::numSub($sumK['payout'], $sumL['payout'], 4)],
                ['WinLose（玩家）', $sumK['win_lose'], $sumL['win_lose'], Common::numSub($sumK['win_lose'], $sumL['win_lose'], 4)],
            ]
        );
        $this->newLine();
    }

    private function renderDiscrepancies(array $diff): void
    {
        if (empty($diff)) {
            $this->info('✅ 所有玩家都對齊，沒有差異！');

            return;
        }

        $this->warn('⚠️  共 ' . count($diff) . ' 位玩家有差異（按 Bet 差絕對值由大到小）');

        $top = array_slice($diff, 0, 20);
        $this->table(
            ['Player', 'K5_N', '我_N', 'ΔN', 'K5_Bet', '我_Bet', 'ΔBet', 'ΔPayout', 'ΔWinLose'],
            array_map(fn ($r) => [
                $r['username'],
                $r['kiss_cnt'],
                $r['local_cnt'],
                $r['cnt_diff'],
                $r['kiss_bet'],
                $r['local_bet'],
                $r['bet_diff'],
                $r['payout_diff'],
                $r['win_lose_diff'],
            ], $top)
        );

        if (count($diff) > 20) {
            $this->line('（只顯示前 20 名，其餘請見匯出檔案）');
        }
    }

    private function writeReport(array $diff, string $date): string
    {
        $path = storage_path('logs/kissh5sw_compare_players_' . $date . '_' . now()->format('YmdHis') . '.csv');

        $fp = fopen($path, 'w');
        if ($fp === false) {
            throw new \RuntimeException('無法開啟報告檔案：' . $path);
        }

        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, [
            'username',
            'kiss_cnt', 'local_cnt', 'cnt_diff',
            'kiss_bet', 'local_bet', 'bet_diff',
            'kiss_payout', 'local_payout', 'payout_diff',
            'kiss_win_lose', 'local_win_lose', 'win_lose_diff',
            'only_in_kiss', 'only_in_local',
        ]);

        foreach ($diff as $r) {
            fputcsv($fp, [
                $r['username'],
                $r['kiss_cnt'], $r['local_cnt'], $r['cnt_diff'],
                $r['kiss_bet'], $r['local_bet'], $r['bet_diff'],
                $r['kiss_payout'], $r['local_payout'], $r['payout_diff'],
                $r['kiss_win_lose'], $r['local_win_lose'], $r['win_lose_diff'],
                $r['only_in_kiss'] ? '1' : '0',
                $r['only_in_local'] ? '1' : '0',
            ]);
        }

        fclose($fp);

        return $path;
    }
}
