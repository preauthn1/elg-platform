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
        SchemaMonitor::table('vendor_info_games', function (Blueprint $table) {
            $table->tinyInteger('bettable')->default(1)->after('status')->comment('遊戲是否可投注(0:否,1:是)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('vendor_info_games', function (Blueprint $table) {
            $table->dropColumn('bettable');
        });
    }
};
