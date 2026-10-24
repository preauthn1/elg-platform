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
        if (Schema::hasColumn('prize_contents', 'coupon_batch_id') === false) {
            SchemaMonitor::table('prize_contents', function (Blueprint $table) {
                $table->integer('coupon_batch_id')->after('prize_id')->default(0)->index()->comment('coupon_batches.id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('prize_contents', 'coupon_batch_id') === true) {
            SchemaMonitor::table('prize_contents', function (Blueprint $table) {
                $table->dropColumn('coupon_batch_id');
            });
        }
    }
};
