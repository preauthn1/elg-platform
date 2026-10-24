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
        SchemaMonitor::table('vendors', function (Blueprint $table) {
            $table->boolean('is_free')->default(false)->after('info')->comment('是否為免盤費商戶');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('vendors', function (Blueprint $table) {
            $table->dropColumn('is_free');
        });
    }
};
