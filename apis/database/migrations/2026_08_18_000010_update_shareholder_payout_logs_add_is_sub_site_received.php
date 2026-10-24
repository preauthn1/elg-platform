<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shareholder_payout_logs', 'is_sub_site_received')) {
            return;
        }

        Schema::table('shareholder_payout_logs', function (Blueprint $table) {
            $table->tinyInteger('is_sub_site_received')->default(0)->comment('0:未接收 1:已接收（子站是否已收到主站推送）')->after('status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('shareholder_payout_logs', 'is_sub_site_received')) {
            return;
        }

        Schema::table('shareholder_payout_logs', function (Blueprint $table) {
            $table->dropColumn('is_sub_site_received');
        });
    }
};
