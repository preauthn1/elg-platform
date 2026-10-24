<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('orders', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('orders', 'tax')) {
                $table->decimal('tax', 11, 5)->default(0)->after('rate')->comment('手續費');
            }
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('orders', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('orders', 'tax')) {
                $table->dropColumn('tax');
            }
        });
    }
};
