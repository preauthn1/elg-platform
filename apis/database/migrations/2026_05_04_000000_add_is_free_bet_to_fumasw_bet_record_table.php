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
        SchemaMonitor::table('fumasw_bet_records', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('fumasw_bet_records', 'is_free_bet')) {
                $table->tinyInteger('is_free_bet')->default(0)->after('payout_time')->comment('是否為freegame 0: 否 1: 是');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('fumasw_bet_records', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('fumasw_bet_records', 'is_free_bet')) {
                $table->dropColumn('is_free_bet');
            }
        });
    }
};
