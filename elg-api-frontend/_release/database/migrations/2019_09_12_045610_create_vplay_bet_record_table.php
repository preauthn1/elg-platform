<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateVplayBetRecordTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vplay_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('WinloseID')->comment('訂單號');
            $table->string('MemberAccount')->comment('玩家名稱');
            $table->string('AgentAccount')->comment('代理商名稱');
            $table->integer('GameTypeID')->comment('遊戲類型代碼');
            $table->string('GameTypeName')->comment('遊戲類型名稱');
            $table->integer('GameID')->comment('遊戲代碼');
            $table->string('GameENAME')->comment('遊戲名稱-英文');
            $table->string('GameNameZHCN')->comment('遊戲名稱-簡單');
            $table->decimal('Bet', 10, 2)->comment('投注額');
            $table->decimal('WinLose', 10, 2)->comment('輸贏金額');
            $table->decimal('GongPoint', 10, 2)->comment('服務費');
            $table->decimal('BetLM', 10, 2)->comment('押注幸運金');
            $table->decimal('WinLoseLM', 10, 2)->comment('輸贏幸運金');
            $table->decimal('GetJPMoney', 10, 2)->comment('JP幸運金');
            $table->dateTime('BetTime')->comment('押注時間(+8時區)');
            $table->dateTime('utc_bet_time')->comment('押注時間(+0時區)');
            $table->dateTime('ReCalcuTime')->comment('結算時間(+8時區)');
            $table->dateTime('utc_recalcu_time')->comment('結算時間(+0時區)');
            $table->integer('BetState')->comment('狀態(0:未結算,1:已結算,2:退單)');
            $table->string('Memo')->comment('備註');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
        });
    }
}