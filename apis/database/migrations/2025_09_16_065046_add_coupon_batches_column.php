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
        if (Schema::hasColumn('coupon_batches', 'mobile_daily_limit') === false) {
            SchemaMonitor::table('coupon_batches', function (Blueprint $table) {
                $table->integer('mobile_daily_limit')->after('receive_limit')->default(0)->comment('手機號每日可領取次數 0:無限制');
            });
        }

        if (Schema::hasColumn('coupon_batches', 'realname_required') === false) {
            SchemaMonitor::table('coupon_batches', function (Blueprint $table) {
                $table->boolean('realname_required')->after('mobile_daily_limit')->default(0)->comment('是否需要實名認證');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('coupon_batches', 'mobile_daily_limit') === true) {
            SchemaMonitor::table('coupon_batches', function (Blueprint $table) {
                $table->dropColumn('mobile_daily_limit');
            });
        }

        if (Schema::hasColumn('coupon_batches', 'realname_required') === true) {
            SchemaMonitor::table('coupon_batches', function (Blueprint $table) {
                $table->dropColumn('realname_required');
            });
        }
    }
};
