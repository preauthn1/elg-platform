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
        SchemaMonitor::create('igs_daily_wins', function (Blueprint $table) {
            $table->increments('id');
            $table->string('user_id', 50)->index()->comment('玩家id');
            $table->dateTime('date_time')->comment('時間');
            $table->decimal('win_lose', 18, 4)->comment('總輸贏');
            $table->decimal('bet_times', 18, 4)->comment('總投注量');
            $table->decimal('bet', 18, 4)->comment('總下注額');
            $table->dateTime('utc_date_time')->index()->comment('utc時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->unique(['user_id', 'date_time'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('igs_daily_wins');
    }
};
