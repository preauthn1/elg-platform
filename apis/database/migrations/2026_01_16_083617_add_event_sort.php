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
        SchemaMonitor::table('events', function (Blueprint $table) {
            $table->integer('sort')->default(0)->after('end_time')->comment('排序');
        });

        SchemaMonitor::table('event_v2_details', function (Blueprint $table) {
            $table->integer('sort')->default(0)->after('end_time')->comment('排序');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 請使用 SchemaMonitor 操作避免線上環境炸掉
        SchemaMonitor::table('events', function (Blueprint $table) {
            $table->dropColumn('sort');
        });

        SchemaMonitor::table('event_v2_details', function (Blueprint $table) {
            $table->dropColumn('sort');
        });
    }
};
