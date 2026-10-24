<?php

declare(strict_types=1);

namespace App\Helpers\Traits;

use Illuminate\Support\Str;

trait MonitorTrait
{
    /**
     * 將查詢物件轉換為 SQL 語句
     */
    public static function queryToSql(string $sql, array $bindings): string
    {
        return Str::replaceArray('?', collect($bindings)->map(function ($binding) {
            if ($binding instanceof \DateTimeInterface) {
                return "'" . $binding->format('Y-m-d H:i:s') . "'";
            } elseif (is_string($binding)) {
                return "'" . addslashes($binding) . "'";
            } elseif (is_bool($binding)) {
                return $binding ? '1' : '0';
            } elseif (is_null($binding)) {
                return 'NULL';
            }

            // 其他數字或可轉成字串的
            return (string) $binding;
        })->toArray(), $sql);
    }

    /**
     * 取得 SQL 語句呼叫堆疊的檔案位置
     */
    public static function getQueryCallerFile(): ?string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 21);
        foreach ($trace as $key => $frame) {
            // 略過 Middleware/RequestLogger.php:71
            if ($key === 0) {
                continue;
            }
            if (isset($frame['file']) && str_contains($frame['file'], base_path()) && !str_contains($frame['file'], '/vendor/')) {
                return $frame['file'] . ':' . ($frame['line'] ?? '');
            }
        }

        return null;
    }
}
