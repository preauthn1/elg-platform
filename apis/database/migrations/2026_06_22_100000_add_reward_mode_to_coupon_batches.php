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
        if (Schema::hasColumn('coupon_batches', 'reward_mode') === false) {
            SchemaMonitor::table('coupon_batches', function (Blueprint $table) {
                $table->tinyInteger('reward_mode')->after('type')->default(1)->comment('獎勵模式; 1:固定金額, 2:隨機獎勵');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('coupon_batches', 'reward_mode') === true) {
            SchemaMonitor::table('coupon_batches', function (Blueprint $table) {
                $table->dropColumn('reward_mode');
            });
        }
    }
};
