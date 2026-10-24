<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('member_statistics', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('流水號');
            $table->integer('member_id')->comment('會員系統編號');
            $table->timestamp('last_activity_at')->nullable()->comment('最後請求API時間');
            $table->timestamp('last_deposit_at')->nullable()->comment('最後代收成功時間');
            $table->timestamp('last_withdraw_at')->nullable()->comment('最後代付成功時間');
            $table->timestamp('last_bet_at')->nullable()->comment('最後投注時間');
            $table->integer('total_login_fail_count')->default(0)->comment('總登入錯誤次數');
            $table->integer('login_fail_count')->default(0)->comment('登入錯誤次數');
            $table->integer('forget_pwd_count')->default(0)->comment('忘記密碼次數');
            $table->integer('payment_pwd_fail_count')->nullable()->default(0)->comment('支付密碼錯誤次數');
            $table->integer('validation_code_fail_count')->nullable()->default(0)->comment('驗證碼錯誤次數');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->unique('member_id', 'unique_member_id');

            // 成員活動篩選器(3967)日期篩選欄位:避免 ms.last_*_at 篩選時全表掃描。
            // last_login 走 members.idx_members_last_login;last_activity_at 僅顯示不可篩且更新最頻繁,故不建索引。
            $table->index('last_deposit_at', 'idx_member_statistics_last_deposit_at');
            $table->index('last_withdraw_at', 'idx_member_statistics_last_withdraw_at');
            $table->index('last_bet_at', 'idx_member_statistics_last_bet_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_statistics');
    }
};
