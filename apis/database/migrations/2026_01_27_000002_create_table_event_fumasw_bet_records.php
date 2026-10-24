<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private $table_name = 'event_fumasw_bet_records';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        SchemaMonitor::create($this->table_name, function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 200)->comment('transferId from request');
            $table->string('round_id', 200)->comment('roundId, ticketId');
            $table->string('username', 30)->comment('玩家帳號');
            $table->string('game_code', 30)->comment('遊戲編號, gameId from request');
            $table->string('type', 30)->comment('類別, 1: bet, 2: settle, 3: free settle');
            $table->tinyInteger('status')->default(0)->comment('僅用來記錄投注單狀態, 派彩單不受影響, 0: 未派彩, 1: 已派彩');
            $table->decimal('bet', 18, 4)->default(0.0000)->comment('下注金額');
            $table->decimal('valid_bet', 18, 4)->default(0.0000)->comment('真實下注金額');
            $table->decimal('win_lose', 18, 4)->default(0.0000)->comment('輸贏金額');

            $table->datetime('bet_time')->comment('下注時間');
            $table->datetime('payout_time')->comment('派彩/取消/bonus時間');
            $table->json('infos')->nullable()->comment('詳細資訊');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));

            $table->unique('bet_id');
            $table->index('round_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        SchemaMonitor::dropIfExists($this->table_name);
    }
};
