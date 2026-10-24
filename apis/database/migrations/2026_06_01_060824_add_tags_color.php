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
        SchemaMonitor::table('tags', function (Blueprint $table) {
            $table->string('text_color')->nullable()->default(null)->after('name')->comment('文字顏色');
            $table->string('background_color')->nullable()->default(null)->after('text_color')->comment('背景顏色');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('tags', function (Blueprint $table) {
            $table->dropColumn('text_color');
            $table->dropColumn('background_color');
        });
    }
};
