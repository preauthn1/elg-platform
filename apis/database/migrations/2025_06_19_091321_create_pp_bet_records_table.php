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
        SchemaMonitor::create('pp_bet_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('bet_id')->default(0)->comment('注单唯一Id, ThemeId');
            $table->string('data_type', 10)->index()->comment('類型; RNG(老虎機), LC(真人), CANCAL(取消單)');
            $table->string('username', 30)->index()->comment('玩家帳號');
            $table->string('game_code', 30)->index()->comment('游戏ID');
            $table->dateTime('bet_time')->index()->comment('投注時間(utc)');
            $table->dateTime('end_time')->index()->comment('結算時間(utc)');
            $table->decimal('bet', 18, 4)->default(0)->comment('下注金额');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏金额');
            $table->tinyInteger('bet_status')->default(1)->index()->comment('注單狀態 0:取消 1:已派彩');
            $table->text('info')->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');

            $table->unique(['bet_id', 'data_type'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('pp_bet_records');
    }
};
