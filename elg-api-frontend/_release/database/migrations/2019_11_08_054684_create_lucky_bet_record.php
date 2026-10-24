<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateLuckyBetRecord extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('lucky_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->bigInteger('round_id')->nullable()->comment('注單ID');
            $table->bigInteger('userId')->nullable()->comment('玩家ID');
            $table->string('account')->nullable()->comment('玩家在網站的唯一標識');
            $table->string('cur')->nullable()->comment('玩家貨幣符號');
            $table->integer('mid')->nullable()->comment('遊戲ID');
            $table->string('gid')->nullable()->comment('一局SPIN的ID，免費遊戲GID相同');
            $table->string('tid')->nullable()->comment('每局遊戲的交易ID');
            $table->decimal('bet', 13, 7)->comment('投注金額');
            $table->decimal('win', 13, 7)->comment('贏得金額');
            $table->decimal('left', 13, 7)->comment('下注後餘額');
            $table->integer('isGamble')->nullable()->comment('是否為Gamble，1:是0:否');
            $table->integer('isFreeSpin')->nullable()->comment('是否為免費遊戲，1:是0:否');
            $table->integer('freeSpinNum')->nullable()->comment('免費遊戲序號');
            $table->integer('time')->nullable()->comment('該局結算的UTC時間戳記，單位秒');
            $table->dateTime('bet_time')->nullable()->comment('該局結算的UTC時間，單位秒');
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
        Schema::dropIfExists('lucky_bet_records');
    }
}