<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('agent_cash_flows', 'tax')) {
                $table->decimal('tax', 11, 5)->default(0)->after('rate')->comment('手續費');
            }

            if (!SchemaMonitor::hasColumn('agent_cash_flows', 'tax_percentage')) {
                $table->decimal('tax_percentage', 11, 5)->default(0)->after('tax')->comment('手續費(百分比)');
            }
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('agent_cash_flows', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('agent_cash_flows', 'tax_percentage')) {
                $table->dropColumn('tax_percentage');
            }

            if (SchemaMonitor::hasColumn('agent_cash_flows', 'tax')) {
                $table->dropColumn('tax');
            }
        });
    }
};
