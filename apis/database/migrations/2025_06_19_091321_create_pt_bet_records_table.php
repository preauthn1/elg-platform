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
        SchemaMonitor::create('pt_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('player_name', 191)->index()->comment('玩家帳號');
            $table->string('window_code', 191)->comment('窗口號');
            $table->string('game_id', 191)->comment('窗口號');
            $table->string('game_code', 191)->index()->comment('遊戲局號');
            $table->string('game_type', 191)->comment('遊戲類型');
            $table->string('game_name', 191)->comment('遊戲名稱');
            $table->string('session_id', 191)->comment('期號');
            $table->string('currency_code', 191)->comment('幣別');
            $table->decimal('bet', 13, 7)->nullable()->comment('投注額');
            $table->decimal('win', 13, 7)->nullable()->comment('贏得金額');
            $table->decimal('progress_ive_bet', 13, 7)->nullable()->comment('獎池投注額');
            $table->decimal('progress_ive_win', 13, 7)->nullable()->comment('獎池贏得金額');
            $table->decimal('balance', 13, 7)->nullable()->comment('餘額');
            $table->decimal('current_bet', 13, 7)->nullable()->comment('當局投注');
            $table->dateTime('game_date')->comment('投注時間(時區+8)');
            $table->dateTime('utc_game_date')->index()->comment('投注時間(UTC+0)');
            $table->string('info', 191)->comment('詳細信息');
            $table->string('live_network', 191)->nullable()->comment('現場分類');
            $table->string('rnum', 191)->comment('類型');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->string('game_name_modified', 30)->nullable()->virtualAs('replace(substring_index(`game_name`,_utf8mb4\'(\',-(1)),_utf8mb4\')\',_utf8mb4\'\')')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('pt_bet_records');
    }
};
