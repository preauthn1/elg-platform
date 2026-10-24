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
        SchemaMonitor::create('vpower_bet_records', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('系統編號');
            $table->string('uniqleid', 50)->index()->comment('注單編號(唯一值)');
            $table->string('uid', 30);
            $table->string('gameid', 30)->index()->comment('遊戲ID');
            $table->string('tableid', 20)->comment('桌子ID');
            $table->string('username', 20)->index()->comment('玩家名稱');
            $table->decimal('pay', 18, 4)->comment('下注金額');
            $table->decimal('win', 18, 4)->nullable()->comment('派彩金額');
            $table->decimal('startscore', 18, 4)->comment('遊戲開始金額');
            $table->decimal('finalscore', 18, 4)->comment('遊戲結束金額');
            $table->string('time', 10)->comment('時間');
            $table->dateTime('utc_time')->index()->comment('時間(UTC)');
            $table->string('origin_utc_time', 20)->nullable();
            $table->text('info')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent()->index();

            $table->index(['utc_time', 'username']);
            $table->unique(['time', 'uid', 'uniqleid', 'startscore', 'finalscore'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vpower_bet_records');
    }
};
