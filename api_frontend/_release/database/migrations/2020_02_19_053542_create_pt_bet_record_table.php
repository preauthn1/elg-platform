<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreatePtBetRecordTable extends Migration
{
    protected $table_name = 'pt_bet_records';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable($this->table_name)) {
            Schema::create($this->table_name, function (Blueprint $table) {
                $table->increments('id')->comment('流水號');
                $table->string('player_name')->comment('玩家帳號');
                $table->string('window_code')->comment('窗口號');
                $table->string('game_id')->comment('窗口號');
                $table->string('game_code')->comment('遊戲局號');
                $table->string('game_type')->comment('遊戲類型');
                $table->string('game_name')->comment('遊戲名稱');
                $table->string('session_id')->comment('期號');
                $table->string('currency_code')->comment('幣別');
                $table->decimal('bet', 13, 7)->nullable()->comment('投注額');
                $table->decimal('win', 13, 7)->nullable()->comment('贏得金額');
                $table->decimal('progress_ive_bet', 13, 7)->nullable()->comment('獎池投注額');
                $table->decimal('progress_ive_win', 13, 7)->nullable()->comment('獎池贏得金額');
                $table->decimal('balance', 13, 7)->nullable()->comment('餘額');
                $table->decimal('current_bet', 13, 7)->nullable()->comment('當局投注');
                $table->dateTime('game_date')->comment('投注時間(時區+8)');
                $table->dateTime('utc_game_date')->comment('投注時間(UTC+0)');
                $table->string('info')->comment('詳細信息');
                $table->string('live_network')->comment('現場分類');
                $table->string('rnum')->comment('類型');
                $table->timestamp('created_at')
                    ->useCurrent()
                    ->comment('建立日期');
                $table->timestamp('updated_at')
                    ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                    ->comment('更新日期');
                
                //Index
                $table->index('game_code');
                $table->index('player_name');
                $table->index('utc_game_date');
            });
        }
    }
}