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
            if (!SchemaMonitor::hasColumn('members', 'first_deposit_point')) {
                $table->decimal('first_deposit_point', 18, 4)->nullable()->default(null)
                    ->after('first_deposit')
                    ->comment('首存金額(point，換算後實際入帳點數，非原幣別amount)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('members', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('members', 'first_deposit_point')) {
                $table->dropColumn('first_deposit_point');
            }
        });
    }
};
