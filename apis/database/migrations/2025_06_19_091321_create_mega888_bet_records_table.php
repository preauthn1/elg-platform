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
        SchemaMonitor::create('mega888_bet_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('ref_id', 50)->unique()->comment('識別碼 {player_id}-{game_id}-{bet_time}-{bet_id]');
            $table->string('btype', 10)->comment('遊戲分類 (single, live)');
            $table->integer('class_id')->nullable()->comment('未知');
            $table->string('player_id', 20)->comment('玩家帳號');
            $table->dateTime('bet_time')->nullable()->index()->comment('投注時間');
            $table->dateTime('bet_time_utc')->nullable()->comment('投注時間utc');
            $table->decimal('begin_balance', 18, 4)->nullable()->comment('投注前餘額');
            $table->decimal('end_balance', 18, 4)->nullable()->comment('結算後餘額');
            $table->integer('game_id')->nullable()->index()->comment('遊戲ID');
            $table->string('game_name', 50)->nullable()->comment('遊戲名稱');
            $table->integer('line_num')->nullable()->comment('未知');
            $table->string('log_data_str', 191)->nullable()->comment('遊戲記錄');
            $table->integer('log_data_type')->nullable()->comment('未知');
            $table->integer('round_no')->nullable()->comment('局號');
            $table->integer('row_num')->nullable()->comment('未知');
            $table->integer('table_id')->nullable()->comment('桌號');
            $table->decimal('win', 18, 4)->nullable()->comment('派彩金額');
            $table->decimal('bet', 18, 4)->nullable()->comment('投注金額');
            $table->integer('bet_id')->nullable()->comment('未知');
            $table->string('info', 500)->nullable()->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->integer('created_by')->comment('建立者ID');
            $table->integer('updated_by')->comment('更新者ID');

            $table->index(['player_id', 'bet_time_utc']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('mega888_bet_records');
    }
};
