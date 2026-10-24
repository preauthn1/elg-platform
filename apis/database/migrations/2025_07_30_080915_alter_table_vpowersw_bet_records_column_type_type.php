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
        SchemaMonitor::table('vpowersw_bet_records', function (Blueprint $table) {
            $table->tinyInteger('status')->change();
            $table->integer('type')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 請使用 SchemaMonitor 操作避免線上環境炸掉
        SchemaMonitor::table('vpowersw_bet_records', function (Blueprint $table) {
            $table->string('status', 1)->change();
            $table->string('type', 1)->change();
        });
    }
};
