<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * member_wallets 加幣別欄位（多幣別錢包第一期 1.1）
     *
     * 只加欄位：預設 0 對現有錢包完全透明，沒有任何既有邏輯讀它；
     * 唯一鍵改「會員＋類型＋幣別」三元組是後續獨立的 task（已移出第一期），
     * 在所有寫入點帶上幣別之前不可先動鍵，順序反了會建出幣別＝0 的重複列。
     *
     * 走手寫 DDL＋ALGORITHM=INSTANT，不走 SchemaMonitor::table()，理由有二
     * （2026-09-01 圓桌審查定版，工法比照 member_codes_dedup migration 與
     * config/custom.php schema_monitor 節的規範）：
     *
     * 1. fail-safe：不寫 ALGORITHM 時 MySQL 自行挑演算法，INSTANT 條件不滿足
     *    （ROW_FORMAT=COMPRESSED、instant row version 達上限、分割表）會靜默降級成
     *    INPLACE／COPY 重建表——member_wallets 是入金、派彩、遊戲回調共寫的熱表，
     *    重建期間寫入排隊、回調逾時重送。明寫 ALGORITHM=INSTANT 後做不到就直接報錯，
     *    絕不退化成鎖表。六站 MySQL 實測 8.0.35～8.0.41（皆 ≥ 8.0.29），
     *    含 AFTER 指定位置的 ADD COLUMN 皆在 INSTANT 支援範圍內。
     *
     * 2. 不靜默跳過：SchemaMonitor::table() 在正式環境列數超門檻（預設 500000，
     *    TPower 實測 50.9 萬列已超）或 count 失敗時直接 return，migration 仍記為已執行
     *    ——欄位沒建成、當下無感，等第二期程式開始讀寫 currency_id 才在該站全面
     *    unknown column，且會被錯誤歸因到當時的部署。手寫 DDL 失敗就是失敗，當場紅。
     *
     * 部署後仍須逐站確認欄位建成（SHOW COLUMNS FROM member_wallets LIKE 'currency_id'），
     * 六站全綠才算本 task 完成。
     */
    public function up(): void
    {
        // 冪等防護：欄位已存在（重跑、或他途已補）時跳過，避免 duplicate column 中斷整批 migration
        if (Schema::hasColumn('member_wallets', 'currency_id')) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE member_wallets
                ADD COLUMN currency_id INT UNSIGNED NOT NULL DEFAULT 0
                    COMMENT '幣別 currencies.id；非貨幣錢包固定 0'
                    AFTER wallet_type_id,
                ALGORITHM=INSTANT
            SQL);
    }

    /**
     * ⚠️ 正式站事故回滾不需要、也不建議回滾 schema：本欄位對舊版程式完全透明
     * （無任何 runtime 讀寫、DB default 0、14 支 SP 與舊 repo 全為具名欄位寫入），
     * 回滾程式版本即可，欄位留著無害。本方法主要供本機／測試庫使用。
     */
    public function down(): void
    {
        if (!Schema::hasColumn('member_wallets', 'currency_id')) {
            return;
        }

        DB::statement('ALTER TABLE member_wallets DROP COLUMN currency_id, ALGORITHM=INSTANT');
    }
};
