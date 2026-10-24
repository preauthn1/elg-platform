<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMega888DailyWinTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mega888_daily_wins', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->date('date')->comment('日期');
            $table->integer('type')->comment('類型，1:mega電子遊戲2:BG視訊');
            $table->string('loginId')->comment('玩家編號');
            $table->decimal('bet')->comment('投注金額');
            $table->decimal('win')->comment('贏得金額');
            $table->decimal('yieId')->comment('收益率');
            $table->string('userId')->comment('玩家id');
            $table->string('name')->comment('玩家暱稱');
            $table->string('memo')->nullable()->comment('備註');
            $table->string('tel')->nullable()->comment('電話');
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
        Schema::dropIfExists('mega888_daily_wins');
    }
}