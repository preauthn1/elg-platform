<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    private const string INDEX_NAME = 'member_bank_accounts_bank_id_index';

    /**
     * member_bank_accounts 原本只有 PRIMARY 與 member_id 兩個索引，而銀行收斂軌的命令
     * 全在按 bank_id 查這張表（BankRepairService::reassignForeignKeys、bank:fix-mismatch
     * 的引用數統計、bank:merge-wintoto-drift-banks 的綁定分類、dedup 家族），每次都是
     * 全表掃描（2026-08-25 實查 TPower 200,628 列、VWorld 152,586 列）。
     *
     * 更關鍵的是鎖面：無索引時 `WHERE bank_id … FOR UPDATE` 會全表掃描並把整張會員綁定表
     * 鎖到交易結束。加了索引也不改變「互斥鎖一律鎖主鍵」的既有慣例（非唯一索引 FOR UPDATE
     * 產生 gap lock），索引只讓挑候選那一步不用掃全表。
     *
     * ADD INDEX 是 INPLACE（不重建表、允許並發 DML），兩站列數皆遠低於 SchemaMonitor 的
     * 50 萬列保護門檻。
     */
    public function up(): void
    {
        if (!SchemaMonitor::hasIndex('member_bank_accounts', self::INDEX_NAME)) {
            SchemaMonitor::table('member_bank_accounts', function (Blueprint $table) {
                $table->index('bank_id', self::INDEX_NAME);
            });
        }

        // 生效斷言：SchemaMonitor 的生產列數保護是「靜默跳過仍記為已執行」。這裡把失敗
        // 前移到 migrate 當下 fail loud，而非拖到依賴索引的查詢變慢才發現。
        if (!SchemaMonitor::hasIndex('member_bank_accounts', self::INDEX_NAME)) {
            throw new RuntimeException(
                'member_bank_accounts bank_id 索引未生效：SchemaMonitor 可能因生產列數保護'
                . '靜默跳過，請確認列數在門檻內後重跑 migration'
            );
        }
    }

    public function down(): void
    {
        if (SchemaMonitor::hasIndex('member_bank_accounts', self::INDEX_NAME)) {
            SchemaMonitor::table('member_bank_accounts', function (Blueprint $table) {
                $table->dropIndex(self::INDEX_NAME);
            });
        }
    }
};
