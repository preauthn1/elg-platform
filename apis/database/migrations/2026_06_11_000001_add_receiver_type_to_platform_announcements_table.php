<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('platform_announcements', function (Blueprint $table) {
            $table->integer('receiver_type')->default(1)->comment('公告類別;1:會員前台2:代理後台')->after('type');
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('platform_announcements', function (Blueprint $table) {
            $table->dropColumn('receiver_type');
        });
    }
};
