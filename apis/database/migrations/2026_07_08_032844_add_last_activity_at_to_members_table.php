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
        SchemaMonitor::table('members', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->comment('最後請求API時間');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('members', function (Blueprint $table) {
            $table->dropColumn('last_activity_at');
        });
    }
};
