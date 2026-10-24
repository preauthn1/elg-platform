<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\MemberStatusEnum;
use App\Enums\MemberStatusLogTypeEnum;
use App\Models\Member;
use App\Models\MemberStatusLog;
use App\Services\MemberFailCountService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 會員驗證失敗計次：四種失敗共用的計次與達上限鎖帳
 *
 * 登入密碼、Google 驗證、驗證碼各自的鎖帳情境在各自的測試檔；這裡驗共用部分：
 * 查詢順序、各類型對應的欄位、只計次不鎖帳。
 *
 * php artisan test tests/Unit/Services/MemberFailCountServiceTest.php
 */
class MemberFailCountServiceTest extends TestCase
{
    private function service(): MemberFailCountService
    {
        return App::make(MemberFailCountService::class);
    }

    private function member(): Member
    {
        return $this->createMember($this->createAgentAndDomain(), ['username' => 'mfc_' . uniqid()]);
    }

    /**
     * 回傳第一筆符合 pattern 的查詢在查詢紀錄中的位置，查無回 null
     *
     * @param  list<string>  $queries
     */
    private function firstQueryIndex(array $queries, string $pattern): ?int
    {
        foreach ($queries as $index => $query) {
            if (preg_match($pattern, $query) === 1) {
                return $index;
            }
        }

        return null;
    }

    /**
     * 只計次的路徑（支付密碼）一樣要先拿會員鎖、再以鎖定讀重取失敗次數，同一會員的併發失敗才不會少算。
     * 兩個請求真的同時進來的情境在測試框架重現不了，這裡改驗查詢順序。
     */
    public function test_increase_locks_member_row_before_reading_statistics(): void
    {
        $member = $this->member();

        DB::enableQueryLog();
        $count = $this->service()->increase($member, MemberStatusLogTypeEnum::PAYMENT_PASSWORD_LIMIT);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame(1, $count, '沒有統計列時要先建立，再從 0 加到 1');

        $memberLockAt = $this->firstQueryIndex($queries, '/^select .* from `members` .* for update$/');
        $statisticsLockAt = $this->firstQueryIndex($queries, '/^select .* from `member_statistics` .* for update$/');
        $statisticsUpdateAt = $this->firstQueryIndex($queries, '/^update `member_statistics`/');

        // 交易內第一個一般讀（不論哪張表）決定讀取快照，所以會員鎖必須是第一條查詢
        $this->assertSame(0, $memberLockAt, '會員鎖應是交易內第一條查詢，排在任何一般讀之前');
        $this->assertNotNull($statisticsLockAt, '失敗次數應以鎖定讀重取');
        $this->assertNotNull($statisticsUpdateAt, '應寫回失敗次數');
        $this->assertLessThan($statisticsUpdateAt, $statisticsLockAt, '寫回前應先鎖定讀重取');
    }

    public function test_increase_only_counts_without_locking(): void
    {
        $member = $this->member();
        $member->statistics()->create(['payment_pwd_fail_count' => 99]);

        $count = $this->service()->increase($member, MemberStatusLogTypeEnum::PAYMENT_PASSWORD_LIMIT);

        $this->assertSame(100, $count);
        $this->assertSame(100, (int) $member->statistics()->first()->payment_pwd_fail_count);
        $this->assertSame(MemberStatusEnum::VERIFIED->value, $member->fresh()->status, '只計次不應鎖帳');
        $this->assertSame(0, MemberStatusLog::where('member_id', $member->id)->count());
    }

    /**
     * 每種鎖帳原因只加它自己的欄位，登入密碼以外不動累計總次數
     */
    public function test_increase_and_lock_counts_only_the_column_of_its_type(): void
    {
        $member = $this->member();
        $member->statistics()->create([
            'login_fail_count' => 1,
            'total_login_fail_count' => 1,
            'validation_code_fail_count' => 1,
            'payment_pwd_fail_count' => 1,
            'google_auth_fail_count' => 1,
        ]);

        $count = $this->service()->increaseAndLock(
            $member,
            MemberStatusLogTypeEnum::VERIFICATION_CODE_LIMIT,
            static fn (int $failCount): bool => false,
        );

        $this->assertSame(2, $count);
        $statistics = $member->statistics()->first();
        $this->assertSame(2, (int) $statistics->validation_code_fail_count);
        $this->assertSame(1, (int) $statistics->login_fail_count);
        $this->assertSame(1, (int) $statistics->total_login_fail_count);
        $this->assertSame(1, (int) $statistics->payment_pwd_fail_count);
        $this->assertSame(1, (int) $statistics->google_auth_fail_count);
    }

    public function test_increase_and_lock_locks_with_the_given_type(): void
    {
        $member = $this->member();
        $member->statistics()->create(['google_auth_fail_count' => 5]);

        $count = $this->service()->increaseAndLock(
            $member,
            MemberStatusLogTypeEnum::GOOGLE_AUTH_LIMIT,
            static fn (int $failCount): bool => $failCount >= 6,
        );

        $this->assertSame(6, $count);
        $this->assertSame(MemberStatusEnum::LOCKED->value, $member->fresh()->status);
        $logs = MemberStatusLog::where('member_id', $member->id)->get();
        $this->assertCount(1, $logs);
        $this->assertSame(MemberStatusLogTypeEnum::GOOGLE_AUTH_LIMIT, $logs->first()->type);
    }

    public function test_rejects_type_without_fail_count(): void
    {
        $member = $this->member();

        $this->expectException(InvalidArgumentException::class);

        $this->service()->increase($member, MemberStatusLogTypeEnum::BACKEND_CHANGE);
    }
}
