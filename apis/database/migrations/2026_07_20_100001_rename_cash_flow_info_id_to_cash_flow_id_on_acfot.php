<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    private const string INDEX_NAME = 'acfot_cash_flow_id_index';

    /**
     * ACFOT 的 cash_flow_info_id 為 dead 欄位（setup 寫死 0、runtime 零讀）。
     * repurpose 為 cash_flow_id（denormalize agent_cash_flows.cash_flow_id），
     * 讓「以 cash_flows.id 為根查 ACFOT 上下架」一步到位。
     *
     * rename 與加 index 各自獨立 guard（各自冪等）：
     * 若在 rename 成功、加 index 前中斷重跑，仍會補上遺漏的 index，
     * 不會因為 cash_flow_info_id 已消失而整段短路。
     */
    public function up(): void
    {
        if (SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'cash_flow_info_id')) {
            SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
                $table->renameColumn('cash_flow_info_id', 'cash_flow_id');
            });
        }

        if (SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'cash_flow_id')
            && !SchemaMonitor::hasIndex('agent_cash_flow_order_types', self::INDEX_NAME)) {
            SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
                $table->index('cash_flow_id', self::INDEX_NAME);
            });
        }

        // 生效斷言：SchemaMonitor 生產列數保護是「靜默跳過仍記為已執行」，
        // 若某站表列數超門檻，up() 會 no-op 卻被記為已跑、之後不重試。
        // 這裡把「migrate 是否真生效」的失敗前移到 migrate 當下 fail loud，
        // 而非拖到 backfill／查改命令炸 SQL error 才發現。
        if (!SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'cash_flow_id')) {
            throw new RuntimeException(
                'ACFOT cash_flow_id 未生效：SchemaMonitor 可能因生產列數保護靜默跳過，'
                . '請確認 agent_cash_flow_order_types 列數在門檻內後重跑 migration'
            );
        }
    }

    public function down(): void
    {
        if (SchemaMonitor::hasIndex('agent_cash_flow_order_types', self::INDEX_NAME)) {
            SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
                $table->dropIndex(self::INDEX_NAME);
            });
        }

        if (SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'cash_flow_id')
            && !SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'cash_flow_info_id')) {
            SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
                $table->renameColumn('cash_flow_id', 'cash_flow_info_id');
            });
        }
    }
};
