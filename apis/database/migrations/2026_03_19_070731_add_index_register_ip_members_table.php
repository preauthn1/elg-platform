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
            $table->index('register_ip');
        }, 1000000);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('members', function (Blueprint $table) {
            $table->dropIndex('members_register_ip_index');
        }, 1000000);
    }
};
