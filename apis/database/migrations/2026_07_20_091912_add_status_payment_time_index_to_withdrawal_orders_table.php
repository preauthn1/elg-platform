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
     * 供 patch:backfill-withdrawal-payment-time 回填後，校正/回填命令的代付分桶可改用
     * payment_time 篩選（status=24 AND payment_time 範圍）。加大 maxRows：新增次要索引為
     * MySQL 8 online DDL（ALGORITHM=INPLACE, LOCK=NONE），大表加索引亦安全，僅需離峰執行。
     * target/tickets#3967
     */
    public function up(): void
    {
        SchemaMonitor::table('withdrawal_orders', function (Blueprint $table) {
            $table->index(['status', 'payment_time'], 'idx_withdrawal_orders_status_payment_time');
        }, maxRows: 5_000_000);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('withdrawal_orders', function (Blueprint $table) {
            $table->dropIndex('idx_withdrawal_orders_status_payment_time');
        }, maxRows: 5_000_000);
    }
};
