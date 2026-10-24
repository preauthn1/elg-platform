<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateXe88DailyWin extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('xe88_daily_wins', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->date('date')->nullable()->comment('日期');
            $table->string('username')->nullable()->comment('玩家帳號');
            $table->decimal('bets', 13, 7)->comment('總投注金額');
            $table->decimal('wins', 13, 7)->comment('總贏得金額');
            $table->decimal('turnover', 13, 7)->comment('進出金額');
            $table->decimal('houseearnings', 13, 7)->comment('總輸贏');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')
                ->default(0)
                ->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('xe88_daily_wins');
    }
}