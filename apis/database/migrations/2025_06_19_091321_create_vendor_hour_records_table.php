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
        SchemaMonitor::create('vendor_hour_records', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('流水號');
            $table->dateTime('date_hour')->index()->comment('時間');
            $table->integer('member_id')->index()->comment('會員系統編號');
            $table->integer('vendor_id')->index()->comment('營運商編號');
            $table->integer('game_id')->default(0)->index()->comment('遊戲編號');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注額');
            $table->integer('bet_received')->default(0)->comment('己兌換的投注額');
            $table->decimal('win', 18, 4)->default(0)->comment('勝分');
            $table->integer('win_received')->default(0)->comment('己兌換的贏分');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('輸贏');
            $table->integer('win_lose_received')->default(0)->comment('己兌換的輸贏');
            $table->dateTime('last_bet_time')->nullable()->comment('最後投注時間');
            $table->decimal('valid_bet_rate', 4)->nullable()->default(0);
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');

            $table->unique(['date_hour', 'member_id', 'vendor_id', 'game_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vendor_hour_records');
    }
};
