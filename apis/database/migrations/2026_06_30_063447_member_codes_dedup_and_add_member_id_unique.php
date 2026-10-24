<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Phase 2：清理同一 member_id 的重複推廣碼，保留最小 id（最早建立的原始碼）。
        // 併發競態曾使同一會員產生多筆 member_code（target/ticket_tasks#639）；加 unique 前必須先去重，
        // 否則 ADD UNIQUE 會因 duplicate 直接失敗。已驗證待刪 loser 碼無任何下線 invitation_code 引用，零金流風險。
        DB::statement(<<<'SQL'
            DELETE t1 FROM member_codes t1
            JOIN member_codes t2
              ON t1.member_id = t2.member_id
             AND t1.id > t2.id
            WHERE t1.member_id IS NOT NULL
        SQL);

        // Phase 3：member_id 升級為 unique，從 DB 層杜絕重複。
        // 刻意使用 raw DB::statement 而非 SchemaMonitor：member_codes 為大表（>500k 列），
        // SchemaMonitor::table() 會因超過 maxRows 中止並 BotNotify；此處需直接下 DDL。
        // ADD UNIQUE 於 MySQL 8.0 支援 ALGORITHM=INPLACE + LOCK=NONE，線上不鎖表、不阻塞併發 DML，
        // 且 LOCK=NONE 為 fail-safe：若無法無鎖完成則直接報錯，絕不退化成鎖表。
        // 不 DROP 既有的 member_id 普通 index（各白標命名不一致，避免 DROP 失敗）；冗餘 index 無害，可日後統一清理。
        DB::statement('ALTER TABLE member_codes ADD UNIQUE INDEX member_codes_member_id_unique (member_id), ALGORITHM=INPLACE, LOCK=NONE');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE member_codes DROP INDEX member_codes_member_id_unique, ALGORITHM=INPLACE, LOCK=NONE');
    }
};
