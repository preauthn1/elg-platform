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
        SchemaMonitor::create('spadegamingsw_bet_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 200)->unique()->comment('交易 id');
            $table->string('reference_bet_id', 200)->nullable()->comment('關聯交易 id');
            $table->string('username', 200)->comment('玩家帳號');
            $table->string('game_code', 200)->comment('遊戲編號');
            $table->integer('type')->index()->comment('1: 下注, 2: 取消下注, 4: 派彩, 7: 紅包');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0)->comment('合法下注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金額');
            $table->decimal('rolling_chips', 18, 4)->default(0)->comment('使用禮碼');
            $table->dateTime('bet_time')->comment('下注時間');
            $table->dateTime('payout_time')->index()->comment('派彩/取消/bonus時間');
            $table->timestamps();
            $table->json('infos')->nullable()->comment('詳細資訊');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('spadegamingsw_bet_records');
    }
};
