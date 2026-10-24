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
        SchemaMonitor::table('summary_profit_shares', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('summary_profit_shares', 'cap_amount')) {
                $table->decimal('cap_amount', 18, 4)->default(-1)->after('wallet_balance')->comment('佔成錢包封頂額度 -1=不限');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('summary_profit_shares', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('summary_profit_shares', 'cap_amount')) {
                $table->dropColumn('cap_amount');
            }
        });
    }
};
