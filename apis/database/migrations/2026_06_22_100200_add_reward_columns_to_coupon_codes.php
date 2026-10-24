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
        if (Schema::hasColumn('coupon_codes', 'coupon_batch_prize_id') === false) {
            SchemaMonitor::table('coupon_codes', function (Blueprint $table) {
                $table->integer('coupon_batch_prize_id')->nullable()->after('mobile')->comment('獎項ID; 對應 coupon_batch_prizes.id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('coupon_codes', 'coupon_batch_prize_id') === true) {
            SchemaMonitor::table('coupon_codes', function (Blueprint $table) {
                $table->dropColumn('coupon_batch_prize_id');
            });
        }
    }
};
