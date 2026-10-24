<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\DetachesBanksActiveGuard;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/CashFlow/Bank/GuardActiveBanksCommandTest.php
 *
 * 為何不能比照其他測試走事務回滾：受測對象本身就是 DDL——`bank:guard-active-banks`
 * 的動作即 `ALTER TABLE banks`（新增 generated stored column `active_uk` ＋建唯一索引），
 * 而 MySQL 執行 DDL 會隱式 commit、當下交易被強制提交，{@see DatabaseTransactions}
 * 的 rollback 就此失效。要它走回滾等於要求「測一個會終結交易的操作，同時交易還活著」。
 *
 * 更關鍵的是隱式 commit 會把「它之前的所有寫入」一併提交，包含 makeBank() 插入的
 * fixture 列——所以連資料列都回不去，必須自行清理（舊版寫 truncate 的動機即在此，
 * 但那把刀過大，見下方事故說明）。
 *
 * 附帶一提，本檔兩支測試並不對等：test_aborts_when_active_duplicates_still_exist
 * 偵測到重複就 return FAILURE、ALTER 沒跑到，實際上仍受交易保護；無解的只有建約束那支。
 *
 * 故本檔自行於 tearDown 卸除新增的 schema 與隱式 commit 殘留的列。
 *
 * ⚠️ tearDown 只允許清理「本測試自己建立的東西」：
 * 2026-08-25 Dev 庫 banks 全表被清空的事故，根因即本檔舊版 tearDown 的
 * `DB::table('banks')->truncate()`——測試連上非測試庫時等於清空真實資料，
 * 且 phpunit.xml 固定 APP_ENV=testing，靠環境名防呆擋不住連錯庫。
 * 因此 (1) 資料列以 insertGetId 回傳的 id 逐筆追蹤、tearDown 精準刪除；
 * (2) schema 只卸本測試建立的（setUp 時已存在的 active_uk 不動）；
 * (3) 禁止 truncate 與無條件 delete。
 */
class GuardActiveBanksCommandTest extends TestCase
{
    use DetachesBanksActiveGuard;

    /** setUp 時 banks 是否已有 active_uk：既有的 guard 約束不是本測試建的，tearDown 不卸 */
    private bool $hadActiveUkColumn = false;

    /** @var list<int> 本測試插入的 banks.id，tearDown 逐筆刪除 */
    private array $fixtureBankIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->hadActiveUkColumn = Schema::hasColumn('banks', 'active_uk');
        $this->fixtureBankIds = [];
    }

    protected function tearDown(): void
    {
        if (!$this->hadActiveUkColumn && Schema::hasColumn('banks', 'active_uk')) {
            DB::statement('ALTER TABLE banks DROP INDEX uk_banks_active');
            DB::statement('ALTER TABLE banks DROP COLUMN active_uk');
        }

        if ($this->fixtureBankIds !== []) {
            DB::table('banks')->whereIn('id', $this->fixtureBankIds)->delete();
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeBank(array $attrs = []): int
    {
        return $this->fixtureBankIds[] = (int) DB::table('banks')->insertGetId(array_merge([
            'agent_group_id' => 674,
            'type' => 1,
            'name' => '(MYR) - AGRO Bank',
            'code' => 'MYR0023',
            'currency_id' => 1,
            'status' => 1,
            'created_at' => Carbon::parse('2026-01-01 00:00:00'),
            'updated_at' => Carbon::parse('2026-01-01 00:00:00'),
        ], $attrs));
    }

    public function test_aborts_when_active_duplicates_still_exist(): void
    {
        $this->makeBank(['created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $this->makeBank(['created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $this->artisan('bank:guard-active-banks')
            ->expectsOutputToContain('仍有')
            ->assertExitCode(1);

        $this->assertFalse(Schema::hasColumn('banks', 'active_uk'));
    }

    public function test_builds_constraint_blocks_second_active_duplicate_and_is_idempotent(): void
    {
        $this->makeBank(['code' => 'MYR0023', 'status' => 1]);

        $this->artisan('bank:guard-active-banks')
            ->expectsOutputToContain('已建立')
            ->assertExitCode(0);

        $this->assertTrue(Schema::hasColumn('banks', 'active_uk'));

        // 第二筆 active 同鍵 → 唯一約束擋下
        try {
            $this->makeBank(['code' => 'MYR0023', 'status' => 1]);
            $this->fail('預期唯一約束擋下第二筆 active 同鍵，但插入成功');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('uk_banks_active', $e->getMessage());
        }

        // inactive 同鍵不受限（active_uk 為 NULL）
        $inactiveId = $this->makeBank(['code' => 'MYR0023', 'status' => 0]);
        $this->assertGreaterThan(0, $inactiveId);

        // idempotent：再跑一次為 no-op
        $this->artisan('bank:guard-active-banks')
            ->expectsOutputToContain('已存在')
            ->assertExitCode(0);
    }
}
