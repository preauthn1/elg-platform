<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Tests\Support\BanksActiveGuardSchema;

/**
 * 供「必須製造 active 同鍵重複當 fixture」的測試類（dedup 家族）使用：類開始前卸除
 * banks 的 active 唯一約束、類結束後復原，中間各單測維持 DatabaseTransactions 正常
 * 包裹（DDL 全部發生在測試交易之外）。
 *
 * 只有 schema 基線帶約束（promote_banks_active_unique_guard migration 已跑）時才會
 * 實際卸除；基線沒有約束時前後皆 no-op——測試在兩種 schema 世界都成立。
 *
 * ⚠️ 使用類不得自行定義 setUpBeforeClass / tearDownAfterClass（類自身方法會靜默蓋掉
 * trait 版本＝卸鎖不會發生）；需要類級 hook 時改在本 trait 的呼叫點前後擴充。
 * ⚠️ 卸鎖窗口是「整個類的執行期」：同一測試庫上並行跑別的 guard 相關斷言會互相干擾
 * （CI `--parallel` 有 `_test_{token}` 分庫天然隔離；本機同庫多 session 請避開）。
 * ⚠️ 程序在類執行中途被 kill 時 tearDownAfterClass 不會跑、測試庫會殘留「無 guard」
 * 狀態且不自癒：以 `php artisan bank:guard-active-banks --env=testing` 手動復原。
 */
trait DetachesBanksActiveGuard
{
    private static bool $banksActiveGuardDetached = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$banksActiveGuardDetached = BanksActiveGuardSchema::drop();
    }

    public static function tearDownAfterClass(): void
    {
        try {
            if (self::$banksActiveGuardDetached) {
                BanksActiveGuardSchema::restore();
            }
        } finally {
            self::$banksActiveGuardDetached = false;
            parent::tearDownAfterClass();
        }
    }
}
