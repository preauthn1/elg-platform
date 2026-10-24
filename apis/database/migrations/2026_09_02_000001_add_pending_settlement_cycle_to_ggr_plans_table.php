<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 4121 結算週期切換「只對下一期生效」的載體（2026-09-01 工單定案）。
 *
 * 週期變更時設定端只寫 pending_settlement_cycle＋pending_cycle_from
 * （＝切換當下當期的下一期起點，GMT+8 業務日）、不動 settlement_cycle；
 * 引擎結算推進到 pending_cycle_from 時把 pending 落地為 settlement_cycle
 * 並清空（promote）。當期因此照原週期跑完，歷史不重算、區間無重疊無漏算。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::table('ggr_plans', function (Blueprint $table) {
            $table->tinyInteger('pending_settlement_cycle')->nullable()->after('settlement_cycle')
                ->comment('待生效結算週期:NULL無變更 1:每日 2:每週 3:每月');
            $table->date('pending_cycle_from')->nullable()->after('pending_settlement_cycle')
                ->comment('新週期生效日(切換當下當期的下一期起點,GMT+8業務日)');
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('ggr_plans', function (Blueprint $table) {
            $table->dropColumn(['pending_settlement_cycle', 'pending_cycle_from']);
        });
    }
};
