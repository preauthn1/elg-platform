<?php

declare(strict_types=1);

namespace App\Console\Commands\Redis;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * 掃描 Redis 並依前綴統計記憶體佔用，找出「誰把空間吃掉了」。
 *
 * 2026-09-23 TPower 的 1GB Memorystore 從 20% 一路漲到 99.5%，觸發
 * `OOM command not allowed under OOM prevention`，全站（含會員轉帳）的 Redis 寫入
 * 被拒約一分鐘。當時只能從 GCP metric 看到「key 數 +625、記憶體 +500MB」，
 * 看不出是哪一類 key，只好靠猜——這支指令就是為了不必再猜。
 *
 * 🔴 一律用 SCAN 游標分批，禁止 KEYS：Redis 是單執行緒，KEYS 在數萬個 key 上會阻塞
 * 整個實例，等於再製造一次上述事故。
 *
 * MEMORY USAGE 需要 Redis 4.0+（Memorystore BASIC 即為 REDIS_4_0）；它回的是該 key
 * 連同內部結構的估算位元組，巢狀結構採樣估算，與 INFO 的 used_memory 不會完全相等。
 *
 * 做法等同 redis-cli 的 --memkeys（SCAN ＋ 逐 key MEMORY USAGE），差別在多一層前綴聚合：
 * --memkeys 只回報各型別最大的 key，看不出「幾百個各自不大的 key 合起來吃掉幾百 MB」這種形態，
 * 而那正是 2026-09-23 那次的樣子。節流沿用 redis-cli -i 的設計（每 N 批讓出一次）。
 *
 * 掃描以 database 為單位，不是連線名——config 的 7 個連線只對應 4 個 DB：
 *   DB 0：default／cache／json    DB 2：session    DB 3：queue／horizon    DB 15：db_15
 * 同一個 DB 選哪一個連線名，掃到的都是同一批 key。
 *
 * ⚠️ 線上分析會增加 Redis 負載（送出的命令數等於 key 總數）。業界慣例是**記憶體超過 2GB
 * 就改用 RDB 離線分析**（Memorystore BASIC 支援 export 到 Cloud Storage，再以 redis-rdb-tools
 * 之類解析），完全不碰線上實例。本指令用於 1GB 這種規模的即時排查。
 */
class AnalyzeRedisMemoryCommand extends Command
{
    /**
     * php artisan redis:analyze-memory --connection=default --top=30 --depth=2
     *
     * @var string
     */
    protected $signature = 'redis:analyze-memory
        {--connection=default : 連線名；掃的是該連線指向的整個 DB（見 class docblock 的對照）}
        {--top=30 : 列出最大的幾個 key}
        {--depth=2 : 前綴取到第幾層（以 : 分段）}
        {--scanCount=1000 : 每批 SCAN 的筆數}';

    protected $description = '掃描 Redis 記憶體佔用：依前綴統計並列出最大的 key（唯讀，不刪不改）';

    /**
     * 每幾批讓出一次，以及讓出多久。
     *
     * 沿用 redis-cli `-i` 的設計（每 100 個 SCAN cycle sleep 指定秒數，生產建議 -i 0.1）：
     * 真正的負擔不是 SCAN 本身，而是逐 key 的 MEMORY USAGE——送出的命令數等於 key 總數，
     * 它們會與正常流量交錯排隊。每批讓出 1ms 這種寫法形同虛設（43 批總共才 43ms）。
     */
    private const int BREATH_EVERY_BATCHES = 10;

    private const int BREATH_US = 100_000;

    /**
     * SCAN 的 COUNT 上限。
     *
     * COUNT 決定單次 SCAN 要掃過多少個 bucket，給到百萬就等於把不阻塞的 SCAN 變成阻塞操作，
     * 那正是本指令禁用 KEYS 的理由。上限寫死，不讓參數把保護拆掉。
     */
    private const int MAX_SCAN_COUNT = 10_000;

    /** TOP N 的暫存上限倍率：超過就排序截斷，避免 key 數百萬時把記憶體吃光 */
    private const int TOP_BUFFER_FACTOR = 10;

    public function handle(): int
    {
        $connection = (string) $this->option('connection');
        $top = max(1, (int) $this->option('top'));
        $depth = max(1, (int) $this->option('depth'));
        $scanCount = self::resolveScanCount((int) $this->option('scanCount'));

        $startedAt = microtime(true);

        try {
            $client = Redis::connection($connection)->client();
        } catch (\Throwable $e) {
            $this->error(sprintf('連線 %s 取得失敗：%s', $connection, $e->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf('掃描 Redis 連線 %s（SCAN 每批 %d 筆）', $connection, $scanCount));

        // 空批次自動重試，否則 phpredis 的 scan 會回空陣列讓迴圈提早結束
        $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

        // 先確認這個環境認得 MEMORY USAGE：託管型 Redis 會封鎖部分命令，
        // 不先探就會掃完整個 keyspace 才發現每一筆都量不到——白跑一趟，還白送出等同 key 數的命令
        if (!$this->supportsMemoryUsage($client)) {
            $this->error('這個 Redis 不支援 MEMORY USAGE（需 4.0 以上，或該命令被託管服務封鎖），無法統計佔用');

            return self::FAILURE;
        }

        /** @var array<string, array{keys: int, bytes: int}> 前綴 => 統計 */
        $prefixes = [];
        /** @var list<array{key: string, bytes: int}> */
        $largest = [];
        $totalKeys = 0;
        $totalBytes = 0;
        $unmeasured = 0;
        $batches = 0;
        $iterator = null;

        while (($keys = $client->scan($iterator, null, $scanCount)) !== false) {
            foreach ($keys as $key) {
                $bytes = $this->memoryUsage($client, (string) $key);

                if ($bytes === null) {
                    // key 在掃描期間過期／被刪，或該版本不支援 MEMORY USAGE
                    $unmeasured++;

                    continue;
                }

                $totalKeys++;
                $totalBytes += $bytes;

                $prefix = self::prefixOf((string) $key, $depth);
                $prefixes[$prefix]['keys'] = ($prefixes[$prefix]['keys'] ?? 0) + 1;
                $prefixes[$prefix]['bytes'] = ($prefixes[$prefix]['bytes'] ?? 0) + $bytes;

                $largest[] = ['key' => (string) $key, 'bytes' => $bytes];
            }

            if (count($largest) > $top * self::TOP_BUFFER_FACTOR) {
                $largest = self::trimLargest($largest, $top);
            }

            // 迴圈的結束條件只靠 while：SCAN_RETRY 模式下 phpredis 會自動重試空批次，
            // 游標歸零才回 false，不必另外判斷 $iterator
            if (++$batches % self::BREATH_EVERY_BATCHES === 0) {
                usleep(self::BREATH_US);
            }
        }

        if ($totalKeys === 0) {
            $this->warn('沒有掃到任何 key');

            return self::SUCCESS;
        }

        $largest = self::trimLargest($largest, $top);

        $this->renderSummary($connection, $totalKeys, $totalBytes, $unmeasured, microtime(true) - $startedAt);
        $this->renderPrefixes($prefixes, $totalBytes);
        $this->renderLargest($client, $largest, $totalBytes);

        return self::SUCCESS;
    }

    /**
     * 探一次 MEMORY USAGE：拿一個必然不存在的 key 問，命令可用時回 null（查無此 key），
     * 命令被封鎖或版本不足時拋錯。只送一個命令，成本可忽略。
     */
    private function supportsMemoryUsage(object $client): bool
    {
        try {
            $client->rawCommand('MEMORY', 'USAGE', 'redis-analyze-memory:probe:' . uniqid());
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * MEMORY USAGE：key 不存在或命令不支援時回 null（不讓整趟掃描中斷）
     */
    private function memoryUsage(object $client, string $key): ?int
    {
        try {
            $bytes = $client->rawCommand('MEMORY', 'USAGE', $key);
        } catch (\Throwable) {
            return null;
        }

        return is_int($bytes) ? $bytes : null;
    }

    /**
     * SCAN 的 COUNT 夾回 [100, MAX_SCAN_COUNT]：上限是保護，不讓參數把它拆掉。
     */
    public static function resolveScanCount(int $requested): int
    {
        return min(self::MAX_SCAN_COUNT, max(100, $requested));
    }

    /**
     * 取 key 的前綴：以 `:` 分段取前 $depth 段，末尾補 `:*` 表示還有下層。
     * 沒有 `:` 的 key 自成一類。
     */
    public static function prefixOf(string $key, int $depth): string
    {
        $segments = explode(':', $key);

        if (count($segments) <= $depth) {
            return $key;
        }

        return implode(':', array_slice($segments, 0, $depth)) . ':*';
    }

    /**
     * 只留最大的 $top 個，其餘丟棄（key 數百萬時避免整份留在記憶體）
     *
     * @param  list<array{key: string, bytes: int}>  $largest
     * @return list<array{key: string, bytes: int}>
     */
    private static function trimLargest(array $largest, int $top): array
    {
        usort($largest, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return array_slice($largest, 0, $top);
    }

    private function renderSummary(string $connection, int $totalKeys, int $totalBytes, int $unmeasured, float $elapsed): void
    {
        $this->newLine();
        $this->info(sprintf(
            '連線 %s：%s 個 key，合計 %s，耗時 %.1f 秒%s',
            $connection,
            number_format($totalKeys),
            self::humanBytes($totalBytes),
            $elapsed,
            $unmeasured > 0 ? sprintf('（%d 個 key 量不到，多為掃描期間過期）', $unmeasured) : '',
        ));
    }

    /**
     * @param  array<string, array{keys: int, bytes: int}>  $prefixes
     */
    private function renderPrefixes(array $prefixes, int $totalBytes): void
    {
        uasort($prefixes, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        $rows = [];
        foreach ($prefixes as $prefix => $stat) {
            $rows[] = [
                $prefix,
                number_format($stat['keys']),
                self::humanBytes($stat['bytes']),
                sprintf('%.1f%%', $totalBytes > 0 ? $stat['bytes'] / $totalBytes * 100 : 0),
                self::humanBytes((int) ($stat['bytes'] / max(1, $stat['keys']))),
            ];
        }

        $this->newLine();
        $this->line('=== 前綴分布（依佔用排序）===');
        $this->table(['前綴', 'key 數', '合計', '佔比', '平均/key'], $rows);
    }

    /**
     * @param  list<array{key: string, bytes: int}>  $largest
     */
    private function renderLargest(object $client, array $largest, int $totalBytes): void
    {
        $rows = [];
        foreach ($largest as $item) {
            // 型別與 TTL 只對 TOP N 查，全表查會多出兩倍往返
            $ttl = $client->ttl($item['key']);
            $rows[] = [
                $item['key'],
                self::humanBytes($item['bytes']),
                sprintf('%.1f%%', $totalBytes > 0 ? $item['bytes'] / $totalBytes * 100 : 0),
                self::typeName($client->type($item['key'])),
                match (true) {
                    $ttl === -1 => '永久',
                    $ttl === -2 => '已不存在',
                    default => sprintf('%d 秒', $ttl),
                },
            ];
        }

        $this->newLine();
        $this->line('=== 最大的 key ===');
        $this->table(['key', '大小', '佔比', '型別', 'TTL'], $rows);
        $this->newLine();
        $this->line('※ 只顯示 key 名與大小，不讀取任何 value');
        $this->line('※ SCAN 保證不漏、但不保證不重（rehash 期間同一個 key 可能被掃到兩次），統計值可能略為高估');
        $this->line('※ 線上掃描會增加 Redis 負載；記憶體超過 2GB 建議改以 RDB 匯出後離線分析');
    }

    /** phpredis 的 type() 回整數常數，轉成可讀名稱 */
    private static function typeName(mixed $type): string
    {
        return match ($type) {
            \Redis::REDIS_STRING => 'string',
            \Redis::REDIS_SET => 'set',
            \Redis::REDIS_LIST => 'list',
            \Redis::REDIS_ZSET => 'zset',
            \Redis::REDIS_HASH => 'hash',
            \Redis::REDIS_STREAM => 'stream',
            default => 'unknown',
        };
    }

    private static function humanBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => sprintf('%.2f GB', $bytes / 1073741824),
            $bytes >= 1048576 => sprintf('%.2f MB', $bytes / 1048576),
            $bytes >= 1024 => sprintf('%.1f KB', $bytes / 1024),
            default => $bytes . ' B',
        };
    }
}
