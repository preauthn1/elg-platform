<?php

declare(strict_types=1);

namespace App\Helpers\Traits;

use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 分散式互斥鎖（同一筆業務同時只有一個執行者）。
 *
 * 🔴 **互斥一律走 {@see self::withLock()}，禁止裸用 `Cache::lock()` 自行 release**
 * ——本方法內含持鎖逾時警示與 isOwnedByCurrentProcess 保護，裸用會誤釋放他人的鎖。
 *
 * 鍵與 TTL 的來源固定：快取鍵集中在 {@see \App\CacheKeys\CacheKeys}、TTL 一律取
 * {@see \App\Enums\General\CacheTtl} enum、含參數的鍵用 `Common::genCacheKey()`，
 * **禁止寫魔術數字**。
 *
 * ⚠️ 這是應用層的互斥，與 DB 層的 `lockForUpdate()` 是不同層次的工具、**不可互相取代**；
 * queue job 的全域單飛用 Laravel 內建 WithoutOverlapping middleware，也不走本 trait。
 * 讀取型熱點的防擊穿用 `Cache::safeRemember()`（macro 定義在 MacroProvider::boot）。
 */
trait DistributedLock
{
    /**
     * 執行帶鎖的操作
     *
     * @param  string  $key  鎖的唯一鍵
     * @param  int  $ttl  鎖的有效時間（秒）
     * @param  callable  $callback  要執行的操作
     * @param  int  $waitSeconds  等待獲取鎖的時間（秒），0 表示不等待
     * @return mixed 操作的結果
     *
     * @throws ApiException 如果無法獲取鎖
     * @throws Throwable 業務邏輯拋出的異常
     */
    public function withLock(string $key, int $ttl, callable $callback, int $waitSeconds = 0, string $errorMsg = ''): mixed
    {
        $lock = Cache::lock($key, $ttl);
        $lockAcquired = false;
        $callbackStart = null;

        try {
            // 嘗試獲取鎖
            if ($waitSeconds > 0) {
                // 等待模式：失敗直接拋出 LockTimeoutException，由對應 catch 處理
                $lock->block($waitSeconds);
                $lockAcquired = true;
            } else {
                // 立即模式：立即嘗試獲取鎖，失敗則返回 false
                $lockAcquired = $lock->get();

                if (!$lockAcquired) {
                    Log::warning('取得鎖失敗', [
                        'lock_key' => $key,
                        'ttl' => $ttl,
                        'wait_seconds' => $waitSeconds,
                        'attempted_at' => now()->toDateTimeString(),
                    ]);

                    throw new ApiException(
                        $errorMsg ?: __('error_message.program processing'),
                        HttpCode::HTTP_LOCKED->value
                    );
                }
            }

            // 記錄鎖獲取成功
            Log::debug('已取得鎖', [
                'lock_key' => $key,
                'ttl' => $ttl,
            ]);

            // 持鎖計時起點（不含等待取鎖的時間）
            // 同時作為「callback 已開始執行」的旗標，用於 catch 區塊區分例外來源
            $callbackStart = microtime(true);

            // 執行業務邏輯
            $result = $callback();

            // 持鎖執行時長（排除等待取鎖的時間）
            $callbackDuration = microtime(true) - $callbackStart;

            // 檢查是否接近 TTL（80% 閾值）
            if ($callbackDuration > ($ttl * 0.8)) {
                Log::warning('持鎖操作耗時過長', [
                    'lock_key' => $key,
                    'ttl' => $ttl,
                    'duration' => round($callbackDuration, 2),
                    'threshold' => $ttl * 0.8,
                ]);
            }

            return $result;

        } catch (Throwable $e) {
            // LockTimeoutException 且 $callbackStart 為 null：
            // 例外來自 block() 等待取鎖期間，非 callback 內部，視為鎖競爭失敗。
            if ($e instanceof LockTimeoutException && $callbackStart === null) {
                Log::warning('等待取得鎖逾時', [
                    'lock_key' => $key,
                    'ttl' => $ttl,
                    'wait_seconds' => $waitSeconds,
                    'exception' => $e->getMessage(),
                ]);

                throw new ApiException(
                    $errorMsg ?: __('error_message.program processing'),
                    HttpCode::HTTP_LOCKED->value
                );
            }

            // 持鎖後 callback 拋出的例外（含業務邏輯中的 LockTimeoutException）才記錄 error
            // 取鎖失敗的 ApiException 不在此記錄（$lockAcquired 為 false）
            if ($lockAcquired && $callbackStart !== null) {
                Log::error('持鎖期間發生例外', [
                    'lock_key' => $key,
                    'ttl' => $ttl,
                    'duration' => round(microtime(true) - $callbackStart, 2),
                    'exception' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);
            }

            // 重新拋出異常，讓調用方處理
            throw $e;
        } finally {
            // 安全地釋放鎖
            if ($lockAcquired) {
                try {
                    /**
                     * 檢查鎖是否仍然由當前進程持有。
                     * Laravel 12 的 Lock contract 保證 isOwnedByCurrentProcess() 存在，
                     * 預設為 false 以符合最小信任原則，避免誤釋放其他 Process 的鎖。
                     */
                    $isOwnedByCurrentProcess = $lock->isOwnedByCurrentProcess();

                    if ($isOwnedByCurrentProcess) {
                        $lock->release();
                        Log::debug('已釋放鎖', ['lock_key' => $key]);
                    } else {
                        Log::warning('鎖已過期或不屬於目前程序', [
                            'lock_key' => $key,
                            'duration' => $callbackStart !== null ? round(microtime(true) - $callbackStart, 2) : null,
                        ]);
                    }
                } catch (Throwable $e) {
                    // 釋放鎖失敗（極少發生）
                    Log::error('釋放鎖失敗', [
                        'lock_key' => $key,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
