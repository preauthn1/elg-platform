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
            $table->tinyInteger('is_event')->nullable()->comment('活動遊戲標記')->after('is_hot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('vendor_customized_items', function (Blueprint $table) {
            $table->dropColumn('is_event');
        });
    }
};
