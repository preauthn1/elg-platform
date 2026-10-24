<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateLgsDailyWinTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('igs_daily_wins', function (Blueprint $table) {
            $table->increments('id');
            $table->string('user_id', 50)->comment('玩家id');
            $table->dateTime('date_time')->comment('時間');
            $table->decimal('win_lose', 18, 4)->comment('總輸贏');
            $table->decimal('bet_times', 18, 4)->comment('總投注量');
            $table->decimal('bet', 18, 4)->comment('總下注額');
            $table->dateTime('utc_date_time')->comment('utc時間');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('igs_daily_wins');
    }
}