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
        SchemaMonitor::create('pgssw_event_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('bet_id', 191)->default('0')->unique()->comment('注单唯一Id, ThemeId');
            $table->string('game_code', 45)->default('reward')->comment('用來顯示報表遊戲類別');
            $table->string('username', 30)->comment('玩家帳號');
            $table->dateTime('pay_time')->comment('派獎時間(utc)');
            $table->decimal('prize_amount', 18, 4)->default(0)->comment('獎勵金額');
            $table->text('info')->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('pgssw_event_records');
    }
};
