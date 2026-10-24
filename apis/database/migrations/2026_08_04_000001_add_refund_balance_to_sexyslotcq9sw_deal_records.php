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
        SchemaMonitor::table('sexyslotcq9sw_deal_records', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('sexyslotcq9sw_deal_records', 'refund_balance')) {
                $table->decimal('refund_balance', 18, 4)->nullable()->comment('退款後錢包餘額（refund resend 回前次餘額用）')->after('is_refund');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('sexyslotcq9sw_deal_records', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('sexyslotcq9sw_deal_records', 'refund_balance')) {
                $table->dropColumn('refund_balance');
            }
        });
    }
};
