<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 4035 域名管理：domains 補網站 icon（favicon）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('domains', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('domains', 'icon')) {
                $table->string('icon', 500)->nullable()->after('logo_dm')->comment('網站 icon(favicon)');
            }
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('domains', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('domains', 'icon')) {
                $table->dropColumn('icon');
            }
        });
    }
};
