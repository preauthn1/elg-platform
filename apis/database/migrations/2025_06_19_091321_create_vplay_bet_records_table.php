<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('vplay_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('WinloseID', 191)->unique()->comment('訂單號');
            $table->string('MemberAccount', 191)->index()->comment('玩家名稱');
            $table->string('AgentAccount', 191)->comment('代理商名稱');
            $table->integer('GameTypeID')->comment('遊戲類型代碼');
            $table->string('GameTypeName', 191)->index()->comment('遊戲類型名稱');
            $table->integer('GameID')->index()->comment('遊戲代碼');
            $table->string('GameENAME', 191)->comment('遊戲名稱-英文');
            $table->string('GameNameZHCN', 191)->comment('遊戲名稱-簡單');
            $table->decimal('Bet', 13, 7)->default(0)->comment('投注額');
            $table->decimal('WinLose', 13, 7)->default(0)->comment('輸贏金額');
            $table->decimal('GongPoint', 13, 7)->default(0)->comment('服務費');
            $table->decimal('BetLM', 13, 7)->default(0)->comment('押注幸運金');
            $table->decimal('WinLoseLM', 13, 7)->default(0)->comment('輸贏幸運金');
            $table->decimal('GetJPMoney', 13, 7)->default(0)->comment('JP幸運金');
            $table->dateTime('BetTime')->comment('押注時間(+8時區)');
            $table->dateTime('utc_bet_time')->index()->comment('押注時間(+0時區)');
            $table->dateTime('ReCalcuTime')->comment('結算時間(+8時區)');
            $table->dateTime('utc_recalcu_time')->comment('結算時間(+0時區)');
            $table->integer('BetState')->comment('狀態(0:未結算,1:已結算,2:退單)');
            $table->string('Memo', 191)->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vplay_bet_records');
    }
};
