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
        SchemaMonitor::create('total_bet_records', function (Blueprint $table) {
            $table->comment('存放所有遊戲商的投注記錄');
            $table->bigIncrements('id')->comment('流水號');
            $table->dateTime('summary_time')->index()->comment('時間');
            $table->integer('member_id')->index()->comment('會員系統編號');
            $table->integer('vendor_id')->index()->comment('遊戲商系統編號');
            $table->unsignedBigInteger('bet_record_id')->comment('XXX_bet_record.id');
            $table->integer('game_id')->index()->comment('遊戲系統編號');
            $table->string('reference_no', 100)->comment('來源單號');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效投注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('jackpot', 18, 4)->default(0)->comment('jackpot');
            $table->decimal('bonus', 18, 4)->default(0)->comment('bonus');
            $table->decimal('commission', 18, 4)->default(0)->comment('佣金');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼');
            $table->decimal('valid_bet_rate', 18, 4)->default(0)->comment('轉換比例');
            $table->decimal('bak', 18, 4)->default(0)->comment('備用1');
            $table->decimal('bak1', 18, 4)->default(0)->comment('備用2');
            $table->dateTime('bet_time')->index()->comment('投注時間(UTC)');
            $table->dateTime('payout_time')->index()->comment('派彩、結算時間(UTC)');
            $table->dateTime('created_at')->useCurrent()->comment('建立日期');
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(51)->comment('更新者ID');

            $table->unique(['vendor_id', 'bet_record_id'], 'unique_index');
            $table->primary(['id', 'vendor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('total_bet_records');
    }
};
