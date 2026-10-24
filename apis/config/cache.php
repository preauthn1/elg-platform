<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
 * 並行測試（paratest）的快取隔離。
 *
 * paratest 每個 process 帶不同的 TEST_TOKEN，且各自連到 `{DB_DATABASE}_test_{TOKEN}`；
 * 各庫的自增 id 彼此獨立，所以同一個 id（agent 1、member 1…）會同時存在於多個 process。
 * 快取若共用，以 id 組出的 key（如 VendorService 的 vendorServiceVendorIds）就會跨 process
 * 互相污染，測試結果取決於誰先寫入。
 *
 * file store 不吃 `prefix`（FileStore 沒有 prefix 概念），必須改目錄；其餘 store 靠 prefix 隔離。
 * 非測試環境沒有 TEST_TOKEN，行為與原本完全相同。
 */
$parallelTestToken = [REDACTED]'TEST_TOKEN');

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    'default' => env('CACHE_STORE', 'database'),

    /*
     * 限流計數（RateLimiter / throttle middleware）使用的 store。
     *
     * 與 default 分開：計數必須跨 pod 共用才擋得住（工單 4540 忘記密碼限流），
     * 不能跟著 default 落到單機的 file／database。
     */
    'limiter' => env('CACHE_LIMITER_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    |                    "redis", "dynamodb", "octane", "null"
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data' . ($parallelTestToken ? "/test_{$parallelTestToken}" : '')),
            'lock_path' => storage_path('framework/cache/data' . ($parallelTestToken ? "/test_{$parallelTestToken}" : '')),
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '0.0.0.0'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'json' => [
            'driver' => 'redis',
            'connection' => 'json',
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_cache_')
        . ($parallelTestToken ? "test_{$parallelTestToken}_" : ''),

];
