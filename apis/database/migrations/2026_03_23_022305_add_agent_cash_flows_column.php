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
            $table->tinyInteger('ip_change_email_verify')->default(0)->after('has_2fa')->comment('登入IP變更email驗證開關');
            $table->tinyInteger('ip_change_mobile_verify')->default(0)->after('has_2fa')->comment('登入IP變更手機驗證開關');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('members', function (Blueprint $table) {
            $table->dropColumn('ip_change_email_verify');
            $table->dropColumn('ip_change_mobile_verify');
        });
    }
};
