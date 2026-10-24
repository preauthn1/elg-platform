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
        SchemaMonitor::table('agents', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('agents', 'first_login_ip')) {
                $table->bigInteger('first_login_ip')->default(0)->after('last_login_ip')->comment('首次登入IP');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('agents', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('agents', 'first_login_ip')) {
                $table->dropColumn('first_login_ip');
            }
        });
    }
};
