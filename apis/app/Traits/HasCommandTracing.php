<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 命令追蹤功能 Trait
 *
 * 提供統一的追蹤碼生成、日誌上下文管理功能
 * 可被 CommandProvider 和 ScheduleProvider 共用
 */
trait HasCommandTracing
{
    /**
     * 生成追蹤碼
     *
     * @param  string  $prefix  追蹤碼前綴 (ART 或 CRON)
     * @return string 格式: {PREFIX}_{UUID}
     */
    protected function generateTraceId(string $prefix = 'ART'): string
    {
        return strtoupper($prefix) . '_' . Str::uuid7()->toString();
    }

    /**
     * 設置日誌上下文
     *
     * 後續所有 Log 調用都會自動包含這些上下文資訊
     *
     * @param  string  $traceId  追蹤碼
     * @param  string  $command  命令名稱
     * @param  string  $type  類型 (artisan 或 schedule)
     */
    protected function setLogContext(string $traceId, string $command, string $type = 'artisan'): void
    {
        Log::withContext([
            'trace_id' => $traceId,
            'command' => $command,
            'type' => $type,
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    /**
     * 清除日誌上下文
     *
     * 在命令結束時調用，避免影響後續命令
     */
    protected function clearLogContext(): void
    {
        Log::withoutContext();
    }

    /**
     * 記錄命令開始日誌
     *
     * @param  string  $traceId  追蹤碼
     * @param  string  $command  命令名稱
     * @param  int  $commandLogId  CommandLog 記錄 ID
     * @param  array  $extra  額外資訊
     */
    protected function logCommandStart(string $traceId, string $command, int $commandLogId, array $extra = []): void
    {
        Log::info(
            sprintf('[%s] Command [%s] started', $traceId, $command),
            array_merge([
                'trace_id' => $traceId,
                'command_log_id' => $commandLogId,
            ], $extra)
        );
    }

    /**
     * 記錄命令完成日誌
     *
     * @param  string  $traceId  追蹤碼
     * @param  string  $command  命令名稱
     * @param  int  $commandLogId  CommandLog 記錄 ID
     * @param  float|string  $duration  執行時長（秒）
     * @param  array  $extra  額外資訊
     */
    protected function logCommandFinish(string $traceId, string $command, int $commandLogId, float|string $duration, array $extra = []): void
    {
        Log::info(
            sprintf('[%s] Command [%s] finished, duration: %s seconds', $traceId, $command, $duration),
            array_merge([
                'trace_id' => $traceId,
                'command_log_id' => $commandLogId,
                'duration' => $duration,
            ], $extra)
        );
    }

    /**
     * 記錄命令失敗日誌
     *
     * @param  string  $traceId  追蹤碼
     * @param  string  $command  命令名稱
     * @param  int  $commandLogId  CommandLog 記錄 ID
     * @param  float|string  $duration  執行時長（秒）
     * @param  array  $extra  額外資訊
     */
    protected function logCommandFailed(string $traceId, string $command, int $commandLogId, float|string $duration, array $extra = []): void
    {
        Log::error(
            sprintf('[%s] Command [%s] failed, duration: %s seconds', $traceId, $command, $duration),
            array_merge([
                'trace_id' => $traceId,
                'command_log_id' => $commandLogId,
                'duration' => $duration,
            ], $extra)
        );
    }

    /**
     * 記錄命令跳過日誌
     *
     * @param  string  $traceId  追蹤碼
     * @param  string  $command  命令名稱
     * @param  int  $commandLogId  CommandLog 記錄 ID
     * @param  array  $extra  額外資訊
     */
    protected function logCommandSkipped(string $traceId, string $command, int $commandLogId, array $extra = []): void
    {
        Log::warning(
            sprintf('[%s] Command [%s] skipped', $traceId, $command),
            array_merge([
                'trace_id' => $traceId,
                'command_log_id' => $commandLogId,
            ], $extra)
        );
    }

    /**
     * 格式化執行時長
     *
     * @param  float  $seconds  秒數
     * @param  int  $precision  精度
     */
    protected function formatDuration(float $seconds, int $precision = 4): string
    {
        return number_format($seconds, $precision, '.', '');
    }
}
