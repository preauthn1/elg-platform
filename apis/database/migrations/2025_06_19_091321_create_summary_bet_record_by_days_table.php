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
        SchemaMonitor::create('summary_bet_record_by_days', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->dateTime('summary_time')->index()->comment('時間');
            $table->integer('agent_id')->index()->comment('代理系統編號');
            $table->integer('member_id')->index()->comment('會員系統編號');
            $table->integer('vendor_id')->index()->comment('遊戲商系統編號');
            $table->integer('game_id')->index()->comment('遊戲系統編號');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('贏得金額');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼');
            $table->decimal('commission', 18, 4)->nullable()->default(0)->comment('佣金');
            $table->decimal('jackpot', 18, 4)->default(0)->comment('彩金');
            $table->decimal('v_jackpot', 18, 4)->default(0)->comment('v_jackpot');
            $table->decimal('cost', 18, 4)->default(0)->comment('成本');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');

            $table->unique(['summary_time', 'member_id', 'vendor_id', 'game_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_bet_record_by_days');
    }
};
