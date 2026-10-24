<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PragmaticPlay\RetrieveRecords;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\VendorInfo;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 測試單一時間點的資料抓取
 *
 * 使用方式：
 * 1. 傳入 timepoint (秒)：php artisan ppsw:test-timepoint 1763485800
 * 2. 傳入注單時間：php artisan ppsw:test-timepoint "2025-11-24 04:13:23"
 *
 * 範例：
 * php artisan ppsw:test-timepoint 1763485800
 * php artisan ppsw:test-timepoint 1763957400
 * php artisan ppsw:test-timepoint "2025-11-24 04:20:22"
 * php artisan ppsw:test-timepoint "2025-11-24 04:14:06"
 */
class TestSingleTimepoint extends Command
{
    protected $signature = 'ppsw:test-timepoint {timepoint : Timepoint(秒) 或注單時間 (Y-m-d H:i:s)}';

    protected $description = '測試單一時間點的 PPSW 遊戲輪次資料抓取（支持傳入注單時間自動轉換）';

    public function handle(): int
    {
        $input = $this->argument('timepoint');

        // 判斷輸入是時間戳還是日期時間字符串
        if (is_numeric($input)) {
            // 直接使用時間戳
            $timepointSeconds = (int) $input;
            $originalTime = Carbon::createFromTimestamp($timepointSeconds, 'UTC');
        } else {
            // 解析日期時間字符串，轉換為最近的 10 分鐘整點
            try {
                $originalTime = Carbon::createFromFormat('Y-m-d H:i:s', $input, 'UTC');
            } catch (Exception $e) {
                $this->error('❌ 日期格式錯誤，請使用 Y-m-d H:i:s 格式');
                $this->error('範例: "2025-11-24 04:13:23"');

                return 1;
            }

            // 轉換為最近的 10 分鐘整點 (向下取整)
            $timepointSeconds = $this->convertToNearestTenMinutes($originalTime);
        }

        $timepoint = $timepointSeconds * 1000; // 轉換為毫秒
        $timepointDateTime = Carbon::createFromTimestamp($timepointSeconds, 'UTC')->format('Y-m-d H:i:s');

        $this->info('========================================');
        $this->info('🧪 測試單一時間點資料抓取');
        $this->info('========================================');

        if (!is_numeric($input)) {
            $this->info('📅 原始注單時間: ' . $originalTime->format('Y-m-d H:i:s') . ' UTC');
            $this->info('🔄 已轉換為最近的 10 分鐘整點');
            $this->newLine();
        }

        $this->info('時間戳 (秒): ' . $timepointSeconds);
        $this->info('時間戳 (毫秒): ' . $timepoint);
        $this->info('UTC 時間: ' . $timepointDateTime);
        $this->info('========================================');
        $this->newLine();

        // 1. 取得廠商資訊
        $this->info('📋 步驟 1: 取得廠商資訊');
        $vendorInfo = VendorInfo::where('vendor_id', VendorIdEnum::PPSW->value)->first();

        if (!$vendorInfo) {
            $this->error('❌ 找不到 PPSW 廠商資訊');

            return 1;
        }

        $this->info('✅ 廠商資訊取得成功');
        $this->newLine();

        // 2. 取得域名列表
        $this->info('📋 步驟 2: 取得 API 域名');
        $domainList = $this->getDomainList($vendorInfo->info);
        $this->info('✅ 找到 ' . count($domainList) . ' 個域名');
        foreach ($domainList as $index => $domain) {
            $this->line('  [' . ($index + 1) . '] ' . $domain);
        }
        $this->newLine();

        // 3. 測試每個域名
        foreach ($domainList as $domainIndex => $domain) {
            $dataTypes = [
                'RNG' => '電子遊戲',
                'LC' => '真人遊戲',
            ];
            foreach ($dataTypes as $type => $name) {
                $this->info('📋 步驟 3.' . ($domainIndex + 1) . ': 測試域名 [' . $domain . '] 的 ' . $name . ' 資料');
                $this->testDomain($domain, $timepoint, $timepointDateTime, array_merge($vendorInfo->info, ['dataType' => $type]));
                $this->newLine();
            }
        }

        $this->info('========================================');
        $this->info('✅ 測試完成');
        $this->info('========================================');

        return 0;
    }

    private function testDomain(string $domain, int $timepoint, string $timepointDateTime, array $info): void
    {
        // 建立 URL
        $url = sprintf(
            'https://%s/IntegrationService/v3/DataFeeds/gamerounds/?login=%s&password=[REDACTED]',
            $domain,
            urlencode($info['SECURE_LOGIN']),
            urlencode($info['SECRET_KEY']),
            $timepoint,
            $info['dataType']
        );

        $this->line('  URL: ' . $url);
        $this->newLine();

        // 抓取資料
        $this->line('  ⏳ 正在抓取 CSV 資料...');
        $response = Http::timeout(30)->get($url);

        if (!$response->successful()) {
            $this->error('  ❌ HTTP 請求失敗: ' . $response->status());

            return;
        }

        $csvData = $response->body();
        $this->info('  ✅ CSV 資料長度: ' . strlen($csvData) . ' bytes');

        // 解析 CSV
        $this->line('  ⏳ 解析 CSV...');
        $rounds = $this->parseCsv($csvData);
        $this->info('  ✅ 解析完成: ' . count($rounds) . ' 筆記錄');

        if (count($rounds) === 0) {
            $this->warn('  ⚠️  沒有資料');

            return;
        }

        // 顯示前 5 筆記錄
        $this->newLine();
        $this->line('  📋 前 5 筆記錄:');
        foreach (array_slice($rounds, 0, 5) as $index => $round) {
            $this->line('    [' . ($index + 1) . '] playSessionID: ' . $round['playSessionID'] .
                ' | parentSessionID: ' . $round['parentSessionID'] .
                ' | bet: ' . $round['bet'] .
                ' | win: ' . $round['win']);
        }

        // 檢查資料庫
        $this->newLine();
        $this->line('  ⏳ 檢查資料庫中已存在的記錄...');
        $playSessionIds = array_column($rounds, 'playSessionID');
        $existingIds = DB::table('ppsw_game_rounds')
            ->whereIn('playSessionID', $playSessionIds)
            ->pluck('playSessionID')
            ->toArray();

        $this->info('  ✅ 資料庫中已存在: ' . count($existingIds) . ' 筆');

        if (count($existingIds) > 0) {
            $this->warn('  📋 已存在的 playSessionID (前 5 個):');
            foreach (array_slice($existingIds, 0, 5) as $id) {
                $this->line('    - ' . $id);
            }
        }

        // 過濾新記錄
        $this->newLine();
        $this->line('  ⏳ 過濾新記錄...');
        $newRounds = array_values(array_filter($rounds, function ($round) use ($existingIds) {
            return !in_array($round['playSessionID'], $existingIds);
        }));

        $this->info('  ✅ 新記錄數: ' . count($newRounds));

        if (count($newRounds) > 0) {
            $this->info('  📋 新記錄 playSessionID (前 5 個):');
            foreach (array_slice(array_column($newRounds, 'playSessionID'), 0, 5) as $id) {
                $this->line('    - ' . $id);
            }

            // 詢問是否要寫入
            $this->newLine();
            // if ($this->confirm('是否要將這 ' . count($newRounds) . ' 筆新記錄寫入資料庫？', false)) {
            $this->line('  ⏳ 正在寫入資料庫...');
            $inserted = $this->insertRecords($domain, $newRounds);
            $this->info('  ✅ 成功寫入: ' . $inserted . ' 筆記錄');
            // } else {
            //     $this->warn('  ⏭️  跳過寫入');
            // }
        } else {
            $this->warn('  ⚠️  沒有新記錄需要寫入（全部已存在）');
        }
    }

    /**
     * 將時間轉換為最近的 10 分鐘整點 (向下取整)
     *
     * 例如：
     * - 04:13:23 → 04:10:00
     * - 04:14:06 → 04:10:00
     * - 04:20:15 → 04:20:00
     * - 04:29:59 → 04:20:00
     *
     * @param  Carbon  $time  原始時間
     * @return int 10 分鐘整點的時間戳 (秒)
     */
    private function convertToNearestTenMinutes(Carbon $time): int
    {
        // 獲取分鐘數並向下取整到 10 的倍數
        $minutes = (int) $time->format('i');
        $roundedMinutes = floor($minutes / 10) * 10;

        // 建立新的時間，保持年月日時，只修改分鐘和秒
        $result = $time->copy()
            ->setMinute((int) $roundedMinutes)
            ->setSecond(0);

        return $result->timestamp;
    }

    private function parseCsv(string $csvData): array
    {
        $data = [];
        $lines = array_filter(explode("\n", $csvData));
        $headers = null;

        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue;
            } // 跳過說明列

            if ($index === 1) {
                $headers = str_getcsv($line, ',', '"', '');

                continue;
            }

            $row = str_getcsv($line, ',', '"', '');
            $rowData = [];
            foreach ($row as $i => $value) {
                $key = $headers[$i] ?? "column_{$i}";
                $rowData[$key] = $value;
            }
            $data[] = $rowData;
        }

        return $data;
    }

    private function insertRecords(string $domain, array $rounds): int
    {
        $insertData = array_map(function ($round) use ($domain) {
            return [
                'server' => $domain,
                'playerID' => $round['playerID'] ?? '',
                'extPlayerID' => $round['extPlayerID'] ?? '',
                'gameID' => $round['gameID'] ?? '',
                'playSessionID' => $round['playSessionID'],
                'parentSessionID' => $round['parentSessionID'] ?? '',
                'startDate' => $this->parseDateTime($round['startDate'] ?? null),
                'endDate' => $this->parseDateTime($round['endDate'] ?? null),
                'status' => $round['status'] ?? '',
                'type' => $round['type'] ?? '',
                'bet' => $round['bet'] ?? 0,
                'win' => $round['win'] ?? 0,
                'currency' => $round['currency'] ?? '',
                'jackpot' => $round['jackpot'] ?? 0,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ];
        }, $rounds);

        $chunks = array_chunk($insertData, 500);
        $totalInserted = 0;

        foreach ($chunks as $chunkIndex => $chunk) {
            try {
                DB::table('ppsw_game_rounds')->insert($chunk);
                $totalInserted += count($chunk);
            } catch (Exception $e) {
                $this->error('  ❌ 批量插入失敗: ' . $e->getMessage());

                // 嘗試逐條插入
                foreach ($chunk as $recordIndex => $record) {
                    try {
                        DB::table('ppsw_game_rounds')->insert([$record]);
                        $totalInserted++;
                    } catch (Exception $recordError) {
                        $this->error('    ❌ 單筆插入失敗 [' . $record['playSessionID'] . ']: ' . $recordError->getMessage());
                        Log::error('Test timepoint single record insert failed', [
                            'playSessionID' => $record['playSessionID'],
                            'error' => $recordError->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $totalInserted;
    }

    /**
     * 安全地解析日期時間
     * 支持多种日期格式，增强容错性
     */
    private function parseDateTime(?string $dateTimeStr): ?Carbon
    {
        // 處理 null、空字串、'null' 字串
        if (empty($dateTimeStr) || $dateTimeStr === 'null') {
            return null;
        }

        // 去除前后空格
        $dateTimeStr = trim($dateTimeStr);

        // 尝试多种日期格式
        $formats = [
            'Y-m-d H:i:s',      // 标准格式
            'Y-m-d H:i:s.u',    // 带微秒
            'Y/m/d H:i:s',      // 斜线分隔
            'Y-m-d\TH:i:s',     // ISO 8601
            'Y-m-d\TH:i:s\Z',   // ISO 8601 with Z
        ];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $dateTimeStr);
            } catch (Exception $e) {
                // 继续尝试下一个格式
                continue;
            }
        }

        // 所有格式都失败，返回 null
        return null;
    }

    private function getDomainList(array $info): array
    {
        $params = [
            'secureLogin' => $info['SECURE_LOGIN'],
        ];
        $params['hash'] = $this->calculateHash($params, $info['SECRET_KEY']);

        $url = \Illuminate\Support\Str::replace('CasinoGameAPI', 'SystemAPI', $info['API_URL']);
        $result = Http::get($url . '/environments', $params);

        if ($result['error'] !== '0') {
            throw new Exception('取得環境失敗：' . $result['description']);
        }

        $domainList = [];
        foreach ($result['environments'] as $environment) {
            $domainList[] = $environment['apiDomain'];
        }

        return $domainList;
    }

    private function calculateHash(array $params, string $secretKey): string
    {
        if (array_key_exists('hash', $params)) {
            unset($params['hash']);
        }

        ksort($params);
        $baseString = http_build_query($params);
        $toHash = $baseString . $secretKey;

        return md5($toHash);
    }
}
