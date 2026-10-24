<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PlayTech;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Vendors\Models\PtswAccount;
use App\Vendors\Models\PtswBetRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 三方報表使用 UTC+8 時區並以 bet_time 作為投注時間依據
 *
 * php artisan playtech:check_bet_record --no-cache
 */
class CheckDailyBetRecord extends Command
{
    protected $signature = 'playtech:check_bet_record {--no-cache : 不使用緩存，強制重新抓取數據}';

    protected $description = '比對投注日報表';

    protected VendorIdEnum $vendorId = VendorIdEnum::PTSW;

    protected VendorInfo $vendorInfo;

    protected string $accountClass = PtswAccount::class;

    protected string $betRecordClass = PtswBetRecord::class;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // 1. 環境確認
        if (!$this->confirmEnvironment()) {
            return self::SUCCESS;
        }

        // 2. 初始化配置
        if (!$this->initializeVendorInfo()) {
            return self::FAILURE;
        }

        // 3. 獲取並驗證對帳日期
        $checkDate = $this->getValidatedCheckDate();
        if ($checkDate === null) {
            return self::FAILURE;
        }

        // 4. 確定緩存策略
        $useCache = $this->determineCacheStrategy();

        // 5. 獲取三方和 DB 數據
        $thirdRecords = $this->getDailyReport($this->vendorInfo->info, $checkDate, $useCache);

        try {
            $betRecords = $this->getBetRecords($checkDate, $useCache);
        } catch (Throwable $e) {
            $this->error('獲取 DB 投注紀錄失敗，程序中止');

            return self::FAILURE;
        }

        // 6. 計算統計數據
        $thirdStats = $this->calculateStatistics($thirdRecords);
        $dbStats = $this->calculateStatistics($betRecords);

        // 7. 顯示統計摘要
        $this->displayStatisticsSummary($thirdStats, $dbStats);

        // 8. 詳細比對投注記錄
        $this->compareRecordsInDetail($thirdRecords, $betRecords);

        $this->info('done');

        return self::SUCCESS;
    }

    /**
     * 確認執行環境
     */
    private function confirmEnvironment(): bool
    {
        if (in_array(strtolower((string) config('database.default')), ['mysql', 'dev', 'testing'], true)) {
            return true;
        }

        $confirm = $this->confirm('目前資料庫連線為 ' . config('database.default') . '，確定要繼續？', false);

        if (!$confirm) {
            $this->info('腳本已終止');
        }

        return $confirm;
    }

    /**
     * 初始化遊戲商資訊
     */
    private function initializeVendorInfo(): bool
    {
        try {
            $this->vendorInfo = VendorInfo::query()
                ->where('vendor_id', $this->vendorId)
                ->where('status', 1)
                ->firstOrFail();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return false;
        }

        $info = $this->vendorInfo->info;

        if (!isset($info['API_URL'], $info['ADMIN_KEY'])) {
            $this->error('缺少必要的 API 配置（API_URL 或 ADMIN_KEY）');

            return false;
        }

        return true;
    }

    /**
     * 獲取並驗證對帳日期
     */
    private function getValidatedCheckDate(): ?string
    {
        $checkDate = $this->ask('請輸入對帳日期 (YYYY-MM-DD)');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkDate)) {
            $this->error('日期格式錯誤，請使用 YYYY-MM-DD 格式');

            return null;
        }

        return $checkDate;
    }

    /**
     * 確定緩存策略
     */
    private function determineCacheStrategy(): bool
    {
        $useCache = !$this->option('no-cache');

        if ($useCache) {
            $this->info('使用緩存模式，如需重新抓取請使用 --no-cache 選項');
        } else {
            $this->warn('已禁用緩存，將重新抓取數據');
        }

        return $useCache;
    }

    /**
     * 計算統計數據
     *
     * 使用 Common::numAdd（底層 bcadd）累加，避免 PHP 浮點數精度誤差。
     *
     * @return array{totalBet: string, totalWin: string}
     */
    private function calculateStatistics(array $records): array
    {
        $totalWin = '0';
        $totalBet = '0';

        foreach ($records as $record) {
            $totalWin = Common::numAdd($totalWin, $record['win'], 2);
            $totalBet = Common::numAdd($totalBet, $record['bet'], 2);
        }

        return [
            'totalBet' => $totalBet,
            'totalWin' => $totalWin,
        ];
    }

    /**
     * 顯示統計摘要
     */
    private function displayStatisticsSummary(array $thirdStats, array $dbStats): void
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 統計摘要');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info("三方總投注額: {$thirdStats['totalBet']}, 三方總贏錢額: {$thirdStats['totalWin']}");
        $this->warn("DB總投注額: {$dbStats['totalBet']}, DB總贏錢額: {$dbStats['totalWin']}");

        // 計算差異
        $betDiff = Common::numSub((string) $thirdStats['totalBet'], (string) $dbStats['totalBet'], 2);
        $winDiff = Common::numSub((string) $thirdStats['totalWin'], (string) $dbStats['totalWin'], 2);

        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // 顯示投注額差異
        $this->displayDifference('投注額', $betDiff);

        // 顯示贏錢額差異
        $this->displayDifference('贏錢額', $winDiff);

        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('');
    }

    /**
     * 顯示差異資訊
     */
    private function displayDifference(string $label, string $diff): void
    {
        if (Common::numCompare($diff, '0', 2) === 0) {
            $this->info("✅ {$label}差異: 0 （完全一致）");
        } else {
            $sign = Common::numCompare($diff, '0', 2) > 0 ? '+' : '';
            $this->error("❌ {$label}差異: {$sign}{$diff} （三方 - DB）");
        }
    }

    /**
     * 詳細比對投注記錄
     */
    private function compareRecordsInDetail(array $thirdRecords, array $betRecords): void
    {
        // 比對三方記錄
        foreach ($thirdRecords as $betId => $thirdRecord) {
            if (isset($betRecords[$betId])) {
                $this->compareIndividualRecord($betId, $thirdRecord, $betRecords[$betId]);
            } else {
                $this->error("DB沒有找到投注紀錄, bet_id: {$betId}");
                // dump($thirdRecord);
            }
        }

        // 檢查 DB 記錄是否有三方沒有的
        foreach ($betRecords as $betId => $betRecord) {
            if (!isset($thirdRecords[$betId])) {
                $this->line("三方沒有找到投注紀錄, bet_id:{$betId}", 'error');
            }
        }
    }

    /**
     * 比對單筆記錄
     */
    private function compareIndividualRecord(string $betId, array $thirdRecord, array $dbRecord): void
    {
        $betAmount = $thirdRecord['bet'];
        $winAmount = $thirdRecord['win'];

        if (Common::numCompare((string) $betAmount, (string) $dbRecord['bet'], 2) !== 0) {
            $this->error("投注額不同, bet_id: {$betId}, 三方: {$betAmount}, DB: {$dbRecord['bet']}");
        } elseif (Common::numCompare((string) $winAmount, (string) $dbRecord['win'], 2) !== 0) {
            $this->error("贏錢額不同, bet_id: {$betId}, 三方: {$winAmount}, DB: {$dbRecord['win']}");
        }
    }

    /** @noinspection D */
    protected function getDailyReport(array $info, string $checkDate, bool $useCache = true): array
    {
        // 生成緩存 key
        $cacheKey = "playtech:daily_report:{$checkDate}";

        // 如果使用緩存且緩存存在，直接返回
        if ($useCache && Cache::has($cacheKey)) {
            $cachedData = Cache::get($cacheKey);
            $this->info('✓ 從緩存讀取數據（共 ' . count($cachedData) . ' 個遊戲回合）');

            return $cachedData;
        }

        $this->info('開始從 API 抓取數據...');

        $headers = [
            'x-auth-admin-key: ' . $info['ADMIN_KEY'],
        ];

        $url = rtrim($info['API_URL'], '/');
        $url .= '/reports/playerGameTransactionReport';

        $data = [];
        $currentPage = 1;
        $totalPages = 1;
        $failedPages = []; // 記錄失敗的頁面

        do {
            $fields = [
                'bet_start_date' => $checkDate . ' 00:00:00',
                'bet_end_date' => $checkDate . ' 23:59:59',
                'game_server' => 'AGCASINO',
                'timezone' => 'Asia/Taipei',
                'per_page' => '5000',
                'page' => (string) $currentPage,
            ];

            $requestUrl = $url . '?' . http_build_query($fields);

            // 增加重試機制
            $maxRetries = 3;
            $retryCount = 0;
            $success = false;
            $result = null;

            while ($retryCount < $maxRetries && !$success) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $requestUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                $rawResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);
                curl_close($ch);

                // 檢查 cURL 錯誤
                if ($curlErrno !== 0) {
                    $this->error("第 {$currentPage} 頁 cURL 錯誤 (嘗試 " . ($retryCount + 1) . "/{$maxRetries}): [{$curlErrno}] {$curlError}");
                    $retryCount++;
                    if ($retryCount < $maxRetries) {
                        sleep(2);
                    }

                    continue;
                }

                // 檢查 HTTP 狀態碼
                if ($httpCode !== 200) {
                    $this->error("第 {$currentPage} 頁 HTTP 錯誤 (嘗試 " . ($retryCount + 1) . "/{$maxRetries}): HTTP {$httpCode}");
                    $this->line('響應內容: ' . substr($rawResponse, 0, 200));
                    $retryCount++;
                    if ($retryCount < $maxRetries) {
                        sleep(2);
                    }

                    continue;
                }

                // 解析 JSON
                $result = json_decode($rawResponse, true);

                // 檢查 JSON 解析是否成功
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->error("第 {$currentPage} 頁 JSON 解析錯誤 (嘗試 " . ($retryCount + 1) . "/{$maxRetries}): " . json_last_error_msg());
                    $this->line('原始響應: ' . substr($rawResponse, 0, 200));
                    $retryCount++;
                    if ($retryCount < $maxRetries) {
                        sleep(2);
                    }

                    continue;
                }

                // 檢查響應結構
                if (!is_array($result)) {
                    $this->error("第 {$currentPage} 頁響應格式錯誤 (嘗試 " . ($retryCount + 1) . "/{$maxRetries}): 響應不是陣列");
                    $retryCount++;
                    if ($retryCount < $maxRetries) {
                        sleep(2);
                    }

                    continue;
                }

                $success = true;
            }

            // 如果重試後仍然失敗
            if (!$success) {
                $this->error("第 {$currentPage} 頁請求失敗，已重試 {$maxRetries} 次，跳過此頁");
                $failedPages[] = $currentPage;
                $currentPage++;

                continue;
            }

            // 從第一次成功的請求獲取總頁數
            if ($currentPage === 1 && isset($result['pagination'])) {
                $totalPages = $result['pagination']['last_page'] ?? 1;
                $totalItems = $result['pagination']['total_items'] ?? 0;
                $this->info("共計 {$totalItems} 筆投注紀錄，分 {$totalPages} 頁");
            }

            // 顯示當前頁面資訊
            // if (isset($result['pagination'])) {
            //     $this->line(json_encode($result['pagination'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            // }
            $this->line("正在處理第 {$currentPage}/{$totalPages} 頁...");

            // 處理數據
            if (!empty($result['data']) && is_array($result['data'])) {
                $itemCount = count($result['data']);
                $this->info("第 {$currentPage} 頁包含 {$itemCount} 筆記錄");

                foreach ($result['data'] as $item) {
                    if (!isset($item['game_round'])) {
                        $this->warn('發現缺少 game_round 的記錄，跳過');

                        continue;
                    }

                    if (!isset($data[$item['game_round']])) {
                        // 投注金額 = bet - refund（使用 Common::numSub 避免浮點誤差）
                        $netBet = Common::numSub((string) ($item['bet'] ?? 0), (string) ($item['refund'] ?? 0), 2);
                        $data[$item['game_round']] = [
                            'bet' => $netBet,
                            'win' => Common::numAdd('0', (string) ($item['win'] ?? 0), 2),
                            'refund' => Common::numAdd('0', (string) ($item['refund'] ?? 0), 2),
                            'status' => $item['status'] ?? '',
                        ];
                    } else {
                        // 使用 Common::numAdd / numSub 累加，避免多次 += 累積浮點誤差
                        $itemNetBet = Common::numSub((string) ($item['bet'] ?? 0), (string) ($item['refund'] ?? 0), 2);
                        $data[$item['game_round']]['bet'] = Common::numAdd($data[$item['game_round']]['bet'], $itemNetBet, 2);
                        $data[$item['game_round']]['win'] = Common::numAdd($data[$item['game_round']]['win'], (string) ($item['win'] ?? 0), 2);
                        $data[$item['game_round']]['refund'] = Common::numAdd($data[$item['game_round']]['refund'], (string) ($item['refund'] ?? 0), 2);
                    }
                }
            } else {
                $this->warn("第 {$currentPage} 頁沒有數據或數據格式錯誤");
            }

            $this->warn("已完成第 {$currentPage} 頁，共 {$totalPages} 頁");
            $currentPage++;

            // 避免請求過快
            usleep(100000); // 暫停 0.1 秒

        } while ($currentPage <= $totalPages);

        $this->info('已處理完成，共 ' . count($data) . ' 個遊戲回合');

        // 顯示失敗的頁面
        if (!empty($failedPages)) {
            $this->error('以下頁面請求失敗: ' . implode(', ', $failedPages));
        }

        // 將結果緩存起來（緩存 24 小時）
        if (!empty($data)) {
            Cache::put($cacheKey, $data, now()->addHours(24));
            $this->info('✓ 數據已緩存（有效期 24 小時）');
        }

        return $data;
    }

    protected function getBetRecords(string $checkDate, bool $useCache = true): array
    {
        // 生成緩存 key
        $cacheKey = "playtech:db_bet_records:{$checkDate}";

        // 如果使用緩存且緩存存在，直接返回
        if ($useCache && Cache::has($cacheKey)) {
            $cachedData = Cache::get($cacheKey);
            $this->info('✓ 從緩存讀取 DB 數據（共 ' . count($cachedData) . ' 筆投注紀錄）');

            return $cachedData;
        }

        $this->info('開始從 DB 查詢投注紀錄...');

        try {
            // 查詢DB投注紀錄
            // 將時間轉換為 UTC 時區以匹配 API 數據
            // 查詢範圍：台北時區當天 00:00:00 ~ 次日 00:00:00（不包含）
            $startDate = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $checkDate . ' 00:00:00', 'Asia/Taipei')
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s');

            $endDate = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $checkDate . ' 00:00:00', 'Asia/Taipei')
                ->addDay() // 加一天
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s');

            $this->info("查詢時間範圍（UTC）: {$startDate} ~ {$endDate}（不包含）");

            // 使用字串調用模型類
            $modelClass = $this->betRecordClass;

            // 先統計總數（使用 >= 和 < 確保範圍正確）
            $totalCount = $modelClass::query()
                // ->where('payout_time', '>=', $startDate)
                // ->where('payout_time', '<', $endDate)
                ->where('bet_time', '>=', $startDate)
                ->where('bet_time', '<', $endDate)
                ->count();

            $this->info("DB 查詢到 {$totalCount} 筆投注紀錄");

            if ($totalCount === 0) {
                $this->warn('DB 中沒有找到任何投注紀錄');

                return [];
            }

            // 使用 chunk 避免記憶體溢出
            $data = [];
            $processedCount = 0;

            $modelClass::query()
                // ->where('payout_time', '>=', $startDate)
                // ->where('payout_time', '<', $endDate)
                ->where('bet_time', '>=', $startDate)
                ->where('bet_time', '<', $endDate)
                ->orderBy('id')
                ->chunk(3000, function ($betRecords) use (&$data, &$processedCount, $totalCount) {
                    foreach ($betRecords as $item) {
                        // 三方 API 給的 win 含 jackpot；DB 端 jackpot 存獨立欄位（玩家錢包透過 changeBalance
                        // 獨立累加，所以 PtswBetRecord::getWin() fallback win_lose+bet 不含 jackpot），
                        // 對帳比對時必須補回 jackpot 才能對齊三方。
                        $dbWin = Common::numAdd(
                            Common::numAdd((string) $item->bet, (string) $item->win_lose, 4),
                            (string) ($item->jackpot ?? '0'),
                            2,
                        );

                        $data[$item->bet_id] = [
                            'bet_id' => $item->bet_id,
                            'bet' => Common::numAdd('0', (string) $item->bet, 2),
                            'win' => $dbWin,
                            'bet_time' => $item->bet_time,
                            'payout_time' => $item->payout_time,
                        ];
                    }

                    $processedCount += $betRecords->count();
                    $this->info("已處理 {$processedCount}/{$totalCount} 筆記錄");
                });

            $this->info('DB 數據處理完成，共 ' . count($data) . ' 個遊戲回合');

            // 將結果緩存起來（緩存 24 小時）
            if (!empty($data)) {
                Cache::put($cacheKey, $data, now()->addHours(24));
                $this->info('✓ DB 數據已緩存（有效期 24 小時）');
            }

            return $data;

        } catch (Throwable $e) {
            $this->error('查詢 DB 投注紀錄時發生錯誤: ' . $e->getMessage());
            $this->error('錯誤位置: ' . $e->getFile() . ':' . $e->getLine());

            // 記錄詳細的錯誤堆疊
            $this->line('錯誤堆疊:');
            $this->line(Common::formatTrace($e));

            throw $e; // 重新拋出異常以便上層處理
        }
    }
}
