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
        SchemaMonitor::create('joker_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('app_id', 20)->comment('APP編號');
            $table->string('transaction_type', 20)->index()->comment('注單類型; Game or Jackpot');
            $table->string('bet_id', 50)->comment('投注編號唯一值');
            $table->string('game_type', 50)->index()->comment('遊戲類型; wager/endWager/cancelWager/cancelEndWager/appendWagerResult/promopayout/campaign/campaignpayout');
            $table->string('username', 50)->index()->comment('玩家帳號');
            $table->string('game_code', 50)->index()->comment('遊戲代碼');
            $table->decimal('bet_amount', 18, 4)->default(0)->comment('投注金額');
            $table->decimal('win_amount', 18, 4)->default(0)->comment('贏分金額');
            $table->dateTime('bet_time')->index()->comment('投注時間');
            $table->text('info')->comment('response data');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');

            $table->unique(['app_id', 'bet_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('joker_bet_records');
    }
};
