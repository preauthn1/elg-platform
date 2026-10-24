<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * wudisw／fumasw 注單表補 payout_time 索引（對帳查詢 WHERE payout_time 區間用）。
 *
 * 六站生產庫已於 2026-09-03〜04 建妥同名索引——小表直接 ADD INDEX、三張大表走
 * 鏡像表回填＋原子換表（見 docs/reconcile/payout-time-鏡像表建置.md）；本 migration
 * 補測試庫與日後新站台，索引名與線上一致：{table}_payout_time_index。
 *
 * 冪等與大表防線：payout_time 已有以它為第一欄的索引（不論名稱）即跳過；
 * 生產環境若表超過 50 萬列且缺索引，SchemaMonitor 會取消操作並發 TG 通知
 * 轉人工（走 Olympus schema_operation 鏡像表流程），不會在部署中跑大 ALTER。
 */
return new class extends Migration
{
    private const array TABLES = ['wudisw_bet_records', 'fumasw_bet_records'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (!Schema::hasTable($tableName) || $this->hasPayoutTimeLeadingIndex($tableName)) {
                continue;
            }

            SchemaMonitor::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->index('payout_time', $tableName . '_payout_time_index');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (!Schema::hasTable($tableName) || !$this->hasIndexNamed($tableName, $tableName . '_payout_time_index')) {
                continue;
            }

            SchemaMonitor::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex($tableName . '_payout_time_index');
            });
        }
    }

    /**
     * 是否已有以 payout_time 為第一欄的索引（不論名稱，避免異名重複建索引）。
     */
    private function hasPayoutTimeLeadingIndex(string $tableName): bool
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['columns'][0] ?? null) === 'payout_time') {
                return true;
            }
        }

        return false;
    }

    private function hasIndexNamed(string $tableName, string $indexName): bool
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            if ($index['name'] === $indexName) {
                return true;
            }
        }

        return false;
    }
};
