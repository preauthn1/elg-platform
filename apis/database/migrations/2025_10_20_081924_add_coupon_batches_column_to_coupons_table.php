<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('coupon_batches', 'flow_limit_multiple') === false) {
            SchemaMonitor::table('coupon_batches', function (Blueprint $table) {
                $table->integer('flow_limit_multiple')->after('amount')->default(0)->nullable(false)->comment('稽核流水倍數');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('coupon_batches', 'flow_limit_multiple') === true) {
            SchemaMonitor::table('coupon_batches', function (Blueprint $table) {
                $table->dropColumn('flow_limit_multiple');
            });
        }
    }
};
