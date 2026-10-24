<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * 索引名 => 欄位。三個單欄索引，用途逐一對應實際查詢（見 up() docblock）。
     *
     * @var array<string, string>
     */
    private const INDEXES = [
        'bank_dedup_logs_loser_bank_id_index' => 'loser_bank_id',
        'bank_dedup_logs_action_index' => 'action',
        'bank_dedup_logs_created_at_index' => 'created_at',
    ];

    /**
     * `bank_dedup_logs` 建表至今只有 PRIMARY 一個索引（六站實查），但它同時是三種讀取路徑
     * 的來源。逐一對應：
     *
     * - `loser_bank_id`：`BankMismatchAuditor::loadDbBanks()` 的 `--include-inactive` 路徑
     *   以 `whereNotIn('id', select loser_bank_id …)` 排除「曾作為 loser 被收斂停用」的列。
     *   單欄索引可讓這個子查詢走 covering index scan、不必回表。
     * - `action`：`DedupRollback::handle()` 以 `whereIn('action', [...])` 撈可回滾的紀錄；
     *   驗收 SOP 的「審計筆數核對」也是 `GROUP BY action`。
     * - `created_at`：驗收 SOP 每次實跑後以 `created_at >= <執行時戳>` 撈本次寫入的審計，
     *   核對筆數與預演是否一致。這是唯一的範圍掃描，也是最會隨表成長而變慢的一支。
     *
     * ⚠️ **當下不是效能瓶頸，這是前瞻性補齊**（誠實標註，勿事後誤讀為修了什麼慢查詢）：
     * 實查 VWorld 1,229 列、TPower 981 列、Yes2Win 633 列，這個量級全表掃描的成本可以忽略。
     * 加索引的理由是這張表只增不減、且每次收斂都逐列寫入（單次最大批曾寫 1,032 筆改指的
     * 審計），成長是單向的；等它變慢才加，屆時 ADD INDEX 的鎖面反而更大。
     *
     * 寫入側成本可接受：審計是批量 INSERT，三個 secondary index 會增加寫放大，但相對於
     * 同一交易內的 banks／cash_flow_banks／member_bank_accounts 寫入與鎖持有時間可忽略。
     *
     * ADD INDEX 是 INPLACE（不重建表、允許並發 DML），六站列數皆遠低於 SchemaMonitor 的
     * 50 萬列保護門檻。
     */
    public function up(): void
    {
        foreach (self::INDEXES as $name => $column) {
            if (!SchemaMonitor::hasIndex('bank_dedup_logs', $name)) {
                SchemaMonitor::table('bank_dedup_logs', function (Blueprint $table) use ($name, $column) {
                    $table->index($column, $name);
                });
            }

            // 生效斷言：SchemaMonitor 的生產列數保護是「靜默跳過仍記為已執行」。這裡把失敗
            // 前移到 migrate 當下 fail loud，而非拖到依賴索引的查詢變慢才發現。
            if (!SchemaMonitor::hasIndex('bank_dedup_logs', $name)) {
                throw new RuntimeException(sprintf(
                    'bank_dedup_logs %s 索引未生效：SchemaMonitor 可能因生產列數保護靜默跳過，'
                    . '請確認列數在門檻內後重跑 migration',
                    $column,
                ));
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            if (SchemaMonitor::hasIndex('bank_dedup_logs', $name)) {
                SchemaMonitor::table('bank_dedup_logs', function (Blueprint $table) use ($name) {
                    $table->dropIndex($name);
                });
            }
        }
    }
};
