<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Facades\SchemaMonitor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * banks active 唯一約束的 schema 基線守衛：migration promote_banks_active_unique_guard
 * 已套用時，active_uk 欄位與 uk_banks_active 索引必須同時存在——直接抓「有欄位、
 * 無索引」的半套狀態（兩句 ALTER 各自隱式 commit，第二句失敗即留半套）。
 *
 * migration 未套用（本機測試庫常態，直到本分支發版）時跳過不誤報。
 *
 * ⚠️ 順序耦合：若某支掛 DetachesBanksActiveGuard 的類中途 fatal 導致 tearDownAfterClass
 * 未執行，guard 未復原，本測試會紅——那正是在報「測試庫 schema 被卸鎖沒還」，訊號正確；
 * 以 `php artisan bank:guard-active-banks --env=testing` 復原。
 */
class BanksActiveGuardTest extends TestCase
{
    public function test_active_guard_column_and_index_exist_when_migration_ran(): void
    {
        $migrationRan = DB::table('migrations')
            ->where('migration', '2026_08_31_000001_promote_banks_active_unique_guard')
            ->exists();

        if (!$migrationRan) {
            $this->markTestSkipped('promote_banks_active_unique_guard 尚未套用於本測試庫。');
        }

        $this->assertTrue(
            SchemaMonitor::hasColumn('banks', 'active_uk'),
            'active_uk generated column 應存在。'
        );
        $this->assertTrue(
            SchemaMonitor::hasIndex('banks', 'uk_banks_active'),
            'uk_banks_active 唯一索引應存在（只有欄位沒有索引＝半套，防線不在）。'
        );
    }
}
