<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * ACFOT 唯一 live 連結的 DB 層終防線。
 *
 * 同 (agent_cash_flow_id, order_type_id) 至多一筆未軟刪列（啟停走 status，不以第二列表達）。
 * SoftDeletes 下直接 unique 不可行（多重 NULL deleted_at 不互斥），以 virtual generated column
 * 迂迴：live 列 is_live=1、軟刪列 is_live=NULL（NULL 不參與 unique 碰撞）。
 *
 * 覆蓋 Observer guard 蓋不住的路徑：併發 check-then-insert race、restore()（僅動 deleted_at）、
 * cascade restore 的 query builder bulk update。Observer guard 保留為友善錯誤訊息層。
 * 2026-08-07 六站盤點 live 重複皆 0，加約束安全。
 */
return new class extends Migration
{
    public function up(): void
    {
        // Preflight：加約束前自檢重複（與 unique index 同 scope：未軟刪、不分 status），
        // 有重複即 fail loud 列出 pair，避免 ALTER 半途炸出難讀的 duplicate entry。
        // 執行當下自檢＝各站部署時自動把關，不依賴事前盤點的時效性。
        // （migration 情境用 DB::table 聚合；COUNT(*) 為常量聚合、無綁定輸入）
        $duplicates = DB::table('agent_cash_flow_order_types')
            ->select('agent_cash_flow_id', 'order_type_id', DB::raw('COUNT(*) AS cnt'))
            ->whereNull('deleted_at')
            ->groupBy('agent_cash_flow_id', 'order_type_id')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'agent_cash_flow_order_types 存在未軟刪的重複 (agent_cash_flow_id, order_type_id)，'
                . '須先人工去重（軟刪其一）再重跑 migration：%s',
                $duplicates->map(fn ($row) => sprintf(
                    '(acf_id=%d, ot_id=%d) x%d',
                    $row->agent_cash_flow_id,
                    $row->order_type_id,
                    $row->cnt,
                ))->implode('; '),
            ));
        }

        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'is_live')) {
                $table->tinyInteger('is_live')
                    ->nullable()
                    ->virtualAs('IF(deleted_at IS NULL, 1, NULL)')
                    ->comment('唯一性輔助欄：live=1、軟刪=NULL（generated，勿寫入）')
                    ->after('deleted_at');
            }

            if (!$this->indexExists('agent_cash_flow_order_types', 'uq_acfot_live_link')) {
                $table->unique(
                    ['agent_cash_flow_id', 'order_type_id', 'is_live'],
                    'uq_acfot_live_link',
                );
            }
        });

        // 生效斷言：SchemaMonitor 生產列數保護是「靜默跳過仍記為已執行」，前移到 migrate 當下 fail loud
        if (!SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'is_live')
            || !$this->indexExists('agent_cash_flow_order_types', 'uq_acfot_live_link')) {
            throw new RuntimeException(
                'agent_cash_flow_order_types 唯一 live 連結約束未生效：'
                . 'SchemaMonitor 可能因生產列數保護靜默跳過，請確認後重跑 migration'
            );
        }
    }

    public function down(): void
    {
        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            if ($this->indexExists('agent_cash_flow_order_types', 'uq_acfot_live_link')) {
                $table->dropUnique('uq_acfot_live_link');
            }

            if (SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'is_live')) {
                $table->dropColumn('is_live');
            }
        });
    }

    /** 檢查索引是否已存在，避免重複建立 */
    private function indexExists(string $table, string $index): bool
    {
        return count(DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$index],
        )) > 0;
    }
};
