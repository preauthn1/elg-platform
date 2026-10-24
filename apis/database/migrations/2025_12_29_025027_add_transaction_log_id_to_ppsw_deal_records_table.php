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
        SchemaMonitor::table('ppsw_deal_records', function (Blueprint $table) {
            $table->unsignedInteger('transaction_log_id')->nullable()->after('status')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('ppsw_deal_records', function (Blueprint $table) {
            $table->dropColumn('transaction_log_id');
        });
    }
};
