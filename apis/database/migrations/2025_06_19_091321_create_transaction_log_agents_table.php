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
        SchemaMonitor::create('transaction_log_agents', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('from_agent_id')->index()->comment('發送者的代理系統編號');
            $table->integer('to_agent_id')->index()->comment('接收者的代理系統編號');
            $table->integer('type')->index()->comment('類型1:開分, 2:洗分, 3:代理模式獎勵(全民代理三層返水), 4:代理傭金派發, 5:分桶獎勵');
            $table->integer('order_id')->default(0)->index()->comment('withdrawal_order_agents.id');
            $table->decimal('amount', 18, 4)->comment('金額');
            $table->decimal('rolling_chip_amount', 18, 4)->default(0)->comment('禮碼');
            $table->decimal('from_limit_of_credit', 18, 4)->comment('發送者剩餘額度');
            $table->decimal('from_used_credit', 18, 4)->comment('發送者已使用額度');
            $table->decimal('to_limit_of_credit', 18, 4)->comment('接收者剩餘額度');
            $table->decimal('to_used_credit', 18, 4)->comment('接收者已使用額度');
            $table->string('operator', 191)->nullable()->index()->comment('實際操作者(有可能是子帳號)');
            $table->integer('platform')->default(1)->comment('操作平台1:客服，2:代理後台');
            $table->string('remark', 191)->nullable()->comment('備註');
            $table->integer('status')->default(0)->index()->comment('狀態0:失敗，1:成功');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('transaction_log_agents');
    }
};
