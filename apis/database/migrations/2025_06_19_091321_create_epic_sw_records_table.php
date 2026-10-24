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
        SchemaMonitor::create('epic_sw_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('operator_id', 20)->default('')->comment('OperatorId');
            $table->string('type', 20)->default('')->index()->comment('注单類型');
            $table->bigInteger('bet_id')->default(0)->index()->comment('bet_id / tran_id');
            $table->string('username', 50)->default('')->index()->comment('PlayerId');
            $table->string('game_code', 50)->default('')->index()->comment('GameCode');
            $table->bigInteger('round_id')->default(0)->index()->comment('RoundId');
            $table->string('bonus_id', 50)->default('')->comment('BonusId / ');
            $table->decimal('bet_amount', 18, 4)->default(0)->comment('BetAmount');
            $table->decimal('rolling_chip_amount', 18, 4)->default(0)->comment('禮碼');
            $table->decimal('valid_bet_amount', 18, 4)->nullable()->comment('ValidBetAmount');
            $table->decimal('payout_amount', 18, 4)->nullable()->comment('Payout');
            $table->decimal('win_lose_amount', 18, 4)->nullable()->comment('WinLose');
            $table->dateTime('tran_date_time')->index();
            $table->string('session_token', 500)->index()->comment('AuthToken');
            $table->bigInteger('result_id')->nullable()->comment('ResultId');
            $table->tinyInteger('result_type')->default(0)->comment('狀態 0:Single/Quick Game Round Result,1:Single/Quick Game Refund');
            $table->string('result', 100)->comment('Result');
            $table->tinyInteger('status')->default(0)->index()->comment('狀態 0:已處理,1:未處理');
            $table->string('currency', 5)->default('')->comment('currency');
            $table->decimal('exchange_rate', 6, 4)->default(0)->comment('ExchangeRate');
            $table->text('info')->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');

            $table->unique(['type', 'bet_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('epic_sw_records');
    }
};
