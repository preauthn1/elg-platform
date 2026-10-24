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
        SchemaMonitor::create('xe88_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('username', 20)->nullable()->index()->comment('玩家帳號');
            $table->integer('game_id')->nullable()->index()->comment('遊戲ID');
            $table->string('game_name', 191)->nullable()->comment('遊戲名稱');
            $table->decimal('bet', 13, 7)->comment('投注金額');
            $table->decimal('win', 13, 7)->comment('贏得金額');
            $table->decimal('begin_money', 13, 7)->index()->comment('下注前餘額');
            $table->decimal('end_money', 13, 7)->comment('下注後餘額');
            $table->text('game_log')->nullable()->comment('遊戲記錄');
            $table->string('log_time', 191)->nullable()->index()->comment('原始投注時間');
            $table->dateTime('utc_log_time')->nullable()->index()->comment('UTC投注時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('xe88_bet_records');
    }
};
