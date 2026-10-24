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
        SchemaMonitor::create('wallet_transaction_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('transaction_no', 191)->index()->comment('單號');
            $table->integer('wallet_type_id')->default(0)->index()->comment('錢包類別ID');
            $table->integer('agent_id')->default(0)->comment('agents.id');
            $table->integer('member_id')->default(0)->index()->comment('members.id');
            $table->integer('vendor_id')->default(0)->comment('vendors.id');
            $table->integer('order_id')->default(0)->index()->comment('orders.id');
            $table->integer('type')->default(0)->index()->comment('類別');
            $table->decimal('operator_before_balance', 18, 4)->default(0)->comment('操作者變更前點數(代理)');
            $table->decimal('operator_balance', 18, 4)->default(0)->comment('操作者餘額(代理)');
            $table->decimal('point', 18, 4)->default(0)->comment('平台金額');
            $table->decimal('vendor_point', 18, 4)->default(0)->comment('遊戲商金額');
            $table->decimal('tax', 18, 4)->default(0)->comment('稅收');
            $table->decimal('rate', 10, 4)->default(1)->comment('平台點數與遊戲點數的比例');
            $table->decimal('before_balance', 18, 4)->default(0)->comment('變更前點數');
            $table->decimal('balance', 18, 4)->default(0)->comment('平台餘額');
            $table->decimal('vendor_balance', 18, 4)->default(0)->comment('遊戲商餘額');
            $table->integer('status')->default(0)->index()->comment('狀態; 0: 失敗, 1000: 成功');
            $table->string('remark', 191)->comment('備註');
            $table->string('checksum', 191)->comment('校驗碼');
            $table->string('result', 191)->comment('結果');
            $table->string('operator', 191)->comment('操作者');
            $table->integer('platform')->default(0)->comment('操作平台0:前台，1:客服，2:代理後台');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('wallet_transaction_logs');
    }
};
