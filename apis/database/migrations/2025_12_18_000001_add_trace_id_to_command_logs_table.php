<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('command_logs', function (Blueprint $table) {
            $table->string('trace_id', 50)->nullable()->after('cronjob')->index()->comment('追蹤碼，用於追蹤排程任務的所有日誌');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('command_logs', function (Blueprint $table) {
            $table->dropColumn('trace_id');
        });
    }
};
