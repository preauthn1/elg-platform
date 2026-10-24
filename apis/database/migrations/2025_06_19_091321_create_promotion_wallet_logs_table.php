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
        SchemaMonitor::create('promotion_wallet_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('transaction_no', 191)->comment('單號');
            $table->integer('agent_id')->comment('agents.id');
            $table->integer('member_id')->index()->comment('members.id');
            $table->integer('event_id')->index()->comment('events.id');
            $table->integer('promotion_wallet_id')->default(0)->index()->comment('promotion_wallets.id');
            $table->integer('vendor_id')->comment('vendors.id');
            $table->integer('order_id')->comment('訂單系統編號');
            $table->integer('type')->comment('類型1:平台轉入遊戲商,2:遊戲商轉入平台');
            $table->decimal('point', 18, 4)->default(0)->comment('平台金額');
            $table->decimal('vendor_point', 18, 4)->default(0)->comment('遊戲商金額');
            $table->decimal('rate', 10, 4)->default(1)->comment('平台點數與遊戲點數的比例');
            $table->decimal('before_balance', 18, 4)->default(0)->comment('變更前金額');
            $table->decimal('balance', 18, 4)->default(0)->comment('平台餘額');
            $table->decimal('vendor_balance', 18, 4)->default(0)->comment('遊戲商餘額');
            $table->integer('status')->default(0)->comment('狀態編號');
            $table->string('remark', 191)->comment('備註');
            $table->string('result', 191)->comment('結果');
            $table->string('operator', 191)->comment('操作者');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('promotion_wallet_logs');
    }
};
