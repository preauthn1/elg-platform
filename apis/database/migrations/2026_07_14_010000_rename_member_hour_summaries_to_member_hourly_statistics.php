<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::rename('member_hour_summaries', 'member_hourly_statistics');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::rename('member_hourly_statistics', 'member_hour_summaries');
    }
};
