<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * agent_operate_logs.reference_id 早就是事實上的多型欄位：
     * 依 type 不同分別指向 agents.id、members.id、orders.id、withdrawal_orders.id、
     * 簡訊發送 id，甚至 username 字串（舊 login_failed），但沒有任何型別標記。
     * 讀取端因此只能無條件對 agents 與 members 各 leftJoin 一次，
     * 造成 order id 誤撈出同 id 的代理／會員名稱。reference_type 補上這個型別標記。
     *
     * 預設 '' 代表「型別未知」：舊資料不回填，且 cs_user_login／cs_user_login_fail
     * 由本 repo 之外的系統寫入、短期內不會帶型別，讀取端必須永遠保留 '' 的相容分支。
     *
     * hasColumn guard → 六站冪等重跑。
     */
    public function up(): void
    {
        SchemaMonitor::table('agent_operate_logs', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('agent_operate_logs', 'reference_type')) {
                $table->string('reference_type', 30)->default('')->after('reference_id')
                    ->comment('reference_id 指向的實體別名（agent/member/order...），空字串為舊資料或未標型別');
            }
        });

        // 生效斷言（backstop）：agent_operate_logs 已列入 config/custom.php 的
        // schema_monitor.row_count_exempt_tables，正常情況不會被生產列數保護攔下。
        // 但那道保護是「靜默跳過仍記為已執行」，一旦白名單在後續合併中被移除（或 config
        // 快取沒更新），欄位沒建成卻放行，所有寫入點會 unknown column 全面失敗。
        // 這個斷言讓那種情況在 migrate 當下就炸掉，而不是等到有人操作代理後台才發現。
        if (!SchemaMonitor::hasColumn('agent_operate_logs', 'reference_type')) {
            throw new RuntimeException(
                'agent_operate_logs reference_type 未生效：請確認 config/custom.php 的 '
                . 'schema_monitor.row_count_exempt_tables 仍包含 agent_operate_logs（並已 config:clear）；'
                . '若確實因表過大無法線上加欄位，'
                . '請改由 DBA 以 ALGORITHM=INSTANT（MySQL 8.0.29+）或 pt-online-schema-change 加欄位後再重跑 migration'
            );
        }
    }

    public function down(): void
    {
        SchemaMonitor::table('agent_operate_logs', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('agent_operate_logs', 'reference_type')) {
                $table->dropColumn('reference_type');
            }
        });
    }
};
