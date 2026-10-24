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
        SchemaMonitor::create('pussy888_daily_wins', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->date('date')->index()->comment('時間');
            $table->string('player_id', 191)->index()->comment('pussy888玩家帳號');
            $table->decimal('win', 18, 4)->comment('派彩金額');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('pussy888_daily_wins');
    }
};
