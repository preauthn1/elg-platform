<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * GGR 遊戲商設置的「無限制」表示法由 NULL 改為 0 工單4123
 *
 * 2026-08-31 工單重寫版定案：`0` 是特殊值＝不限制（勾「無最低限制／無上限」
 * 就是自動填 0，參考站 tooltip「輸入 0＝無最小值」原文正確）。原本用 NULL
 * 表示是批次一的設計，與工單定案不一致，統一改為 0、欄位改 NOT NULL。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ggr_vendor_setting_details')->whereNull('min_winlose')->update(['min_winlose' => 0]);
        DB::table('ggr_vendor_setting_details')->whereNull('max_ggr')->update(['max_ggr' => 0]);

        SchemaMonitor::table('ggr_vendor_setting_details', function (Blueprint $table) {
            $table->unsignedBigInteger('min_winlose')->default(0)->comment('最低遊戲輸贏(加權後門檻,0=不限制)')->change();
            $table->unsignedBigInteger('max_ggr')->default(0)->comment('最大GGR(可計算加權上限,0=無上限)')->change();
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('ggr_vendor_setting_details', function (Blueprint $table) {
            $table->unsignedBigInteger('min_winlose')->nullable()->default(null)->comment('最低遊戲輸贏(正整數,列入計算下限,NULL=無最低限制)')->change();
            $table->unsignedBigInteger('max_ggr')->nullable()->default(null)->comment('最大GGR(正整數,可計算加權上限,NULL=無上限)')->change();
        });

        DB::table('ggr_vendor_setting_details')->where('min_winlose', 0)->update(['min_winlose' => null]);
        DB::table('ggr_vendor_setting_details')->where('max_ggr', 0)->update(['max_ggr' => null]);
    }
};
