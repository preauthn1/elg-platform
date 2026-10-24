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
        SchemaMonitor::table('agent_vendors', function (Blueprint $table) {
            $table->integer('admin')->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
