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
        SchemaMonitor::table('event_v2_details', function (Blueprint $table) {
            $table->boolean('join_withdraw')->after('account_block')->default(false)->comment('是否為參加即領獎');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('event_v2_details', function (Blueprint $table) {
            $table->dropColumn('join_withdraw');
        });
    }
};
