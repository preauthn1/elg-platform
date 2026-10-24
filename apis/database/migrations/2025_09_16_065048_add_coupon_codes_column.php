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
        if (Schema::hasColumn('coupon_codes', 'mobile') === false) {
            SchemaMonitor::table('coupon_codes', function (Blueprint $table) {
                $table->string('mobile', 30)->nullable()->after('member_id')->comment('手機號碼');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('coupon_codes', 'mobile') === true) {
            SchemaMonitor::table('coupon_codes', function (Blueprint $table) {
                $table->dropColumn('mobile');
            });
        }
    }
};
