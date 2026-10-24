<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PragmaticPlay\Services;

use App\Helpers\Common;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class FetchGameRoundsService
{
    public const TABLE_GAME_ROUNDS = 'ppsw_game_rounds';

    /**
     * @throws Exception
     */
    public function initTable(): void
    {
        if (Schema::hasTable(self::TABLE_GAME_ROUNDS)) {
            return;
        }

        Schema::create(self::TABLE_GAME_ROUNDS, function ($table) {
            $table->bigIncrements('id');
            $table->string('server')->index()->comment('API 域名');
            $table->string('playerID')->index()->comment('玩家ID');
            $table->string('extPlayerID')->index()->comment('外部玩家ID');
            $table->string('gameID')->index()->comment('遊戲ID');
            $table->string('playSessionID')->unique()->comment('遊戲回合ID');
            $table->string('parentSessionID')->index()->comment('父回合ID');
            $table->timestamp('startDate')->nullable()->index()->comment('開始時間');
            $table->timestamp('endDate')->nullable()->index()->comment('結束時間');
            $table->string('status')->nullable()->comment('狀態');
            $table->string('type')->nullable()->comment('類型');
            $table->decimal('bet', 20, 6)->default(0)->comment('下注金額');
            $table->decimal('win', 20, 6)->default(0)->comment('贏得金額');
            $table->string('currency')->nullable()->comment('幣別');
            $table->decimal('jackpot', 20, 6)->default(0)->comment('彩金');
            $table->timestamps();

            $table->index(['extPlayerID', 'startDate']);
            $table->index(['gameID', 'startDate']);
        });
    }

    /**
     * @throws Exception
     */
    public function getEnvironmentDomains(array $info): array
    {
        $params = [
            'secureLogin' => $info['SECURE_LOGIN'],
        ];
        $params['hash'] = $this->calculateHash($params, $info['SECRET_KEY']);

        $url = Str::replace('CasinoGameAPI', 'SystemAPI', $info['API_URL']);

        $result = Http::get($url . '/environments', $params);

        if (($result['error'] ?? null) !== '0') {
            throw new Exception('取得環境失敗：' . ($result['description'] ?? 'unknown'));
        }

        $domainList = [];
        foreach (($result['environments'] ?? []) as $environment) {
            if (!empty($environment['apiDomain'])) {
                $domainList[] = $environment['apiDomain'];
            }
        }

        $domainList = array_values(array_unique($domainList));

        return $domainList;
    }

    /**
     * 生成 288 個 timepoint 的 gamerounds URL。
     */
    public function buildTimePointRequests(string $domain, array $credentials, int $timestamp, string $dataType): array
    {
        $requests = [];

        for ($i = 0; $i < 288; $i++) {
            $timepoint = ($timestamp + ($i * 300)) * 1000;
            $requests[$i] = sprintf(
                'https://%s/IntegrationService/v3/DataFeeds/gamerounds/?login=%s&password=[REDACTED]',
                $domain,
                urlencode((string) ($credentials['login'] ?? '')),
                urlencode((string) ($credentials['password'] ?? '')),
                $timepoint,
                urlencode($dataType)
            );
        }

        return $requests;
    }

    public function extractTimepointFromUrl(string $url): string
    {
        preg_match('/timepoint=(\\d+)/', $url, $matches);
        $timepointMs = $matches[1] ?? 0;
        $timepointSeconds = (int) (((int) $timepointMs) / 1000);

        return Carbon::createFromTimestamp($timepointSeconds, 'UTC')->format('Y-m-d H:i:s');
    }

    /**
     * @return list<array<string,string>>
     */
    public function tidyCsv(string $csvData): array
    {
        $data = [];

        $lines = array_filter(explode("\n", $csvData));

        $headers = null;

        foreach ($lines as $index => $line) {
            if ($index === 0) {
                continue;
            }

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

    /**
     * 支持多种日期格式，增强容错性
     */
    public function parseDateTime(?string $dateTimeStr): ?Carbon
    {
        if (empty($dateTimeStr) || $dateTimeStr === 'null') {
            return null;
        }

        $dateTimeStr = trim($dateTimeStr);

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i:s.u',
            'Y/m/d H:i:s',
            'Y-m-d\\TH:i:s',
            'Y-m-d\\TH:i:s\\Z',
        ];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $dateTimeStr);
            } catch (Exception) {
                continue;
            }
        }

        return null;
    }

    /**
     * INSERT IGNORE 插入（忽略重複鍵）
     */
    public function insertIgnore(string $table, array $data): int
    {
        if (empty($data)) {
            return 0;
        }

        $columns = array_keys($data[0]);
        $columnList = implode(', ', array_map(static fn ($col) => "`{$col}`", $columns));

        $placeholders = [];
        $bindings = [];

        foreach ($data as $row) {
            $rowPlaceholders = [];
            foreach ($columns as $column) {
                $rowPlaceholders[] = '?';
                $bindings[] = $row[$column];
            }
            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }

        $sql = "INSERT IGNORE INTO `{$table}` ({$columnList}) VALUES " . implode(', ', $placeholders);

        return DB::affectingStatement($sql, $bindings);
    }

    /**
     * 批次插入遊戲輪次資料（事務包裝 + INSERT IGNORE）
     *
     * @throws Exception|Throwable
     */
    public function batchInsertRounds(string $domain, array $rounds, string $timepointDateTime): int
    {
        Log::info('📥 batchInsertRounds 开始', [
            'domain' => $domain,
            'datetime' => $timepointDateTime,
            'csv_records_count' => count($rounds),
            'first_record' => $rounds[0] ?? null,
        ]);

        if (empty($rounds)) {
            Log::warning('⚠️  CSV 数据为空', [
                'domain' => $domain,
                'datetime' => $timepointDateTime,
            ]);

            return 0;
        }

        $insertData = [];
        foreach ($rounds as $round) {
            if (empty($round['playSessionID'])) {
                Log::warning('batchInsertRounds: 發現空的 playSessionID', [
                    'round' => $round,
                ]);

                continue;
            }

            $insertData[] = [
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
        }

        Log::info('📝 准备插入数据', [
            'domain' => $domain,
            'datetime' => $timepointDateTime,
            'total_records' => count($rounds),
            'valid_records' => count($insertData),
            'first_playSessionID' => $insertData[0]['playSessionID'] ?? 'N/A',
        ]);

        if (empty($insertData)) {
            Log::warning('⚠️  没有有效的插入数据', [
                'domain' => $domain,
                'datetime' => $timepointDateTime,
            ]);

            return 0;
        }

        $chunks = array_chunk($insertData, 1000);
        $totalInserted = 0;

        // 釋放原始數據
        unset($insertData);

        try {
            DB::beginTransaction();

            foreach ($chunks as $chunkIndex => $chunk) {
                try {
                    $inserted = $this->insertIgnore(self::TABLE_GAME_ROUNDS, $chunk);
                    $totalInserted += $inserted;

                    Log::info('✅ 批次插入成功', [
                        'chunk_index' => $chunkIndex + 1,
                        'chunk_size' => count($chunk),
                        'actually_inserted' => $inserted,
                        'datetime' => $timepointDateTime,
                    ]);
                } catch (Exception $e) {
                    Log::warning('批次插入失敗，嘗試逐條插入', [
                        'chunk_index' => $chunkIndex + 1,
                        'chunk_size' => count($chunk),
                        'datetime' => $timepointDateTime,
                        'error' => $e->getMessage(),
                    ]);

                    // 逐條插入以找出問題記錄
                    foreach ($chunk as $recordIndex => $record) {
                        try {
                            $inserted = $this->insertIgnore(self::TABLE_GAME_ROUNDS, [$record]);
                            $totalInserted += $inserted;
                        } catch (Exception $recordError) {
                            Log::error('單筆記錄插入失敗', [
                                'chunk_index' => $chunkIndex + 1,
                                'record_index' => $recordIndex,
                                'playSessionID' => $record['playSessionID'] ?? 'N/A',
                                'datetime' => $timepointDateTime,
                                'error' => $recordError->getMessage(),
                                'record_data' => $record,
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            if ($totalInserted > 0) {
                Log::info('時間點寫入成功', [
                    'domain' => $domain,
                    'datetime' => $timepointDateTime,
                    'csv_records' => count($rounds),
                    'inserted' => $totalInserted,
                ]);
            }

            return $totalInserted;
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('batchInsertRounds: 事務回滾', [
                'domain' => $domain,
                'datetime' => $timepointDateTime,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            throw $e;
        }
    }

    /**
     * 計算 Hash 值用於 API 請求驗證
     */
    public function calculateHash(array $params, string $secretKey): string
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
