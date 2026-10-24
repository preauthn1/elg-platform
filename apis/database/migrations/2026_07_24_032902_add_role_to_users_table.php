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
        SchemaMonitor::table('users', function (Blueprint $table) {
            $table->tinyInteger('role')->default(1)->after('status')->comment('角色 1:admin,2:superadmin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
