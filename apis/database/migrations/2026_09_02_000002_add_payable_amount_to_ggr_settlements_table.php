<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 4121 發揚（負數結轉）沖抵後的實發金額（2026-09-01 工單定案）。
 *
 * final_amount 是本期公式實算值（可為負）；payable_amount 是沖抵遞延餘額後
 * 本期實際可發放的金額（恆 >= 0）。批次七發放排程直接讀本欄入帳，
 * 不需重推導沖抵邏輯。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('ggr_settlements', function (Blueprint $table) {
            $table->decimal('payable_amount', 18, 2)->default(0)->after('final_amount')
                ->comment('发扬沖抵後實發金額(>=0,批次七發放依據)');
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('ggr_settlements', function (Blueprint $table) {
            $table->dropColumn('payable_amount');
        });
    }
};
