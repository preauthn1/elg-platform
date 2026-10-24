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
        SchemaMonitor::table('nex4dsw_deal_records', function (Blueprint $table) {
            $table->string('reference_no', 100)->change();
        });

        SchemaMonitor::table('nex4dsw_bet_records', function (Blueprint $table) {
            $table->string('reference_no', 100)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('nex4dsw_deal_records', function (Blueprint $table) {
            $table->string('reference_no', 50)->change();
        });

        SchemaMonitor::table('nex4dsw_bet_records', function (Blueprint $table) {
            $table->string('reference_no', 50)->change();
        });
    }
};
