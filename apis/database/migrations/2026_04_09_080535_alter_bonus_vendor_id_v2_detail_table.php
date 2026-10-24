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
        SchemaMonitor::table('event_v2_details', function (Blueprint $table) {
            $table->unsignedInteger('bonus_vendor_id')->nullable()->default(null)->after('withdraw_config')->comment('活動序號vendor id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('event_v2_details', function (Blueprint $table) {
            $table->dropColumn('bonus_vendor_id');
        });
    }
};
