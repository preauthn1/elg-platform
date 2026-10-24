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
        SchemaMonitor::table('currencies', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('currencies', 'sort')) {
                $table->unsignedInteger('sort')->default(0)->comment('排序')->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('currencies', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('currencies', 'sort')) {
                $table->dropColumn('sort');
            }
        });
    }
};
