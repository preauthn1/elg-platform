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
        SchemaMonitor::create('event_details', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('event_id')->index()->comment('活動編號');
            $table->integer('action_id')->comment('行為編號; params.type=event_action');
            $table->integer('order_type_id')->nullable()->comment('訂單類型編號');
            $table->integer('vendor_id')->nullable()->comment('遊戲商系統編號');
            $table->integer('game_id')->nullable()->comment('遊戲系統編號');
            $table->integer('game_join_event_used')->default(1)->comment('是否使用games的join_bet_event / join_payout_event');
            $table->tinyInteger('bind_unlimited')->nullable()->default(0)->comment('邀請時是否綁定全民代理');
            $table->decimal('rate', 10)->comment('匯率');
            $table->tinyInteger('game_type_id')->nullable()->comment('遊戲分類');
            $table->integer('point')->comment('達標點數');
            $table->dateTime('start_time')->index()->comment('開始時間');
            $table->dateTime('end_time')->index()->comment('結束時間');
            $table->dateTime('draw_time')->comment('派獎時間');
            $table->integer('expire_hour')->default(0)->comment('獎勵領取期限(小時)');
            $table->boolean('ip_block')->default(false)->comment('限制IP');
            $table->boolean('phone_block')->default(false)->comment('限制電話');
            $table->boolean('browser_block')->default(false)->comment('限制瀏覽器序號');
            $table->boolean('account_block')->default(false)->comment('限制真實姓名');
            $table->integer('vendor_lock')->default(0)->comment('是否鎖定遊戲商入口');
            $table->boolean('status')->default(false)->index()->comment('狀態0:停用,1:啟用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_details');
    }
};
