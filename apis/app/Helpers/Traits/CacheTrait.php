<?php

declare(strict_types=1);

namespace App\Helpers\Traits;

use Illuminate\Support\Facades\Cache;

trait CacheTrait
{
    public static function genCacheKey(array $params, $prefix = ''): string
    {
        // 保留有效參數
        $params = self::keepValidParams($params);

        // 字典排序, 確保不因參數順序不同而產生不同的緩存鍵
        ksort($params);

        // 將字串轉為數字
        $hash = md5(json_encode($params, JSON_NUMERIC_CHECK));

        if ($prefix) {
            $hash = $prefix . ':' . $hash;
        }

        return $hash;
    }

    /**
     * 保留有效參數
     */
    private static function keepValidParams(array $params): array
    {
        // 排除空值參數
        return array_filter($params, fn ($value) => $value !== '' && $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    public static function deleteCacheKeys(array $cacheKeys = []): void
    {
        if (empty($cacheKeys)) {
            return;
        }

        Cache::deleteMultiple($cacheKeys);
    }
}
