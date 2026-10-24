<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PragmaticPlay\RetrieveRecords;

use App\Console\Commands\Vendor\PragmaticPlay\Services\CompareGameRoundsWithBetRecordsService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 比對 ppsw_game_rounds 與 ppsw_bet_records 的資料差異
 *
 * 使用方式：php artisan ppsw:compare-records {date}
 * 範例：php artisan ppsw:compare-records 2025-12-12
 */
class CompareGameRoundsWithBetRecords extends Command
{
    private const int CONSOLE_TABLE_MAX_ROWS_DEFAULT = 50;

    private const int CONSOLE_TABLE_MAX_ROWS_AMOUNT_DIFF = 10;

    protected $signature = 'ppsw:compare-records {date}';

    protected $description = '比對 ppsw_game_rounds 與 ppsw_bet_records 的資料差異';

    private CompareGameRoundsWithBetRecordsService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new CompareGameRoundsWithBetRecordsService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dateStr = (string) $this->argument('date');

        $date = $this->parseDateArgument($dateStr);
        if ($date === null) {
            $this->error('❌ 日期格式錯誤，請使用 Y-m-d 格式（例如：2025-11-18）');

            return 1;
        }

        $this->info('========================================');
        $this->info('📊 比對遊戲輪次資料與投注記錄');
        $this->info('========================================');
        $this->info('查詢日期: ' . $date->format('Y-m-d'));
        $this->info('========================================');
        $this->newLine();

        // 1. 檢查資料表是否存在
        $missingTables = $this->service->missingTables();
        if (!empty($missingTables)) {
            $this->error('❌ 找不到以下資料表：' . implode(', ', $missingTables));

            return 1;
        }

        // 2. 取得日期範圍（避免 Carbon addDay 造成 $date 本身被 mutate）
        [$startDate, $endDate] = $this->makeDateRange($date);

        // 3. 顯示兩個資料表的投注額加總
        $this->displayTableSummary($startDate, $endDate);

        // 4. 執行比對
        $this->info('🔍 開始比對資料...');
        $this->newLine();

        try {
            $onlyInGameRounds = $this->service->findOnlyInGameRounds($startDate, $endDate);
            $onlyInBetRecords = $this->service->findOnlyInBetRecords($startDate, $endDate);

            $amountDiffResult = $this->service->findAmountDifferences($startDate, $endDate);
            $amountDifferences = $amountDiffResult['records'];

            $subRoundRecords = $this->service->findBetRecordsWithSubRounds($startDate, $endDate);

            // 4.5 分析統計差異來源（跨日結算 / 時間維度不一致）
            $summaryDiffAnalysis = $this->service->analyzeSummaryDifferences($startDate, $endDate);
        } catch (Throwable $e) {
            $this->error('❌ 比對過程發生例外：' . $e->getMessage());

            return 1;
        }

        // 5. 輸出結果
        $this->displayResults($onlyInGameRounds, $onlyInBetRecords, $amountDifferences, $subRoundRecords, $dateStr);

        // 6. 只有在發現「數據異常」時才產生報告檔案
        //    subRoundRecords 為正常業務邏輯（子回合），不視為異常，不應單獨觸發報告
        $hasIssues = !empty($onlyInGameRounds) || !empty($onlyInBetRecords) || !empty($amountDifferences);
        if ($hasIssues) {
            $this->generateReport($onlyInGameRounds, $onlyInBetRecords, $amountDifferences, $subRoundRecords, $dateStr, $summaryDiffAnalysis);
        } else {
            $this->line('📄 未發現數據異常，不產生報告檔案。');
        }

        return 0;
    }

    private function parseDateArgument(string $dateStr): ?Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $dateStr)->startOfDay();
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function makeDateRange(Carbon $date): array
    {
        $start = $date->copy()->startOfDay();
        $end = $start->copy()->addDay();

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /**
     * 顯示兩個資料表的投注額加總
     * 使用 game_rounds.endDate 作為時間範圍，符合遊戲商後台報表數據
     */
    private function displayTableSummary(string $startDate, string $endDate): void
    {
        $this->info('📊 資料表統計摘要');
        $this->info(str_repeat('=', 80));
        $this->newLine();

        $summary = $this->service->getTableSummary($startDate, $endDate);
        $gameRoundsStats = $summary['game_rounds'];
        $betRecordsStats = $summary['bet_records'];

        // 3. 顯示統計表格
        $this->table(
            ['資料表', '記錄數', '總投注', '總輸贏', '總盈虧'],
            [
                [
                    CompareGameRoundsWithBetRecordsService::TABLE_GAME_ROUNDS,
                    number_format((float) $gameRoundsStats['total_count']),
                    number_format((float) $gameRoundsStats['total_bet'], 2),
                    number_format((float) $gameRoundsStats['total_win'], 2),
                    number_format((float) $gameRoundsStats['total_profit'], 2),
                ],
                [
                    CompareGameRoundsWithBetRecordsService::TABLE_BET_RECORDS,
                    number_format((float) $betRecordsStats['total_count']),
                    number_format((float) $betRecordsStats['total_bet'], 2),
                    number_format((float) $betRecordsStats['total_win'], 2),
                    number_format((float) $betRecordsStats['total_profit'], 2),
                ],
            ]
        );

        // 4. 計算差異
        $countDiff = abs($gameRoundsStats['total_count'] - $betRecordsStats['total_count']);
        $betDiff = abs($gameRoundsStats['total_bet'] - $betRecordsStats['total_bet']);
        $winDiff = abs($gameRoundsStats['total_win'] - $betRecordsStats['total_win']);
        $profitDiff = abs($gameRoundsStats['total_profit'] - $betRecordsStats['total_profit']);

        // 5. 顯示差異
        if ($countDiff > 0 || $betDiff > 0 || $winDiff > 0 || $profitDiff > 0) {
            $this->newLine();
            $this->warn('⚠️  兩表差異：');
            $this->table(
                ['項目', '差異值'],
                [
                    ['記錄數差異', number_format($countDiff)],
                    ['投注額差異', number_format($betDiff, 2)],
                    ['輸贏額差異', number_format($winDiff, 2)],
                    ['盈虧差異', number_format($profitDiff, 2)],
                ]
            );
        } else {
            $this->newLine();
            $this->info('✅ 兩表統計數據一致！');
        }

        $this->newLine();
        $this->info(str_repeat('=', 80));
        $this->newLine();
    }

    /**
     * 顯示比對結果
     */
    private function displayResults(array $onlyInGameRounds, array $onlyInBetRecords, array $amountDifferences, array $subRoundRecords, string $date): void
    {
        $this->newLine();
        $this->info('========================================');
        $this->info('📊 比對結果統計');
        $this->info('========================================');
        $this->info('日期: ' . $date);
        $this->newLine();

        // 1. 只存在於 game_rounds
        $this->warn('⚠️  只存在於 ppsw_game_rounds: ' . count($onlyInGameRounds) . ' 筆');
        $this->renderConsoleTableWithLimit(
            $onlyInGameRounds,
            self::CONSOLE_TABLE_MAX_ROWS_DEFAULT,
            ['playSessionID', 'ExtPlayerID', 'GameID', 'StartDate', 'EndDate', 'Bet', 'Win'],
            static function ($record) {
                return [
                    $record->playSessionID,
                    $record->extPlayerID,
                    $record->gameID,
                    $record->startDate,
                    $record->endDate,
                    $record->bet,
                    $record->win,
                ];
            }
        );
        $this->newLine();

        // 2. 只存在於 bet_records
        $this->warn('⚠️  只存在於 ppsw_bet_records: ' . count($onlyInBetRecords) . ' 筆');
        $this->renderConsoleTableWithLimit(
            $onlyInBetRecords,
            self::CONSOLE_TABLE_MAX_ROWS_DEFAULT,
            ['BetID', 'MemberID', 'GameCode', 'BetTime', 'PayoutTime', 'Bet', 'Win'],
            static function ($record) {
                return [
                    $record->bet_id,
                    $record->member_id,
                    $record->game_code,
                    $record->bet_time,
                    $record->payout_time,
                    $record->bet,
                    $record->win,
                ];
            }
        );
        $this->newLine();

        // 3. 金額有差異
        $this->warn('⚠️  金額有差異: ' . count($amountDifferences) . ' 筆');
        $this->renderConsoleTableWithLimit(
            $amountDifferences,
            self::CONSOLE_TABLE_MAX_ROWS_AMOUNT_DIFF,
            ['PlaySessionID', 'ParentSessionID', 'ExtPlayerID', 'GameID', 'GR_Bet', 'BR_Bet', 'GR_Win', 'BR_Win'],
            static function ($record) {
                return [
                    $record->playSessionID,
                    $record->parentSessionID,
                    $record->extPlayerID,
                    $record->gameID,
                    $record->gr_bet,
                    $record->br_bet,
                    $record->gr_win,
                    $record->br_win,
                ];
            }
        );
        $this->newLine();

        // 4. bet_records 對應子回合的記錄
        $this->info('ℹ️  bet_records 對應子回合（Free Spin 等）: ' . count($subRoundRecords) . ' 筆');
        $this->renderConsoleTableWithLimit(
            $subRoundRecords,
            self::CONSOLE_TABLE_MAX_ROWS_AMOUNT_DIFF,
            ['BetID', 'MemberID', 'GameCode', 'ParentSessionID', 'Bet', 'Win'],
            static function ($record) {
                return [
                    $record->bet_id,
                    $record->member_id,
                    $record->game_code,
                    $record->parentSessionID,
                    $record->bet,
                    $record->win,
                ];
            }
        );
        if (count($subRoundRecords) > 0) {
            $this->line('  註: 這些記錄對應的是子回合（如 Free Spin），在統計摘要中會造成差異，但這是正常的業務邏輯。');
        }
        $this->newLine();

        // 總計
        $totalIssues = count($onlyInGameRounds) + count($onlyInBetRecords) + count($amountDifferences);
        if ($totalIssues === 0) {
            $this->info('✅ 沒有發現數據異常！');
            if (count($subRoundRecords) > 0) {
                $this->info('   （統計差異是由 ' . count($subRoundRecords) . ' 筆子回合記錄造成，這是正常情況）');
            }
        } else {
            $this->error('❌ 共發現 ' . $totalIssues . ' 筆數據異常');
            $this->displayBackfillHint($onlyInBetRecords, $amountDifferences, $date);
        }
        $this->info('========================================');
    }

    /**
     * 偵測到漏單時，提示使用 --missing-only 補帳命令
     */
    private function displayBackfillHint(array $onlyInBetRecords, array $amountDifferences, string $date): void
    {
        if (empty($onlyInBetRecords)) {
            return;
        }

        $this->newLine();
        $this->warn('💡 補帳建議');
        $this->line(sprintf(
            '   偵測到 %d 筆只存在於 bet_records，可使用以下命令精準補拉缺漏的 timepoint：',
            count($onlyInBetRecords)
        ));
        $this->newLine();
        $this->line(sprintf('   <fg=cyan>php artisan ppsw:game-rounds %s --missing-only</>', $date));
        $this->newLine();
        $this->line('   補拉完成後再次執行本命令，預期 onlyInBetRecords 應降至 0。');

        if (!empty($amountDifferences)) {
            $this->newLine();
            $this->line(sprintf(
                '   ⚠️  另有 %d 筆金額不一致無法被 --missing-only 修復',
                count($amountDifferences)
            ));
            $this->line('       （INSERT IGNORE 不覆寫已存在記錄，需先手動 DELETE 該 playSessionID 再補拉）');
        }
    }

    /**
     * 產生報告檔案
     */
    private function generateReport(
        array $onlyInGameRounds,
        array $onlyInBetRecords,
        array $amountDifferences,
        array $subRoundRecords,
        string $date,
        array $summaryDiffAnalysis = []
    ): void {
        $reportPath = storage_path('logs/ppsw_comparison_' . $date . '_' . now()->format('YmdHis') . '.log');

        $content = array_merge(
            $this->buildReportHeader($date),
            $this->buildSummaryAnalysisSection($summaryDiffAnalysis),
            $this->buildReconciliationSection($summaryDiffAnalysis, $onlyInBetRecords),
            $this->buildOnlyInGameRoundsSection($onlyInGameRounds),
            $this->buildOnlyInBetRecordsSection($onlyInBetRecords),
            $this->buildAmountDifferencesSection($amountDifferences),
            $this->buildSubRoundsSection($subRoundRecords),
        );

        try {
            $bytes = file_put_contents($reportPath, implode("\n", $content));
            if ($bytes === false) {
                throw new Exception('file_put_contents returned false');
            }
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('❌ 報告寫入失敗：' . $e->getMessage());
            $this->error('   Path: ' . $reportPath);

            return;
        }

        $this->newLine();
        $this->info('📄 報告已儲存至: ' . $reportPath);
    }

    /** @return array<int,string> */
    private function buildReportHeader(string $date): array
    {
        return [
            '========================================',
            'PPSW 資料比對報告',
            '========================================',
            '日期: ' . $date,
            '產生時間: ' . now()->format('Y-m-d H:i:s'),
            '========================================',
            '',
        ];
    }

    /** @return array<int,string> */
    private function buildSummaryAnalysisSection(array $summaryDiffAnalysis): array
    {
        if (empty($summaryDiffAnalysis)) {
            return [];
        }

        $lines = [];
        $lines[] = '【0】摘要差異原因分析（投注額差異/輸贏差異）';
        $lines[] = str_repeat('-', 80);

        $summary = $summaryDiffAnalysis['summary'] ?? [];
        if (!empty($summary)) {
            $lines[] = sprintf('摘要投注額差異: %s', number_format((float) ($summary['bet_diff'] ?? 0), 2));
            $lines[] = sprintf('  game_rounds(endDate) 總投注: %s', number_format((float) ($summary['game_rounds_total_bet'] ?? 0), 2));
            $lines[] = sprintf('  bet_records(payout_time) 總投注: %s', number_format((float) ($summary['bet_records_total_bet'] ?? 0), 2));
            $lines[] = sprintf('摘要輸贏差異: %s', number_format((float) ($summary['win_diff'] ?? 0), 2));
            $lines[] = '';
        }

        $crossDay = $summaryDiffAnalysis['cross_day'] ?? [];
        $missingGr = $summaryDiffAnalysis['missing_game_round'] ?? [];
        $missingBr = $summaryDiffAnalysis['missing_bet_record'] ?? [];
        $reverseCrossDay = $summaryDiffAnalysis['reverse_cross_day'] ?? [];

        $lines[] = sprintf('（A）跨日結算：payout_time 在當天，但 game_rounds.endDate 不在當天：%d 筆', (int) ($crossDay['count'] ?? 0));
        $lines[] = '     這些記錄會被計入 bet_records 當日統計，但不會被計入 game_rounds 當日統計';
        $lines[] = sprintf('     bet_records 總投注加總（跨日）: %s', number_format((float) ($crossDay['bet_sum'] ?? 0), 2));
        $lines[] = sprintf('     bet_records 總輸贏加總（跨日）: %s', number_format((float) ($crossDay['win_sum'] ?? 0), 2));
        $lines[] = '';

        $lines[] = sprintf('（B）疑似漏抓：payout_time 在當天，但找不到任何 game_rounds：%d 筆', (int) ($missingGr['count'] ?? 0));
        $lines[] = sprintf('     bet_records 總投注加總（漏抓）: %s', number_format((float) ($missingGr['bet_sum'] ?? 0), 2));
        $lines[] = sprintf('     bet_records 總輸贏加總（漏抓）: %s', number_format((float) ($missingGr['win_sum'] ?? 0), 2));
        $lines[] = '';

        $lines[] = sprintf('（C）疑似漏抓：endDate 在當天，但找不到任何 bet_records（摘要 inner join 會排除）：%d 筆', (int) ($missingBr['count'] ?? 0));
        $lines[] = sprintf('     game_rounds 總投注加總（缺 bet_records）: %s', number_format((float) ($missingBr['bet_sum'] ?? 0), 2));
        $lines[] = sprintf('     game_rounds 總輸贏加總（缺 bet_records）: %s', number_format((float) ($missingBr['win_sum'] ?? 0), 2));
        $lines[] = '';

        $lines[] = sprintf('（D）反向跨日：endDate 在當天，但 bet_records.payout_time 不在當天（或為空）：%d 筆', (int) ($reverseCrossDay['count'] ?? 0));
        $lines[] = '     這些記錄會被計入 game_rounds 當日統計，但不會被計入 bet_records 當日統計';
        $lines[] = sprintf('     game_rounds 總投注加總（反向跨日）: %s', number_format((float) ($reverseCrossDay['bet_sum'] ?? 0), 2));
        $lines[] = sprintf('     game_rounds 總輸贏加總（反向跨日）: %s', number_format((float) ($reverseCrossDay['win_sum'] ?? 0), 2));

        if (!empty($reverseCrossDay['records'])) {
            $lines[] = '';
            $lines[] = '     反向跨日清單（前 50 筆，依 gr.bet 由大到小）:';
            foreach (array_slice($reverseCrossDay['records'], 0, 50) as $r) {
                $lines[] = sprintf(
                    '     BetID: %s | MemberID: %s | GameCode: %s | GR_EndDate: %s | PayoutTime: %s | GR_Bet: %s | BR_Bet: %s | GR_Win: %s | BR_Win: %s | GR_ParentSessionID: %s',
                    $r->bet_id,
                    $r->member_id,
                    $r->game_code,
                    $r->gr_endDate,
                    $r->payout_time,
                    $r->gr_bet,
                    $r->br_bet,
                    $r->gr_win,
                    $r->br_win,
                    $r->parentSessionID
                );
            }
        }

        return $lines;
    }

    /** @return array<int,string> */
    private function buildReconciliationSection(array $summaryDiffAnalysis, array $onlyInBetRecords): array
    {
        if (empty($summaryDiffAnalysis)) {
            return [];
        }

        $summary = $summaryDiffAnalysis['summary'] ?? [];
        $crossDay = $summaryDiffAnalysis['cross_day'] ?? [];
        $missingGr = $summaryDiffAnalysis['missing_game_round'] ?? [];
        $reverseCrossDay = $summaryDiffAnalysis['reverse_cross_day'] ?? [];

        $summaryBetDiff = (float) (($summary['bet_diff'] ?? 0) ?: 0);
        $reverseBetSum = (float) (($reverseCrossDay['bet_sum'] ?? 0) ?: 0);
        $crossDayBetSum = (float) (($crossDay['bet_sum'] ?? 0) ?: 0);
        $missingGrBetSum = (float) (($missingGr['bet_sum'] ?? 0) ?: 0);

        $onlyInBetRecordsBetSum = 0.0;
        foreach ($onlyInBetRecords as $r) {
            $onlyInBetRecordsBetSum += (float) ($r->bet ?? 0);
        }

        $expectedDiff = $reverseBetSum - $crossDayBetSum - $missingGrBetSum - $onlyInBetRecordsBetSum;
        $delta = abs($summaryBetDiff - $expectedDiff);

        $lines = [];
        $lines[] = '';
        $lines[] = '【0-1】投注額差異對帳驗證（依摘要統計口徑推導）';
        $lines[] = str_repeat('-', 80);
        $lines[] = sprintf('摘要投注額差異（實際）: %s', number_format($summaryBetDiff, 2));
        $lines[] = sprintf('推導投注額差異（預期）: %s', number_format($expectedDiff, 2));
        $lines[] = '推導公式：D(反向跨日) - A(跨日結算) - B(當天 payout 缺 game_round) - onlyInBetRecords';
        $lines[] = sprintf('  D: %s', number_format($reverseBetSum, 2));
        $lines[] = sprintf('  A: %s', number_format($crossDayBetSum, 2));
        $lines[] = sprintf('  B: %s', number_format($missingGrBetSum, 2));
        $lines[] = sprintf('  onlyInBetRecords: %s', number_format($onlyInBetRecordsBetSum, 2));
        $lines[] = '';
        $lines[] = sprintf('對帳差額（|實際-預期|）: %s', number_format($delta, 6));
        $lines[] = $delta < 0.0001
            ? '✅ 對帳成功：摘要投注額差異可由上述原因完整解釋。'
            : '⚠️ 對帳未完全吻合：可能存在其他口徑差異或資料異常（建議再檢查 payout_time NULL、重複 bet_id、或資料精度）。';
        $lines[] = '';

        return $lines;
    }

    /** @return array<int,string> */
    private function buildOnlyInGameRoundsSection(array $onlyInGameRounds): array
    {
        $lines = [];
        $lines[] = '【1】只存在於 ppsw_game_rounds: ' . count($onlyInGameRounds) . ' 筆';
        $lines[] = str_repeat('-', 80);

        foreach ($onlyInGameRounds as $record) {
            $lines[] = sprintf(
                'PlaySessionID: %s | ParentSessionID: %s | ExtPlayerID: %s | GameID: %s | StartDate: %s | EndDate: %s | Bet: %s | Win: %s',
                $record->playSessionID,
                $record->parentSessionID,
                $record->extPlayerID,
                $record->gameID,
                $record->startDate,
                $record->endDate,
                $record->bet,
                $record->win
            );
        }

        $lines[] = '';

        return $lines;
    }

    /** @return array<int,string> */
    private function buildOnlyInBetRecordsSection(array $onlyInBetRecords): array
    {
        $lines = [];
        $lines[] = '【2】只存在於 ppsw_bet_records: ' . count($onlyInBetRecords) . ' 筆';
        $lines[] = str_repeat('-', 80);

        foreach ($onlyInBetRecords as $record) {
            $lines[] = sprintf(
                'BetID: %s | MemberID: %s | GameCode: %s | BetTime: %s | PayoutTime: %s | Bet: %s | Win: %s',
                $record->bet_id,
                $record->member_id,
                $record->game_code,
                $record->bet_time,
                $record->payout_time,
                $record->bet,
                $record->win
            );
        }

        $lines[] = '';

        return $lines;
    }

    /** @return array<int,string> */
    private function buildAmountDifferencesSection(array $amountDifferences): array
    {
        $lines = [];
        $lines[] = '【3】金額有差異: ' . count($amountDifferences) . ' 筆';
        $lines[] = str_repeat('-', 80);

        foreach ($amountDifferences as $record) {
            $lines[] = sprintf(
                'PlaySessionID: %s | ParentSessionID: %s | ExtPlayerID: %s | GameID: %s',
                $record->playSessionID,
                $record->parentSessionID,
                $record->extPlayerID,
                $record->gameID
            );
            $lines[] = sprintf('  GameRounds -> Bet: %s | Win: %s', $record->gr_bet, $record->gr_win);
            $lines[] = sprintf('  BetRecords -> Bet: %s | Win: %s', $record->br_bet, $record->br_win);
            $lines[] = '';
        }

        $lines[] = '';

        return $lines;
    }

    /** @return array<int,string> */
    private function buildSubRoundsSection(array $subRoundRecords): array
    {
        $lines = [];
        $lines[] = '【4】bet_records 對應子回合（Free Spin 等）: ' . count($subRoundRecords) . ' 筆';
        $lines[] = str_repeat('-', 80);
        $lines[] = '註: 這些記錄對應的是子回合，在統計摘要中會造成差異，但這是正常的業務邏輯。';
        $lines[] = '';

        if (empty($subRoundRecords)) {
            return $lines;
        }

        $totalBet = 0.0;
        $totalWin = 0.0;
        foreach ($subRoundRecords as $record) {
            $lines[] = sprintf(
                'BetID: %s | MemberID: %s | GameCode: %s | ParentSessionID: %s | Bet: %s | Win: %s',
                $record->bet_id,
                $record->member_id,
                $record->game_code,
                $record->parentSessionID,
                $record->bet,
                $record->win
            );
            $totalBet += (float) $record->bet;
            $totalWin += (float) $record->win;
        }

        $lines[] = '';
        $lines[] = sprintf('子回合總計 -> 總投注: %s | 總輸贏: %s', number_format($totalBet, 2), number_format($totalWin, 2));

        return $lines;
    }

    /**
     * Render a console table only when record count is within the given limit.
     * Keeps existing behavior: if count > limit, print a hint to check the report.
     *
     * @param  array<int,object>  $records
     * @param  array<int,string>  $headers
     * @param  callable(object):array<int,mixed>  $rowMapper
     */
    private function renderConsoleTableWithLimit(array $records, int $maxRows, array $headers, callable $rowMapper): void
    {
        $count = count($records);

        if ($count === 0) {
            return;
        }

        if ($count <= $maxRows) {
            $this->table($headers, array_map($rowMapper, $records));

            return;
        }

        $this->line('  （記錄過多，請查看報告檔案）');
    }
}
