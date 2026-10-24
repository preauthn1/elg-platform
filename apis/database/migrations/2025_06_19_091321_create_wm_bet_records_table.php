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
        SchemaMonitor::create('wm_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('round_id', 191)->index()->comment('注單號');
            $table->string('game_identity', 191)->index()->comment('遊戲識別碼');
            $table->string('currency', 10)->comment('幣別');
            $table->string('wallet_type', 191)->comment('區段/錢包類型');
            $table->string('external_reference', 191)->nullable()->comment('外部編號');
            $table->string('external_user_id', 191)->nullable()->index()->comment('外部用戶識別');
            $table->string('user_token', 191)->nullable()->comment('會員授權碼');
            $table->string('session_token', 191)->nullable()->comment('區段授權碼');
            $table->string('start_time', 191)->nullable()->comment('注單下注時間');
            $table->string('end_time', 191)->nullable()->comment('注單結算時間');
            $table->dateTime('utc_start_time')->nullable()->index()->comment('注單下注時間(UTC+0)');
            $table->dateTime('utc_end_time')->nullable()->index()->comment('注單結算時間(UTC+0)');
            $table->decimal('initial_balance', 18, 4)->default(0)->comment('錢包起始餘額');
            $table->decimal('finial_balance', 18, 4)->nullable()->default(0)->comment('錢包最後餘額');
            $table->decimal('bet_amount', 18, 4)->default(0)->comment('總投注額');
            $table->decimal('win_amount', 18, 4)->default(0)->comment('總贏分');
            $table->decimal('jack_pot_bet', 18, 4)->default(0)->comment('彩池投注額');
            $table->decimal('jack_pot_win', 18, 4)->default(0)->comment('彩池總贏分');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('wm_bet_records');
    }
};
