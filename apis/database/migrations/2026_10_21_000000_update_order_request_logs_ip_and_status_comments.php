<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 把 `order_request_logs` 兩個欄位的註解改成與實際行為一致（target/tickets#4555）。
 *
 * 建表時這兩欄只有入站或只有出站填得出來，註解照實寫成「入站才有」「入站留空」。
 * 現在兩個方向都填得到：
 *  - `source_ip`：出站改存對端（三方伺服器）IP，取自 curl 的 `primary_ip`
 *  - `http_status`：入站改存我方回給三方的狀態（callback 回應 DTO 的 `httpStatusCode`）
 *
 * 舊註解留著會把人導向錯的結論（看到出站有 IP 會以為資料髒了），所以連既有站一起改。
 * 建表 migration 的註解已同步，這支只處理已經建好的表。
 *
 * 不走 `Schema::table()->change()`：Laravel 改註解要重述整個欄位定義，漏一個屬性就
 * 連型別一起改掉。這裡用原生 DDL 只動 COMMENT，並明寫 `ALGORITHM=INSTANT`——
 * MySQL 8.0 改註解本來就是 INSTANT，寫出來是為了「不是 INSTANT 就直接失敗」而不是
 * 靜悄悄退回 COPY 去鎖表。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('order_request_logs')) {
            return;
        }

        DB::statement("
            ALTER TABLE `order_request_logs`
                MODIFY COLUMN `source_ip` VARCHAR(255)
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
                    COMMENT '對端 IP：入站＝三方打來的來源 IP、出站＝三方伺服器 IP',
                MODIFY COLUMN `http_status` SMALLINT NULL
                    COMMENT 'HTTP 狀態：出站＝三方回我方的、入站＝我方回三方的',
                ALGORITHM=INSTANT
        ");

        // 改註解是靜默操作，沒生效也不會報錯，所以讀回來對一次
        $comments = collect(DB::select(
            'SHOW FULL COLUMNS FROM `order_request_logs` WHERE Field IN (?, ?)',
            ['source_ip', 'http_status']
        ))->pluck('Comment', 'Field');

        if (!str_contains((string) $comments->get('source_ip'), '對端 IP')
            || !str_contains((string) $comments->get('http_status'), '入站＝我方回三方的')) {
            throw new RuntimeException('order_request_logs 的欄位註解未更新，中止');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('order_request_logs')) {
            return;
        }

        DB::statement("
            ALTER TABLE `order_request_logs`
                MODIFY COLUMN `source_ip` VARCHAR(255)
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
                    COMMENT '入站才有，取自 Common::getIp()',
                MODIFY COLUMN `http_status` SMALLINT NULL
                    COMMENT '出站＝三方回應的 HTTP 狀態；入站留空',
                ALGORITHM=INSTANT
        ");
    }
};
