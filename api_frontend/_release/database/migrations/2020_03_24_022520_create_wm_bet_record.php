<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateWmBetRecord1 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('wm_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('round_id')->comment('注單號');
            $table->string('game_identity')->comment('遊戲識別碼');
            $table->string('currency', 10)->comment('幣別');
            $table->string('wallet_type')->comment('區段/錢包類型');
            $table->string('external_reference')->nullable()->comment('外部編號');
            $table->string('external_user_id')->nullable()->comment('外部用戶識別');
            $table->string('user_token')->nullable()->comment('會員授權碼');
            $table->string('session_token')->nullable()->comment('區段授權碼');
            $table->string('start_time')->nullable()->comment('注單下注時間');
            $table->string('end_time')->nullable()->comment('注單結算時間');
            $table->dateTime('utc_start_time')->nullable()->comment('注單下注時間(UTC+0)');
            $table->dateTime('utc_end_time')->nullable()->comment('注單結算時間(UTC+0)');
            $table->decimal('initial_balance', 13, 7)->default(0)->comment('錢包起始餘額');
            $table->decimal('finial_balance', 13, 7)->default(0)->nullable()->comment('錢包最後餘額');
            $table->decimal('bet_amount', 13, 7)->default(0)->comment('總投注額');
            $table->decimal('win_amount', 13, 7)->default(0)->comment('總贏分');
            $table->decimal('jack_pot_bet', 13, 7)->default(0)->comment('彩池投注額');
            $table->decimal('jack_pot_win', 13, 7)->default(0)->comment('彩池總贏分');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
        Schema::table('wm_bet_records', function (Blueprint $table) {
            $table->index('round_id');
            $table->index('game_identity');
            $table->index('external_user_id');
            $table->index('utc_start_time');
            $table->index('utc_end_time');
        });
    }
}