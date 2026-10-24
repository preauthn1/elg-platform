<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateScrBetRecords extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('scr_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('class_id')->nullable()->comment('未知');
            $table->string('account')->comment('918玩家帳號');;
            $table->datetime('bet_time')->nullable()->comment('投注時間');
            $table->datetime('bet_time_utc')->nullable()->comment('投注時間utc');
            $table->decimal('begin_balance', 10, 2)->nullable()->comment('投注前餘額');
            $table->decimal('end_balance', 10, 2)->nullable()->comment('結算後餘額');
            $table->integer('game_id')->nullable()->comment('遊戲ID');
            $table->string('game_name')->nullable()->comment('遊戲名稱');
            $table->integer('line_num')->nullable()->comment('未知');
            $table->string('log_data_str')->nullable()->comment('遊戲記錄');
            $table->integer('log_data_type')->nullable()->comment('未知');
            $table->integer('round_no')->nullable()->comment('未知');
            $table->integer('row_num')->nullable()->comment('未知');
            $table->integer('table_id')->nullable()->comment('桌號');
            $table->decimal('win', 10, 2)->nullable()->comment('輸贏金額');
            $table->decimal('bet', 10, 2)->nullable()->comment('投注金額');
            $table->integer('cday')->nullable()->comment('未知');
            $table->integer('cno')->nullable()->comment('未知');
            $table->string('uuid')->nullable()->comment('未知');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');

            $table->index(['account', 'bet_time']);
            $table->unique(['account', 'uuid']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('scr_bet_records');
    }
}
