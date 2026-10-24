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
        SchemaMonitor::table('event_v2_user_joins', function (Blueprint $table) {
            $table->timestamp('end_time')->nullable()->default(null)->after('status')->comment('結束時間');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('event_v2_user_joins', function (Blueprint $table) {
            $table->dropColumn('end_time');
        });
    }
};
