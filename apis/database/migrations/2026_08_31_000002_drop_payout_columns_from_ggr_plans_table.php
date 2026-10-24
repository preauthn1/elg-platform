<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 移除 GGR 方案的審核/自動發放/有效天數欄位 工單4121
 *
 * 2026-08-31 使用者定案：推廣代理 GGR 不做審核概念與自動發放，
 * 發放全走原本流程、無過期作廢——auto_approve / auto_payout / valid_days
 * 三欄連同 4121 表單的「發放方式」卡片一併移除。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('ggr_plans', function (Blueprint $table) {
            $table->dropColumn(['auto_approve', 'auto_payout', 'valid_days']);
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('ggr_plans', function (Blueprint $table) {
            $table->tinyInteger('auto_approve')->default(0)->after('carry_negative')->comment('自動審核通過 0:否 1:是');
            $table->tinyInteger('auto_payout')->default(0)->after('auto_approve')->comment('自動發放 0:否 1:是');
            $table->integer('valid_days')->nullable()->after('auto_payout')->comment('有效天數(NULL=不過期)');
        });
    }
};
