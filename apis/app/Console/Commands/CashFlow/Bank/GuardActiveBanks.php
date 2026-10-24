<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Services\CashFlow\Bank\BankActiveDedupService;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 對 banks 加上「active 唯一」約束，防止 active 重複再生。
 *
 * 【分類】Bank — 一次性 DDL（逐站各跑一次），約束上線後長期生效（退役條件見下方）。
 *
 * 機制：新增 generated stored column active_uk = active 列才有值的自然鍵，inactive 列為 NULL，
 * 再對 active_uk 建唯一索引（MySQL 唯一索引放行多個 NULL）→ 至多一筆 active 同鍵，
 * inactive 重複不受限。idempotent：已建立則略過；仍有 active 重複則拒絕（須先清理）。
 *
 * 須於該白標 bank:dedup-active --execute 清理完成後，逐站執行。
 *
 * 為什麼當初做成命令而不是 migration：dedup 的測試刻意插入兩筆同鍵 active 列模擬清理前狀態，
 * migration 隨 CI 自動套用會讓那些 fixture 插入失敗；且 MySQL DDL 隱式 commit，DatabaseTransactions
 * 回不掉。做成命令才能「逐站、清理後才上」。六站清淨上鎖後才轉正為 migration（見下一段）。
 * currency_id 用 coalesce(…, -1) 補哨兵，是因為 concat_ws 會跳過 NULL，不補會造成位移歧義。
 *
 * ⚠️ 結構定義有一份同構副本在 migration promote_banks_active_unique_guard（schema 基線
 * 轉正）；改動 storedAs 表達式／欄位長度／索引名必須同步兩處。存在性稽核見 bank:verify-guard。
 *
 * ## 退役條件
 *
 * 結構定義已由 migration `promote_banks_active_unique_guard` 轉正 ⇒ 新站經 migrate 自帶約束，
 * 本命令對新站已無必要。既有六站建立完畢後，它只剩「`bank:dedup-rollback` 卸鎖後重建」
 * 這一個用途 ⇒ **與 `bank:dedup-active`／`bank:dedup-rollback`／`bank:verify-guard` 四支
 * 同進退，要退一起退。**
 */
class GuardActiveBanks extends Command
{
    protected $signature = 'bank:guard-active-banks';

    protected $description = '對 banks 加上 active 唯一約束（generated column partial unique），防止 active 重複再生';

    public function handle(BankActiveDedupService $service): int
    {
        // 存在性以 column＋index 雙要素判定：兩句 ALTER 各自隱式 commit，第二句失敗會留
        // 「有欄位、無索引」的半套（唯一性靠 index，半套＝防線不在），只看 column 會誤判已存在。
        $hasColumn = Schema::hasColumn('banks', 'active_uk');
        $hasIndex = $this->indexExists();

        if ($hasColumn && $hasIndex) {
            $this->info('active 唯一約束（uk_banks_active）已存在，無需重複建立。');

            return self::SUCCESS;
        }

        $duplicates = $service->detectDuplicateGroups();

        if ($duplicates->isNotEmpty()) {
            $this->error(sprintf(
                '仍有 %d 組 active 重複，請先執行 bank:dedup-active --execute 清理後再建立約束。',
                $duplicates->count()
            ));

            return self::FAILURE;
        }

        if ($hasColumn) {
            $this->warn('偵測到半套狀態（active_uk 欄位存在但缺 uk_banks_active 索引），補建索引。');
        }

        Schema::table('banks', function (Blueprint $table) use ($hasColumn, $hasIndex): void {
            if (!$hasColumn) {
                // ⚠️ storedAs 表達式與 migration promote_banks_active_unique_guard 同構，改動必須同步兩處
                $table->string('active_uk', 64)
                    ->nullable()
                    ->storedAs("if(`status` = 1, concat_ws('|', `agent_group_id`, `type`, coalesce(`currency_id`, -1), `code`), null)");
            }

            if (!$hasIndex) {
                $table->unique('active_uk', 'uk_banks_active');
            }
        });

        $this->info('已建立 active 唯一約束（uk_banks_active）。');

        return self::SUCCESS;
    }

    /** 檢查索引是否存在（表名為常量、無綁定輸入；與 migration 的判準一致） */
    private function indexExists(): bool
    {
        return count(DB::select(
            'SHOW INDEX FROM `banks` WHERE Key_name = ?',
            ['uk_banks_active'],
        )) > 0;
    }
}
