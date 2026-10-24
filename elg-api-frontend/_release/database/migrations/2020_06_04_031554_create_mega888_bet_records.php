<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMega888BetRecords extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mega888_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('ref_id')->comment('識別碼 {player_id}-{game_id}-{bet_time}-{bet_id]');
            $table->string('btype')->comment('遊戲分類 (single, live)');
            $table->integer('class_id')->nullable()->comment('未知');
            $table->string('player_id')->comment('玩家帳號');;
            $table->datetime('bet_time')->nullable()->comment('投注時間');
            $table->datetime('bet_time_utc')->nullable()->comment('投注時間utc');
            $table->decimal('begin_balance', 10, 2)->nullable()->comment('投注前餘額');
            $table->decimal('end_balance', 10, 2)->nullable()->comment('結算後餘額');
            $table->integer('game_id')->nullable()->comment('遊戲ID');
            $table->string('game_name')->nullable()->comment('遊戲名稱');
            $table->integer('line_num')->nullable()->comment('未知');
            $table->string('log_data_str')->nullable()->comment('遊戲記錄');
            $table->integer('log_data_type')->nullable()->comment('未知');
            $table->integer('round_no')->nullable()->comment('局號');
            $table->integer('row_num')->nullable()->comment('未知');
            $table->integer('table_id')->nullable()->comment('桌號');
            $table->decimal('win', 10, 2)->nullable()->comment('派彩金額');
            $table->decimal('bet', 10, 2)->nullable()->comment('投注金額');
            $table->integer('bet_id')->nullable()->comment('未知');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('created_by')->comment('建立者ID');
            $table->integer('updated_by')->comment('更新者ID');
            $table->index(['player_id', 'bet_time_utc']);
            $table->unique(['ref_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mega888_bet_records');
    }
}
