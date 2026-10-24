<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('event_v2_details', function (Blueprint $table) {
            $table->string('redirect_path')->nullable()->default(null)->after('bonus_vendor_id')->comment('參加活動後導向的路徑');
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('event_v2_details', function (Blueprint $table) {
            $table->dropColumn('redirect_path');
        });
    }
};
