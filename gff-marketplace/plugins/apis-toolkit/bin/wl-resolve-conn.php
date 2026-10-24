<?php

declare(strict_types=1);

/**
 * 白標 read 連線解析器（whitelabel-db-query MCP 用）
 *
 * 在 php-fpm8 容器內 boot Laravel 一次，解析指定 connection 的 effective read
 * 座標（父層 {database,port} ⊕ `.read`{host,user,pass}——config 為淺合併，
 * 父層值需保留），以 KEY=VALUE 印到 stdout 供 host 端 wrapper（mysql-mcp.js）擷取。
 *
 * 用法（host 端由 wrapper 餵入容器執行）：
 *   docker exec -i php-fpm8 bash -c "cd /var/www/APIs && php -- <connectionId>" \
 *     < bin/wl-resolve-conn.php
 *
 * 安全：輸出含密碼，僅供 wrapper 程序內部消費（轉成 benborla 的 MYSQL_* env），
 * 永不經 Claude context。connection 不存在 / host / user 缺 → 非零 exit、不輸出。
 */

function wl_effective_read(array $conn): array
{
    $read = is_array($conn['read'] ?? null) ? $conn['read'] : [];

    $host = $read['host'] ?? $conn['host'] ?? null;
    if (is_array($host)) {
        $host = $host[0] ?? null;
    }
    $user = $read['username'] ?? $conn['username'] ?? null;
    $password = [REDACTED] ($read['password'] ?? $conn['password'] ?? '');
    $port = (string) ($read['port'] ?? $conn['port'] ?? '3306');
    $database = $conn['database'] ?? null;

    if (! is_string($host) || $host === '' || ! is_string($user) || $user === '' || ! is_string($database) || $database === '') {
        throw new RuntimeException('effective read 座標不完整（host / user / database 必填）');
    }

    return ['HOST' => $host, 'PORT' => $port, 'USER' => $user, 'PASS' => $password, 'DATABASE' => $database];
}

$conn = $argv[1] ?? '';
if (preg_match('/^[A-Za-z0-9]+$/', $conn) !== 1) {
    fwrite(STDERR, "[wl-resolve-conn] connection 名稱僅允許英數字：{$conn}\n");
    exit(2);
}

if (! file_exists('vendor/autoload.php')) {
    fwrite(STDERR, "[wl-resolve-conn] 找不到 vendor/autoload.php——必須在 /var/www/APIs 下執行。\n");
    exit(3);
}

// fail-closed：config 被 cache 時 config() 讀快取而非 .env，解析會 stale。
// 此環境本就禁 config:cache（會鎖死 DB_CONNECTION 注入）；遇到即拒，不靜默用舊值。
if (file_exists('bootstrap/cache/config.php')) {
    fwrite(STDERR, "[wl-resolve-conn] fail-closed：bootstrap/cache/config.php 存在，config 讀快取會 stale。請在容器內跑 php artisan config:clear 後重試。\n");
    exit(6);
}

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connConfig = config("database.connections.{$conn}");
if (! is_array($connConfig)) {
    fwrite(STDERR, "[wl-resolve-conn] connection 不存在於 config/database.php：{$conn}\n");
    exit(4);
}

try {
    foreach (wl_effective_read($connConfig) as $k => $v) {
        // 值不得含換行（避免污染 KEY=VALUE 解析）；密碼含換行極罕見，遇到即 fail。
        if (str_contains((string) $v, "\n")) {
            throw new RuntimeException("欄位 {$k} 含換行，拒絕輸出");
        }
        echo "{$k}={$v}\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[wl-resolve-conn] {$e->getMessage()}\n");
    exit(5);
}
