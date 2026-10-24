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
        SchemaMonitor::table('event_v2_user_joins', function (Blueprint $table) {
            $table->decimal('final_balance', 18, 4)->nullable()->default(null)->after('withdraw')->comment('最終餘額');
            $table->string('phone')->nullable()->default(null)->after('ip')->comment('手機')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('event_v2_user_joins', function (Blueprint $table) {
            $table->dropColumn('final_balance');
            $table->dropColumn('phone');
        });
    }
};
