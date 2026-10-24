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
        SchemaMonitor::table('vendor_customized_items', function (Blueprint $table) {
            $table->integer('sorts')->default(0)->comment('排序權重')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
