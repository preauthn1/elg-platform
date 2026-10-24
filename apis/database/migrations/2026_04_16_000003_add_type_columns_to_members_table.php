<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('members', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('members', 'type')) {
                $table->tinyInteger('type')->default(0)->after('status')->comment('會員類型; 0:一般會員, 1:份桶會員, 2:佔成會員');
            }

            if (!SchemaMonitor::hasColumn('members', 'previous_day_type')) {
                $table->tinyInteger('previous_day_type')->default(0)->after('type')->comment('前一天會員類型; 0:一般會員, 1:份桶會員, 2:佔成會員');
            }
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('members', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('members', 'previous_day_type')) {
                $table->dropColumn('previous_day_type');
            }

            if (SchemaMonitor::hasColumn('members', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
