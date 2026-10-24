<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 4035 域名管理：domains 補色系（hex 色碼，沿用個性化 #2519 客服後台色系的格式）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('domains', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('domains', 'theme_color')) {
                $table->string('theme_color', 20)->nullable()->after('logo_dm')->comment('色系(hex 色碼)');
            }
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('domains', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('domains', 'theme_color')) {
                $table->dropColumn('theme_color');
            }
        });
    }
};
