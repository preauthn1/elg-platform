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
        if (Schema::hasColumn('coupon_batches', 'vendor_lock') === false) {
            SchemaMonitor::table('coupon_batches', function (Blueprint $table) {
                $table->integer('vendor_lock')->after('realname_required')->default(1)->comment('是否鎖定遊戲商入口');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('coupon_batches', 'vendor_lock') === true) {
            SchemaMonitor::table('coupon_batches', function (Blueprint $table) {
                $table->dropColumn('vendor_lock');
            });
        }
    }
};
