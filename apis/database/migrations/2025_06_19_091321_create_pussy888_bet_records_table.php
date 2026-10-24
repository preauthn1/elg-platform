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
        SchemaMonitor::create('pussy888_bet_records', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('系統編號');
            $table->integer('class_id')->comment('ClassID');
            $table->string('account', 20)->index()->comment('pussy888玩家帳號');
            $table->dateTime('bet_time')->index()->comment('注單生成時間');
            $table->dateTime('bet_time_utc')->index()->comment('注單生成時間(UTC)');
            $table->decimal('begin_balance', 18, 4)->comment('遊戲開始金額');
            $table->decimal('end_balance', 18, 4)->comment('遊戲結束金額');
            $table->integer('game_id')->index()->comment('遊戲ID');
            $table->string('game_name', 50)->comment('遊戲名稱');
            $table->integer('line_num');
            $table->string('log_data_str', 191)->comment('遊戲紀錄');
            $table->integer('log_data_type');
            $table->integer('round_no');
            $table->integer('row_num');
            $table->integer('table_id')->comment('桌號');
            $table->decimal('win', 18, 4)->comment('派彩金額');
            $table->decimal('bet', 18, 4)->comment('下注金額');
            $table->integer('cday');
            $table->integer('cno');
            $table->string('uuid', 50)->unique()->comment('uuid');
            $table->text('info')->nullable()->comment('詳細信息');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('pussy888_bet_records');
    }
};
