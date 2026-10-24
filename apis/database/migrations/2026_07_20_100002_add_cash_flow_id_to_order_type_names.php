<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * order_type_names denormalize cash_flow_id（來源 order_types.cash_flow_id，經 type_id）。
     * 讓「以 cash_flows.id 為根查改多語系顯示名」一步到位。
     * hasColumn guard → 六站冪等重跑。
     */
    public function up(): void
    {
        SchemaMonitor::table('order_type_names', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('order_type_names', 'cash_flow_id')) {
                $table->integer('cash_flow_id')->default(0)->after('type_id')
                    ->index('order_type_names_cash_flow_id_index')
                    ->comment('金流商編號（denormalize order_types.cash_flow_id）');
            }
        });

        // 生效斷言：SchemaMonitor 生產列數保護是「靜默跳過仍記為已執行」，
        // 若某站表列數超門檻，up() 會 no-op 卻被記為已跑、之後不重試。
        // 把失敗前移到 migrate 當下 fail loud，而非拖到 backfill 才發現。
        if (!SchemaMonitor::hasColumn('order_type_names', 'cash_flow_id')) {
            throw new RuntimeException(
                'order_type_names cash_flow_id 未生效：SchemaMonitor 可能因生產列數保護靜默跳過，'
                . '請確認 order_type_names 列數在門檻內後重跑 migration'
            );
        }
    }

    public function down(): void
    {
        SchemaMonitor::table('order_type_names', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('order_type_names', 'cash_flow_id')) {
                $table->dropColumn('cash_flow_id');
            }
        });
    }
};
