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
            $table->boolean('is_recycle')->default(true)->after('sort')->comment('是否回收');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('event_v2_details', function (Blueprint $table) {
            $table->dropColumn('is_recycle');
        });
    }
};
