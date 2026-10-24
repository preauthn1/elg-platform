<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\Astar;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Vendors\Models\AstarAccount;
use App\Vendors\Models\AstarBetRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 官方後台 > 報表查詢 > 遊戲紀錄查詢 > 導出 > 轉存 CSV 加上日期命名
 *
 * CSV 對帳
 * php artisan astar:check_bet_record
 *
 * 重新抓帳+統計指令
 * php artisan vendor_bet_record_rerun --vendor=101 --date=2025-08-18 --summary-disable=no
 *
 * 重新統計指令
 * php artisan vendor_summary_daily_rerun --date=2025-08-17 --vendor=101
 */
class CheckDailyBetRecord extends Command
{
    protected $signature = 'astar:check_bet_record';

    protected $description = '比對投注日報表';

    protected VendorIdEnum $vendorId = VendorIdEnum::ASTAR;

    protected string $accountClass = AstarAccount::class;

    protected string $betRecordClass = AstarBetRecord::class;

    /**
     * Execute the console command.
     *
     * @return void
     *
     * @noinspection D
     */
    public function handle()
    {
        $checkDate = $this->ask('請輸入對帳日期 (YYYY-MM-DD)');

        // 檢查日期格式
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkDate)) {
            $this->error('日期格式錯誤，請使用 YYYY-MM-DD 格式');

            return;
        }

        $thirdRecords = $this->getDataFromCsv($checkDate);
        $betRecords = $this->getBetRecords($checkDate);

        $totalWin = 0;
        $totalBet = 0;
        foreach ($thirdRecords as $thirdRecord) {
            $win = is_numeric($thirdRecord['win']) ? (float) $thirdRecord['win'] : 0;
            $bet = is_numeric($thirdRecord['bet']) ? (float) $thirdRecord['bet'] : 0;
            $totalWin += $win;
            $totalBet += $bet;
        }

        $this->info("三方總投注額: {$totalBet}, 三方總贏錢額: {$totalWin}");

        $totalWinLocal = 0;
        $totalBetLocal = 0;
        foreach ($betRecords as $betRecord) {
            $win = is_numeric($betRecord['win']) ? (float) $betRecord['win'] : 0;
            $bet = is_numeric($betRecord['bet']) ? (float) $betRecord['bet'] : 0;
            $totalWinLocal += $win;
            $totalBetLocal += $bet;
        }
        $this->warn("DB總投注額: {$totalBetLocal}, DB總贏錢額: {$totalWinLocal}");

        // 比對三方跟DB的投注紀錄
        foreach ($thirdRecords as $betId => $thirdRecord) {
            if (isset($betRecords[$betId])) {
                $betAmount = $thirdRecord['bet'];
                $winAmount = $thirdRecord['win'];
                $dbRecord = $betRecords[$betId];
                if (Common::numCompare((string) $betAmount, (string) $dbRecord['bet'], 4) != 0) {
                    $this->error("投注額不同, bet_id: {$betId}, 三方: {$betAmount}, DB: {$dbRecord['bet']}");
                } elseif (Common::numCompare((string) $winAmount, (string) $dbRecord['win'], 4) != 0) {
                    $this->error("贏錢額不同, bet_id: {$betId}, 三方: {$winAmount}, DB: {$dbRecord['win']}");
                }
            } else {
                $this->error("DB沒有找到投注紀錄, bet_id: {$betId}");
                // dump($thirdRecord);
            }
        }

        // 檢查DB投注紀錄是否有三方沒有的
        foreach ($betRecords as $betId => $betRecord) {
            if (!isset($thirdRecords[$betId])) {
                $this->error("三方沒有找到投注紀錄, bet_id: {$betId}");

                $win = is_numeric($betRecord['win']) ? (float) $betRecord['win'] : 0;
                $bet = is_numeric($betRecord['bet']) ? (float) $betRecord['bet'] : 0;
                $totalWinLocal -= $win;
                $totalBetLocal -= $bet;
            }
        }
        $this->warn("校正後：DB總投注額: {$totalBetLocal}, DB總贏錢額: {$totalWinLocal}");

        $this->info('done');
    }

    protected function getDataFromCsv(string $checkDate): array
    {
        $csvPath = base_path('app/Console/Commands/Astar/gameRecord_' . $checkDate . '.csv');

        if (!file_exists($csvPath)) {
            $this->error("檔案不存在: {$csvPath}");

            return [];
        }

        $csv = [];
        if (($handle = fopen($csvPath, 'r')) !== false) {
            while (($row = fgetcsv($handle)) !== false) {
                $csv[] = $row;
            }
            fclose($handle);
        } else {
            $this->error("無法開啟檔案: {$csvPath}");

            return [];
        }
        unset($csv[0]);

        $data = [];
        foreach ($csv as $row) {
            $betId = $row[3] . $row[0];

            $data[$betId] = [
                'bet' => $row[14],
                'win' => $row[15],
                'payout_time' => $row[9],
            ];

        }
        $this->line('整理成 ' . count($data) . ' 筆投注紀錄');

        return $data;
    }

    protected function getBetRecords(string $checkDate): array
    {
        // 查詢DB投注紀錄
        $startDate = Carbon::parse($checkDate . ' 12:00:00', 'Asia/Taipei');
        $endDate = $startDate->copy()->addDay();

        $startDate = $startDate->copy()->timezone('UTC')->toDateTimeString();
        $endDate = $endDate->copy()->timezone('UTC')->toDateTimeString();

        $betRecords = $this->betRecordClass::query()
            ->where('payout_time', '>=', $startDate)
            ->where('payout_time', '<', $endDate)
            ->get();

        $this->line("DB查詢到 {$betRecords->count()} 筆投注紀錄");

        return $betRecords->mapWithKeys(function ($item) {
            return [
                $item->bet_id => [
                    'bet' => $item->valid_bet,
                    'win' => $item->win_lose,
                    'payout_time' => $item->payout_time,
                ],
            ];
        })->toArray();
    }
}
