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
        SchemaMonitor::create('scr_bet_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('class_id')->nullable()->comment('未知');
            $table->string('account', 20)->index()->comment('918玩家帳號');
            $table->dateTime('bet_time')->nullable()->index()->comment('投注時間');
            $table->dateTime('bet_time_utc')->nullable()->index()->comment('投注時間utc');
            $table->decimal('begin_balance', 10)->nullable()->comment('投注前餘額');
            $table->decimal('end_balance', 10)->nullable()->comment('結算後餘額');
            $table->integer('game_id')->nullable()->index()->comment('遊戲ID');
            $table->string('game_name', 50)->nullable()->comment('遊戲名稱');
            $table->integer('line_num')->nullable()->comment('未知');
            $table->string('log_data_str', 191)->nullable()->comment('遊戲記錄');
            $table->integer('log_data_type')->nullable()->comment('1:设置分数, 2:游戏记录, 3:未完成的游戏, 4:JackPot, 5:Double, 6:Game bonus, 8:红包, 13:未完成的纸牌游戏');
            $table->integer('round_no')->nullable()->comment('未知');
            $table->integer('row_num')->nullable()->comment('未知');
            $table->integer('table_id')->nullable()->comment('桌號');
            $table->decimal('win', 10)->nullable()->comment('輸贏金額');
            $table->decimal('bet', 10)->nullable()->comment('投注金額');
            $table->integer('cday')->nullable()->comment('未知');
            $table->integer('cno')->nullable()->comment('未知');
            $table->string('uuid', 50)->nullable()->comment('未知');
            $table->string('info', 500)->nullable()->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');

            $table->unique(['account', 'uuid'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('scr_bet_records');
    }
};
