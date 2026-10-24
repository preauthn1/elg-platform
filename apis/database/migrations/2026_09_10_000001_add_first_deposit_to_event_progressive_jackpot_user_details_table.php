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
        SchemaMonitor::table('event_progressive_jackpot_user_details', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('event_progressive_jackpot_user_details', 'first_deposit')) {
                $table->decimal('first_deposit', 18, 4)->default(0)->after('member_id')->comment('首儲金額');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('event_progressive_jackpot_user_details', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('event_progressive_jackpot_user_details', 'first_deposit')) {
                $table->dropColumn('first_deposit');
            }
        });
    }
};
