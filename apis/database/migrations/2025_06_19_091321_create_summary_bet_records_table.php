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
        SchemaMonitor::create('summary_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->dateTime('summary_time')->index()->comment('時間');
            $table->dateTime('data_time')->index()->comment('數據時間');
            $table->integer('member_id')->index()->comment('會員系統編號');
            $table->integer('vendor_id')->index()->comment('遊戲商系統編號');
            $table->integer('game_id')->index()->comment('遊戲系統編號');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('贏得金額');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼');
            $table->decimal('commission', 18, 4)->nullable()->default(0)->comment('佣金');
            $table->decimal('jackpot', 18, 4)->default(0)->comment('彩金');
            $table->integer('record_count')->default(0)->comment('資料計數');
            $table->decimal('bak', 18, 4)->default(0)->comment('備用');
            $table->decimal('bak1', 18, 4)->default(0)->comment('備用');
            $table->decimal('percentage')->nullable()->comment('分成百分比');
            $table->decimal('share_amount')->nullable()->comment('分成金額');
            $table->string('share_range', 191)->nullable()->comment('分成區間');
            $table->decimal('v_jackpot', 18, 4)->default(0)->comment('v_jackpot');
            $table->decimal('cost', 18, 4)->default(0)->comment('成本');
            $table->tinyInteger('correction')->default(0)->comment('改正標示');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');

            $table->primary(['id', 'summary_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_bet_records');
    }
};
