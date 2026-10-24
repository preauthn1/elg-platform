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
        SchemaMonitor::create('pgs_bet_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('username', 50)->index()->comment('玩家帳號');
            $table->integer('gameID')->default(0)->index()->comment('遊戲代號');
            $table->decimal('beforeAmount', 18, 4)->comment('押注前分數');
            $table->decimal('betAmount', 18, 4)->comment('押注分數');
            $table->decimal('payoutAmount', 18, 4)->comment('派彩分數');
            $table->decimal('winLossAmount', 18, 4)->comment('輸贏分數');
            $table->decimal('validAmount', 18, 4)->comment('有效流水');
            $table->integer('roundID')->comment('進房代號');
            $table->bigInteger('betID')->unique()->comment('注單代號');
            $table->timestamp('betTime')->useCurrent()->index()->comment('押注時間 UTC');
            $table->timestamp('roundStartTime')->useCurrent()->comment('進房時間');
            $table->timestamp('roundEndTime')->useCurrent()->comment('退房時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('pgs_bet_records');
    }
};
