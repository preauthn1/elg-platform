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
        SchemaMonitor::table('vendors', function (Blueprint $table) {
            $table->boolean('is_event')->default(false)->comment('是否顯示活動遊戲商標籤0:不顯示,1:顯示')->after('is_hot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('vendors', function (Blueprint $table) {
            $table->dropColumn('is_event');
        });
    }
};
