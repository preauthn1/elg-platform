<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * bet_record_recovery_logs.bet_id 原為 string(50)，但各家注單表的 bet_id 最長到 255
 * （Ace333SW 實際 58 字元，2026-09-05 TPower 事故補帳時因 1406 Data too long 寫不進審計表）。
 * 對齊注單表最寬的定義，補帳／退款才能跨 vendor 留審計。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::table('bet_record_recovery_logs', function (Blueprint $table) {
            $table->string('bet_id', 255)->comment('注單 BetId（冗余便於跨 vendor 追查）')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('bet_record_recovery_logs', function (Blueprint $table) {
            $table->string('bet_id', 50)->comment('注單 BetId（冗余便於跨 vendor 追查）')->change();
        });
    }
};
