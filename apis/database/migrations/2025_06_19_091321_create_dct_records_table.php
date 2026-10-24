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
        SchemaMonitor::create('dct_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('round_id', 50)->default('')->index()->comment('注单唯一Id, ThemeId');
            $table->string('wager_id', 50)->default('')->index()->comment('注单唯一Id, ThemeId');
            $table->string('username', 30)->index()->comment('玩家帳號');
            $table->string('game_code', 20)->index()->comment('游戏ID');
            $table->string('wager_type', 30)->index()->comment('注單類型');
            $table->decimal('amount', 18, 4)->default(0)->comment('金额');
            $table->dateTime('create_time')->index()->comment('注單時間(utc)');
            $table->text('info')->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');

            $table->unique(['round_id', 'wager_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('dct_records');
    }
};
