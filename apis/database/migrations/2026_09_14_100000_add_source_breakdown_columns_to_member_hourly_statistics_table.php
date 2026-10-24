<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * 會員小時匯總拆六類來源（target/tickets#4333）：既有 deposit_*／withdrawal_* 仍是總額，
     * 新增六組 count／amount 明細欄，三類充值相加＝總充值、三類提現相加＝總提現。
     *
     * 千萬級大表：純加欄位、常數預設值、不指定位置（AFTER 在 8.0.29 前會退化成 INPLACE 重建），
     * MySQL 8 走 ALGORITHM=INSTANT，不重建表、不鎖表。
     * 明細值由 patch:backfill-member-hour-summaries 全期回填補上。
     */
    public function up(): void
    {
        SchemaMonitor::table('member_hourly_statistics', function (Blueprint $table) {
            $table->integer('third_party_deposit_count')->default(0)->comment('三方充值次數（orders 成功、金流商非 AutoDeposit）');
            $table->decimal('third_party_deposit_amount', 18, 4)->default(0)->comment('三方充值總額');
            $table->integer('auto_deposit_count')->default(0)->comment('自助上分次數（orders 成功、金流商 AutoDeposit：前台上傳憑證、後台審核）');
            $table->decimal('auto_deposit_amount', 18, 4)->default(0)->comment('自助上分總額');
            $table->integer('admin_deposit_count')->default(0)->comment('後台開分次數（transaction_logs type=4、order_id=0；客服＋代理後台）');
            $table->decimal('admin_deposit_amount', 18, 4)->default(0)->comment('後台開分總額');
            $table->integer('third_party_withdrawal_count')->default(0)->comment('三方提現次數（withdrawal_orders status=24 三方代付）');
            $table->decimal('third_party_withdrawal_amount', 18, 4)->default(0)->comment('三方提現總額');
            $table->integer('auto_withdrawal_count')->default(0)->comment('自助洗分次數（withdrawal_orders status=4 後台人工轉帳）');
            $table->decimal('auto_withdrawal_amount', 18, 4)->default(0)->comment('自助洗分總額');
            $table->integer('admin_withdrawal_count')->default(0)->comment('後台洗分次數（transaction_logs type=5、order_id=0；客服＋代理後台）');
            $table->decimal('admin_withdrawal_amount', 18, 4)->default(0)->comment('後台洗分總額');
        }, maxRows: 100_000_000);
    }

    public function down(): void
    {
        SchemaMonitor::table('member_hourly_statistics', function (Blueprint $table) {
            $table->dropColumn([
                'third_party_deposit_count', 'third_party_deposit_amount',
                'auto_deposit_count', 'auto_deposit_amount',
                'admin_deposit_count', 'admin_deposit_amount',
                'third_party_withdrawal_count', 'third_party_withdrawal_amount',
                'auto_withdrawal_count', 'auto_withdrawal_amount',
                'admin_withdrawal_count', 'admin_withdrawal_amount',
            ]);
        }, maxRows: 100_000_000);
    }
};
