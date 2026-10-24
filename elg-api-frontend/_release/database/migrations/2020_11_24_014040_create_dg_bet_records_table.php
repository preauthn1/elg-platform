<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateDgBetRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('dg_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('betId')->default(0)->comment('注单唯一Id')->unique();
            $table->string('userName')->comment('玩家帳號')->index();
            $table->integer('lobbyId')->default(0)->comment('游戏大厅号3和4:现场厅, 5:南海厅 , 7:国际厅');
            $table->integer('tableId')->default(0)->comment('游戏桌号');
            $table->bigInteger('shoeId')->default(0)->comment('游戏靴号');
            $table->bigInteger('playId')->default(0)->comment('游戏局号');
            $table->integer('GameType')->default(0)->comment('游戏类型');
            $table->integer('GameId')->default(0)->comment('游戏Id');
            $table->bigInteger('memberId')->default(0)->comment('会员Id');
            $table->timestamp('betTime')->default(0)->comment('押注時間');
            $table->timestamp('calTime')->default(0)->comment('结算时间');
            $table->timestamp('betTimeUtc')->default(0)->comment('押注時間 UTC')->index();
            $table->timestamp('calTimeUtc')->default(0)->comment('结算时间 UTC')->index();
            $table->decimal('winOrLoss', 18, 4)->comment('派彩金额 (输赢应扣除下注金额)');
            $table->decimal('betPoints', 18, 4)->comment('下注金额');
            $table->decimal('betPointsz', 18, 4)->comment('好路追注金额');
            $table->decimal('availableBet', 18, 4)->comment('有效下注金额');
            $table->text('result')->comment('游戏结果');
            $table->text('betDetail')->comment('下注注单');
            $table->text('ip')->comment('游戏结果');
            $table->text('ext')->comment('游戏唯一ID');
            $table->integer('isRevocation')->comment('是否结算：0:未结算, 1:已结算, 2:已撤销(该注单为对冲注单)');
            $table->decimal('balanceBefore', 18, 4)->comment('余额');
            $table->bigInteger('parentBetId')->default(0)->comment('货币ID');
            $table->integer('currencyId')->default(0)->comment('撤销的那比注单的ID');
            $table->integer('deviceType')->default(0)->comment('下注时客户端类型');
            $table->integer('pluginid')->default(0)->comment('追注转账流水号');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期')->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('dg_bet_records');
    }
}
