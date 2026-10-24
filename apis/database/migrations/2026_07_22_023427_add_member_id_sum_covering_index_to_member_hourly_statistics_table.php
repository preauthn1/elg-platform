<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 會員活動篩選器（agent/api/member/activity-filter）以金額/次數篩選時，會對
     * member_hourly_statistics 做 GROUP BY member_id + SUM(代收/代付 金額·次數)。
     * 加此覆蓋索引後，該聚合可走 index-only（免回表、member_id 已排序免暫存表），
     * admin 全站聚合的最壞情況由 type=ALL 改善為索引掃描。
     *
     * 千萬級大表：新增次要索引為 MySQL 8 online DDL（ALGORITHM=INPLACE, LOCK=NONE），
     * 安全但耗時，需離峰執行；maxRows 放大以涵蓋各白標實際筆數。
     * target/tickets#3967
     */
    public function up(): void
    {
        SchemaMonitor::table('member_hourly_statistics', function (Blueprint $table) {
            $table->index(
                ['member_id', 'deposit_count', 'deposit_amount', 'withdrawal_count', 'withdrawal_amount'],
                'idx_mhs_member_id_sum_covering'
            );
        }, maxRows: 100_000_000);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('member_hourly_statistics', function (Blueprint $table) {
            $table->dropIndex('idx_mhs_member_id_sum_covering');
        }, maxRows: 100_000_000);
    }
};
