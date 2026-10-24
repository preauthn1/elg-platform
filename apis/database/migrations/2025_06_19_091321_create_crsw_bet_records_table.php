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
        SchemaMonitor::create('crsw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 200)->unique()->comment('注單id');
            $table->string('username', 200)->index()->comment('玩家帳號');
            $table->string('game_code', 200)->comment('遊戲編號, gameId from request');
            $table->integer('result')->comment('0:未有結果, 1:輸, 2:贏, 3:和, 4:取消');
            $table->integer('status')->comment('1:PlaceBet, 2:CancelBet, 3:RevertBet, 4:SettleBet, 5:UnsettleBet');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('有效下注金額');
            $table->decimal('de_bet', 18, 4)->default(0)->comment('實際扣除金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chips', 18, 4)->default(0)->comment('使用禮碼');
            $table->dateTime('bet_time')->comment('下注時間');
            $table->dateTime('result_date')->nullable()->comment('算結果的日期時間');
            $table->dateTime('settle_date')->comment('注單異動時間');
            $table->dateTime('order_date')->comment('歸帳日期');
            $table->json('infos')->nullable()->comment('詳細資訊');
            $table->json('result_infos')->nullable()->comment('result 詳細資訊');
            $table->timestamps();

            $table->foreign(['username'])->references(['username'])->on('crsw_accounts')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('crsw_bet_records');
    }
};
