<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 4035 域名管理：domains 補名稱與 LOGO（淺色 / 深色模式）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('domains', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('domains', 'domain_name')) {
                $table->string('domain_name', 191)->nullable()->after('domain')->comment('域名名稱');
            }
            if (!SchemaMonitor::hasColumn('domains', 'logo_lm')) {
                $table->string('logo_lm', 500)->nullable()->after('domain_name')->comment('LOGO 淺色模式');
            }
            if (!SchemaMonitor::hasColumn('domains', 'logo_dm')) {
                $table->string('logo_dm', 500)->nullable()->after('logo_lm')->comment('LOGO 深色模式');
            }
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('domains', function (Blueprint $table) {
            foreach (['logo_dm', 'logo_lm', 'domain_name'] as $column) {
                if (SchemaMonitor::hasColumn('domains', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
