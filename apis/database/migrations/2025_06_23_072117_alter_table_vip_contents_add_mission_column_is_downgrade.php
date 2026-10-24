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
        SchemaMonitor::table('vip_contents', function (Blueprint $table) {
            $table->boolean('is_downgrade')->default(true)->comment('是否會被降級')->after('vip_channel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('vip_contents', function (Blueprint $table) {
            $table->dropColumn('is_downgrade');
        });
    }
};
