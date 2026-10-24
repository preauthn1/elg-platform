<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string COLUMN_NAME = 'active_uk';

    private const string INDEX_NAME = 'uk_banks_active';

    /**
     * 把「active 唯一」約束轉正進 schema 基線（generated stored column + partial unique，
     * 與 bank:guard-active-banks 命令建立的結構完全相同；改任一側的 storedAs 表達式
     * 必須同步另一側）。
     *
     * 六個生產連線已於 2026-08-31 前由該命令逐站「先清後鎖」完成，對它們本 migration
     * 是 no-op；轉正的價值在新環境（新白標、測試庫）從 schema 基線就自帶防護，且
     * schema 比對不再把 active_uk 視為 drift。
     *
     * 機制：active_uk 只在 status=1 時有值（inactive 為 NULL），唯一索引放行多個 NULL
     * → 同鍵至多一筆 active，inactive／軟刪列不受限。
     *
     * 工法（比照 2026_08_07_180000 唯一約束前例）：ALTER 前先 preflight 查重 fail loud
     * ——column 與 index 是兩句獨立 ALTER、MySQL DDL 隱式 commit，若靠 duplicate entry
     * 失敗會留下「有欄位、無索引」的半套且重跑難以辨識；因此重複列在動 DDL 前就擋下。
     * column／index 各自判存在、各自補建，半套狀態重跑本 migration 可自癒。
     *
     * ⚠️ 營運註記：guard 一旦被 bank:dedup-rollback（回滾時主動卸鎖）或 migrate:rollback
     * 卸除，migrations 表已有紀錄 ⇒ 之後的 migrate 不會補回，須重跑 bank:guard-active-banks；
     * 排程稽核 bank:verify-guard 會在缺席／半套時告警。
     *
     * ⚠️ 測試側：需要製造 active 重複當 fixture 的測試（dedup 家族），以
     * Tests\Concerns\DetachesBanksActiveGuard 於類級卸除／復原本約束。
     */
    public function up(): void
    {
        $hasColumn = SchemaMonitor::hasColumn('banks', self::COLUMN_NAME);
        $hasIndex = $this->indexExists();

        if ($hasColumn && $hasIndex) {
            return;
        }

        // Preflight：與唯一索引同 scope（僅 status=1）自檢重複，有重複即列組 fail loud，
        // 不讓 ALTER 半途炸出 duplicate entry（執行當下自檢＝各站部署時自動把關）。
        $duplicates = DB::table('banks')
            ->select('agent_group_id', 'type', 'currency_id', 'code', DB::raw('COUNT(*) AS cnt'))
            ->where('status', 1)
            ->groupBy('agent_group_id', 'type', 'currency_id', 'code')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'banks 存在 active 同鍵重複，須先執行 bank:dedup-active --execute 清理後再重跑 migration：%s',
                $duplicates->map(fn ($row) => sprintf(
                    '(group=%d, type=%d, currency=%s, code=%s) x%d',
                    $row->agent_group_id,
                    $row->type,
                    $row->currency_id ?? 'NULL',
                    $row->code,
                    $row->cnt,
                ))->implode('; '),
            ));
        }

        SchemaMonitor::table('banks', function (Blueprint $table) use ($hasColumn, $hasIndex): void {
            if (!$hasColumn) {
                $table->string(self::COLUMN_NAME, 64)
                    ->nullable()
                    ->storedAs("if(`status` = 1, concat_ws('|', `agent_group_id`, `type`, coalesce(`currency_id`, -1), `code`), null)");
            }

            if (!$hasIndex) {
                $table->unique(self::COLUMN_NAME, self::INDEX_NAME);
            }
        });

        // 生效斷言（column 與 index 缺一不可）：SchemaMonitor 的生產列數保護是「靜默跳過
        // 仍記為已執行」，把失敗前移到 migrate 當下 fail loud，而非拖到重複列再生才發現防線不在。
        if (!SchemaMonitor::hasColumn('banks', self::COLUMN_NAME) || !$this->indexExists()) {
            throw new RuntimeException(
                'banks active 唯一約束未完整生效（active_uk 欄位與 uk_banks_active 索引缺一不可）：'
                . 'SchemaMonitor 可能因生產列數保護靜默跳過，請確認 banks 列數在門檻內後重跑 migration'
            );
        }
    }

    public function down(): void
    {
        SchemaMonitor::table('banks', function (Blueprint $table): void {
            if ($this->indexExists()) {
                $table->dropUnique(self::INDEX_NAME);
            }

            if (SchemaMonitor::hasColumn('banks', self::COLUMN_NAME)) {
                $table->dropColumn(self::COLUMN_NAME);
            }
        });

        // 反向生效斷言：對稱防 SchemaMonitor 靜默跳過造成「回報已回滾、欄位還在」。
        if (SchemaMonitor::hasColumn('banks', self::COLUMN_NAME) || $this->indexExists()) {
            throw new RuntimeException(
                'banks active 唯一約束未完整卸除：SchemaMonitor 可能因生產列數保護靜默跳過，請確認後重跑 rollback'
            );
        }
    }

    /** 檢查索引是否已存在（比照 2026_08_07_180000 的判準；表名為常量、無綁定輸入） */
    private function indexExists(): bool
    {
        return count(DB::select(
            'SHOW INDEX FROM `banks` WHERE Key_name = ?',
            [self::INDEX_NAME],
        )) > 0;
    }
};
