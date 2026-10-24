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
        if (Schema::hasColumn('coupon_batches', 'all_downline') === false) {
            SchemaMonitor::table('coupon_batches', function (Blueprint $table) {
                $table->integer('all_downline')->after('agent_id')->default(0)->nullable(false)->comment('是否開啟整條線路');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('coupon_batches', 'all_downline') === true) {
            SchemaMonitor::table('coupon_batches', function (Blueprint $table) {
                $table->dropColumn('all_downline');
            });
        }
    }
};
