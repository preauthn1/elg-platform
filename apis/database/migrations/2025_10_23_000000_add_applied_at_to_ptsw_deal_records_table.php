<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class AddAppliedAtToPtswDealRecordsTable extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('ptsw_deal_records', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('ptsw_deal_records', 'applied_at')) {
                $table->timestamp('applied_at')->nullable()->after('external_transaction_date');
            }
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('ptsw_deal_records', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('ptsw_deal_records', 'applied_at')) {
                $table->dropColumn('applied_at');
            }
        });
    }
}
