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
        SchemaMonitor::create('event_total_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->dateTime('summary_time')->index()->comment('時間');
            $table->bigInteger('bet_record_id')->default(0)->index();
            $table->integer('member_id')->index()->comment('會員系統編號');
            $table->integer('vendor_id')->index()->comment('遊戲商系統編號');
            $table->integer('game_id')->index()->comment('遊戲系統編號');
            $table->string('reference_no', 100)->comment('來源單號');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效投注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->dateTime('bet_time')->comment('投注時間(UTC)');
            $table->dateTime('payout_time')->comment('派彩、結算時間(UTC)');
            $table->dateTime('record_time')->comment('xxx_bet_record.created_at');
            $table->dateTime('settlement_time')->useCurrent()->comment('算帳時間');
            $table->dateTime('created_at')->useCurrent()->comment('建立日期');
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_total_bet_records');
    }
};
