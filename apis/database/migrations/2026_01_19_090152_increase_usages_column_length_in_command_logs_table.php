<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 增加 usages 欄位長度，從 decimal(8,5) 改為 decimal(10,2)
     * 原本最大值：999.99999
     * 修改後最大值：99999999.99
     */
    public function up(): void
    {
        SchemaMonitor::table('command_logs', function (Blueprint $table) {
            $table->decimal('usages', 10, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * 還原為原始的 decimal(8,5)
     */
    public function down(): void
    {
        SchemaMonitor::table('command_logs', function (Blueprint $table) {
            $table->decimal('usages', 8, 5)->nullable()->change();
        });
    }
};
