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
        SchemaMonitor::table('event_invite_settings', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('event_invite_settings', 'sort')) {
                $table->integer('sort')->default(0)->after('realname_block')->comment('前端排序');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('event_invite_settings', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('event_invite_settings', 'sort')) {
                $table->dropColumn('sort');
            }
        });
    }
};
