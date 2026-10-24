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
        SchemaMonitor::table('tthsw_deal_records', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('tthsw_deal_records', 'start_balance')) {
                $table->decimal('start_balance', 18, 4)->default(0)->after('amount')->comment('交易前餘額');
            }
            if (!SchemaMonitor::hasColumn('tthsw_deal_records', 'end_balance')) {
                $table->decimal('end_balance', 18, 4)->default(0)->after('start_balance')->comment('交易後餘額');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('tthsw_deal_records', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('tthsw_deal_records', 'start_balance')) {
                $table->dropColumn('start_balance');
            }
            if (SchemaMonitor::hasColumn('tthsw_deal_records', 'end_balance')) {
                $table->dropColumn('end_balance');
            }
        });
    }
};
