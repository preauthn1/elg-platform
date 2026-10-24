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
        // 請使用 SchemaMonitor 操作避免線上環境炸掉
        SchemaMonitor::table('motivationsw_bet_records', function (Blueprint $table) {
            $table->dropUnique('unique_index');
            $table->unique(['bet_id', 'mtcode'], 'unique_index');
            $table->dateTime('bet_time')->nullable()->comment('下注時間')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 請使用 SchemaMonitor 操作避免線上環境炸掉
        SchemaMonitor::table('motivationsw_bet_records', function (Blueprint $table) {
            $table->dropUnique('unique_index');
            $table->unique(['bet_id'], 'unique_index');
            $table->dateTime('bet_time')->comment('下注時間')->change();
        });
    }
};
