<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\CacheKeys\CacheKeys;
use App\Enums\MemberStatusEnum;
use App\Enums\MemberStatusLogTypeEnum;
use App\Models\Member;
use App\Models\MemberAccessToken;
use App\Models\MemberStatusLog;
use App\Services\MemberAccessTokenService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * MemberAccessTokenService 測試
 *
 * php artisan test tests/Unit/Services/MemberAccessTokenServiceTest.php
 */
class MemberAccessTokenServiceTest extends TestCase
{
    private function service(): MemberAccessTokenService
    {
        return new MemberAccessTokenService;
    }

    public function test_issue_invalidates_active_tokens_clears_cache_and_returns_new_string(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'tok_' . uniqid()]);

        $oldTokenString = 'old-token-xxxxxxxxxxxxxxxxxxxxxxxx';
        $future = date('Y-m-d H:i:s', strtotime('+1 day'));
        MemberAccessToken:[REDACTED]
            'member_id' => $member->id,
            'token' => $oldTokenString,
            'expire_date' => $future,
            'admin' => 0,
        ]);

        $cacheKey = CacheKeys::memberAccessToken($oldTokenString);
        Cache::put($cacheKey, [
            'member_id' => $member->id,
            'expire_at' => strtotime($future),
        ], 3600);

        $newToken = [REDACTED];

        $this->assertSame(32, strlen($newToken));
        $this->assertNull(Cache::get($cacheKey));

        $old = MemberAccessToken:[REDACTED]'token', $oldTokenString)->first();
        $this->assertNotNull($old);
        $this->assertLessThanOrEqual(time(), strtotime($old->expire_date));

        $latest = MemberAccessToken:[REDACTED]'member_id', $member->id)
            ->where('token', $newToken)
            ->first();
        $this->assertNotNull($latest);
        $this->assertGreaterThan(time(), strtotime($latest->expire_date));
    }

    public function test_revoke_expires_all_active_tokens_and_clears_cache(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'rev_' . uniqid()]);

        $tokenString = 'revoke-token-xxxxxxxxxxxxxxxxxxxx';
        $otherTokenString = 'revoke-token-yyyyyyyyyyyyyyyyyyyyyyyy';
        $future = date('Y-m-d H:i:s', strtotime('+1 day'));
        MemberAccessToken:[REDACTED]
            'member_id' => $member->id,
            'token' => $tokenString,
            'expire_date' => $future,
            'admin' => $member->id,
        ]);
        MemberAccessToken:[REDACTED]
            'member_id' => $member->id,
            'token' => $otherTokenString,
            'expire_date' => $future,
            'admin' => $member->id,
        ]);

        $cacheKey = CacheKeys::memberAccessToken($tokenString);
        Cache::put($cacheKey, [
            'member_id' => $member->id,
            'expire_at' => strtotime($future),
        ], 3600);

        $this->service()->revoke($member->id);

        foreach ([$tokenString, $otherTokenString] as $token) {
            $record = MemberAccessToken:[REDACTED]'token', $token)->first();
            $this->assertNotNull($record);
            $this->assertLessThanOrEqual(time(), strtotime($record->expire_date));
        }

        $this->assertNull(Cache::get($cacheKey));
    }

    public function test_issue_rolls_back_and_keeps_old_token_active_when_create_fails(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'atomic_' . uniqid()]);

        $oldTokenString = 'atomic-old-xxxxxxxxxxxxxxxxxxxxxx';
        $future = date('Y-m-d H:i:s', strtotime('+1 day'));
        MemberAccessToken:[REDACTED]
            'member_id' => $member->id,
            'token' => $oldTokenString,
            'expire_date' => $future,
            'admin' => $member->id,
        ]);

        $cacheKey = CacheKeys::memberAccessToken($oldTokenString);
        Cache::put($cacheKey, [
            'member_id' => $member->id,
            'expire_at' => strtotime($future),
        ], 3600);

        // 注入：新 token 建立時拋例外，模擬 issue 在 revoke 之後、create 環節失敗
        MemberAccessToken:[REDACTED] () {
            throw new \RuntimeException('simulated create failure');
        });

        try {
            $threw = false;
            try {
                $this->service()->issue($member->id);
            } catch (\RuntimeException) {
                $threw = true;
            }

            $this->assertTrue($threw, 'issue 應向外拋出 create 失敗例外');

            // 原子性：整個 issue 回滾——舊 token 仍 active、無殘留新 token
            $old = MemberAccessToken:[REDACTED]'token', $oldTokenString)->first();
            $this->assertNotNull($old);
            $this->assertGreaterThan(time(), strtotime($old->expire_date), '舊 token 應仍為 active（revoke 已隨 transaction 回滾）');

            // 雙 forget 語意：transaction 內第一次 forget 已清 cache（fail-safe 方向），
            // rollback 不還原——DB token 仍 active，下次請求自 DB 自癒回填，無 fail-open 風險
            $this->assertNull(Cache::get($cacheKey), 'cache 已於 transaction 內先清（fail-safe），由下次請求自癒回填');

            $this->assertSame(1, MemberAccessToken:[REDACTED]'member_id', $member->id)->count(), '不應殘留新建 token');
        } finally {
            MemberAccessToken:[REDACTED];
        }
    }

    public function test_lock_member_and_revoke_rolls_back_member_status_when_revoke_fails(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'lock_' . uniqid()]);
        $member->status = MemberStatusEnum::VERIFIED->value;
        $member->save();

        // 注入：revoke 拋例外，模擬 lockMemberAndRevoke 在 save 之後、revoke 環節失敗
        $service = \Mockery::mock(MemberAccessTokenService::class)->makePartial();
        $service->shouldReceive('revoke')->andThrow(new \RuntimeException('simulated revoke failure'));

        $threw = false;
        try {
            $service->lockMemberAndRevoke($member->fresh(), MemberStatusLogTypeEnum::VERIFICATION_CODE_LIMIT);
        } catch (\RuntimeException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'lockMemberAndRevoke 應向外拋出 revoke 失敗例外');

        // 原子性：revoke 失敗 → member 狀態隨 transaction 回滾，不應停在 LOCKED
        $this->assertSame(
            MemberStatusEnum::VERIFIED->value,
            $member->fresh()->status,
            'revoke 失敗時 member 狀態應回滾為 VERIFIED（save 隨 transaction 回滾）'
        );

        $this->assertSame(
            0,
            MemberStatusLog::where('member_id', $member->id)->count(),
            'revoke 失敗時 MemberStatusLog 應隨 transaction 回滾，不應殘留'
        );
    }

    public function test_revoke_within_outer_transaction_rollback_keeps_token_active(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'rbk_' . uniqid()]);

        $tokenString = 'rollback-token-xxxxxxxxxxxxxxxxxx';
        $future = date('Y-m-d H:i:s', strtotime('+1 day'));
        MemberAccessToken:[REDACTED]
            'member_id' => $member->id,
            'token' => $tokenString,
            'expire_date' => $future,
            'admin' => $member->id,
        ]);

        $cacheKey = CacheKeys::memberAccessToken($tokenString);
        Cache::put($cacheKey, [
            'member_id' => $member->id,
            'expire_at' => strtotime($future),
        ], 3600);

        // revoke 在外層 transaction 內執行後 rollback：update 應撤銷、afterCommit 不觸發
        DB::beginTransaction();
        $this->service()->revoke($member->id);
        DB::rollBack();

        $record = MemberAccessToken:[REDACTED]'token', $tokenString)->first();
        $this->assertGreaterThan(time(), strtotime($record->expire_date), 'token 應仍 active（update 隨 rollback 撤銷）');

        // 雙 forget 語意：cache 已被 transaction 內第一次 forget 清掉（fail-safe 方向可接受），
        // DB token 仍 active、下次請求自癒回填
        $this->assertNull(Cache::get($cacheKey), 'cache 已於 transaction 內先清，rollback 不還原');
    }

    public function test_revoke_after_commit_clears_cache_refilled_during_transaction(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'refill_' . uniqid()]);

        $tokenString = 'refill-token-xxxxxxxxxxxxxxxxxxxx';
        $future = date('Y-m-d H:i:s', strtotime('+1 day'));
        MemberAccessToken:[REDACTED]
            'member_id' => $member->id,
            'token' => $tokenString,
            'expire_date' => $future,
            'admin' => $member->id,
        ]);

        $cacheKey = CacheKeys::memberAccessToken($tokenString);
        Cache::put($cacheKey, [
            'member_id' => $member->id,
            'expire_at' => strtotime($future),
        ], 3600);

        // D5 正向時序＋雙 forget：外層 commit 後 afterCommit 二次 forget 應清掉
        // 「commit 前被併發請求回填」的舊值
        DB::beginTransaction();
        $this->service()->revoke($member->id);

        $this->assertNull(Cache::get($cacheKey), 'transaction 內第一次 forget 應已清 cache');

        // 模擬併發請求在 commit 前把舊 token 回填 cache
        Cache::put($cacheKey, [
            'member_id' => $member->id,
            'expire_at' => strtotime($future),
        ], 3600);

        DB::commit();

        $this->assertNull(Cache::get($cacheKey), '外層 commit 後 afterCommit 二次 forget 應清掉回填的舊值');
    }

    public function test_lock_member_and_revoke_happy_path_persists_status_log_and_revokes_tokens(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'lockok_' . uniqid()]);
        $member->status = MemberStatusEnum::VERIFIED->value;
        $member->save();

        $tokenString = 'lock-happy-token-xxxxxxxxxxxxxxxx';
        $future = date('Y-m-d H:i:s', strtotime('+1 day'));
        MemberAccessToken:[REDACTED]
            'member_id' => $member->id,
            'token' => $tokenString,
            'expire_date' => $future,
            'admin' => $member->id,
        ]);

        $cacheKey = CacheKeys::memberAccessToken($tokenString);
        Cache::put($cacheKey, [
            'member_id' => $member->id,
            'expire_at' => strtotime($future),
        ], 3600);

        $this->service()->lockMemberAndRevoke($member, MemberStatusLogTypeEnum::PAYMENT_PASSWORD_LIMIT);

        $this->assertSame(MemberStatusEnum::LOCKED->value, $member->fresh()->status, '會員應被鎖定');

        $log = MemberStatusLog::where('member_id', $member->id)->first();
        $this->assertNotNull($log, 'MemberStatusLog 應落庫');
        $this->assertSame(MemberStatusLogTypeEnum::PAYMENT_PASSWORD_LIMIT, $log->type);
        $this->assertEquals($member->parent, $log->agent_id);
        $this->assertSame(MemberStatusEnum::VERIFIED, $log->before_status);
        $this->assertSame(MemberStatusEnum::LOCKED, $log->after_status);

        $record = MemberAccessToken:[REDACTED]'token', $tokenString)->first();
        $this->assertLessThanOrEqual(time(), strtotime($record->expire_date), 'token 應被作廢');
        $this->assertNull(Cache::get($cacheKey), 'token cache 應被清除');
    }

    public function test_revoke_with_no_active_token_issues_no_update(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'noop_' . uniqid()]);

        // 無 active token：早退應避免發出任何 UPDATE（含 bulk 版的 whereIn 空集合無效 UPDATE）
        $updateCount = 0;
        DB::listen(function ($query) use (&$updateCount) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
                $updateCount++;
            }
        });

        $this->service()->revoke($member->id);

        $this->assertSame(0, $updateCount, '無 active token 時不應發出任何 UPDATE');
    }

    public function test_revoke_bulk_update_sql_carries_updated_at(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'upd_' . uniqid()]);

        $tokenString = 'updated-at-token-xxxxxxxxxxxxxxxx';
        $future = date('Y-m-d H:i:s', strtotime('+1 day'));
        MemberAccessToken:[REDACTED]
            'member_id' => $member->id,
            'token' => $tokenString,
            'expire_date' => $future,
            'admin' => $member->id,
        ]);

        // 驗 D2 的可觀察面：UPDATE 語句本身必須帶 updated_at（Eloquent Builder 的
        // addUpdatedAtColumn）。不能只驗欄位值有刷新——DB 端 ON UPDATE CURRENT_TIMESTAMP
        // 會讓值恆真，驗不到 Eloquent 這一層
        $updateSqls = [];
        DB::listen(function ($query) use (&$updateSqls) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
                $updateSqls[] = $query->sql;
            }
        });

        $this->service()->revoke($member->id);

        $tokenUpdates = array_filter($updateSqls, fn ($sql) => str_contains($sql, 'member_access_tokens'));
        $this->assertNotEmpty($tokenUpdates, 'revoke 應對 member_access_tokens 發出 bulk UPDATE');

        foreach ($tokenUpdates as $sql) {
            $this->assertStringContainsString('updated_at', $sql, 'bulk update 應由 Eloquent Builder 自動帶 updated_at（D2）');
        }
    }

    /**
     * lockMemberAndRevoke() 改會員狀態時必須就在它自己那一層交易，不可再多包一層 savepoint：
     * 多一層時，死結或鎖等待逾時會改丟 DeadlockException，接不到 QueryException 的統一回應
     */
    public function test_lock_member_and_revoke_updates_member_in_its_own_transaction_level(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'lvl_' . uniqid()]);

        $levels = [];
        DB::listen(function ($query) use (&$levels) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update `members`')) {
                $levels[] = DB::transactionLevel();
            }
        });

        $baseLevel = DB::transactionLevel();
        $this->service()->lockMemberAndRevoke($member, MemberStatusLogTypeEnum::PAYMENT_PASSWORD_LIMIT);

        $this->assertSame([$baseLevel + 1], $levels, '改會員狀態應在 lockMemberAndRevoke 自己的交易層，不應多一層');
    }

    public function test_lock_member_writes_status_log_without_revoking_tokens(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'lockonly_' . uniqid()]);

        $future = date('Y-m-d H:i:s', strtotime('+1 day'));
        $token = [REDACTED]
            'member_id' => $member->id,
            'token' => 'lock-only-token-xxxxxxxxxxxxxxxxx',
            'expire_date' => $future,
            'admin' => $member->id,
        ]);

        $this->service()->lockMember($member, MemberStatusLogTypeEnum::LOGIN_PASSWORD_LIMIT);

        $this->assertSame(MemberStatusEnum::LOCKED->value, $member->fresh()->status, '會員應被鎖定');

        $log = MemberStatusLog::where('member_id', $member->id)->first();
        $this->assertNotNull($log, 'MemberStatusLog 應落庫');
        $this->assertSame(MemberStatusLogTypeEnum::LOGIN_PASSWORD_LIMIT, $log->type);
        $this->assertSame(MemberStatusEnum::VERIFIED, $log->before_status);
        $this->assertSame(MemberStatusEnum::LOCKED, $log->after_status);

        $this->assertGreaterThan(time(), strtotime($token->fresh()->expire_date), 'lockMember 不作廢 token');
    }

    /**
     * 呼叫端手上的 $member 是鎖定前讀到的舊狀態，資料庫裡已被同時進來的請求鎖定：
     * 以鎖內重讀的狀態為準，不重寫狀態紀錄
     */
    public function test_lock_member_skips_status_log_when_already_locked(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'lockagain_' . uniqid()]);
        Member::whereKey($member->id)->update(['status' => MemberStatusEnum::LOCKED->value]);

        $this->assertSame(MemberStatusEnum::VERIFIED->value, $member->status, '前提：手上的 $member 仍是舊狀態');

        $this->service()->lockMember($member, MemberStatusLogTypeEnum::VERIFICATION_CODE_LIMIT);

        $this->assertSame(MemberStatusEnum::LOCKED->value, $member->fresh()->status);
        $this->assertSame(0, MemberStatusLog::where('member_id', $member->id)->count(), '已鎖定的會員不應再寫一筆狀態紀錄');
    }

    public function test_lock_member_rolls_back_status_when_status_log_fails(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'lockfail_' . uniqid()]);

        // 注入：寫狀態紀錄時拋例外，模擬 lockMember 在改狀態之後、寫紀錄環節失敗
        MemberStatusLog::creating(static function (): void {
            throw new \RuntimeException('simulated status log failure');
        });

        $caught = null;
        try {
            $this->service()->lockMember($member->fresh(), MemberStatusLogTypeEnum::LOGIN_PASSWORD_LIMIT);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        // 核對訊息：QueryException 也是 RuntimeException，要確認回滾是寫紀錄失敗造成的，不是更早的 save() 失敗
        $this->assertSame('simulated status log failure', $caught?->getMessage(), 'lockMember 應向外拋出寫紀錄失敗的例外');
        $this->assertSame(
            MemberStatusEnum::VERIFIED->value,
            $member->fresh()->status,
            '寫狀態紀錄失敗時，會員狀態應隨交易回滾'
        );
    }

    public function test_revoke_after_lock_revokes_active_tokens(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'afterlock_' . uniqid()]);

        $token = [REDACTED]
            'member_id' => $member->id,
            'token' => 'after-lock-token-xxxxxxxxxxxxxxxx',
            'expire_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'admin' => $member->id,
        ]);

        $this->service()->revokeAfterLock($member, MemberStatusLogTypeEnum::LOGIN_PASSWORD_LIMIT);

        $this->assertLessThanOrEqual(time(), strtotime($token->fresh()->expire_date), 'token 應被作廢');
    }

    public function test_revoke_after_lock_logs_and_reports_without_throwing_when_revoke_fails(): void
    {
        Exceptions::fake();
        Log::spy();

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'afterlockfail_' . uniqid()]);

        // 注入：revoke 拋例外；限定只被呼叫一次
        $service = \Mockery::mock(MemberAccessTokenService::class)->makePartial();
        $service->shouldReceive('revoke')->once()->andThrow(new \RuntimeException('simulated revoke failure'));

        // 不應往外拋
        $service->revokeAfterLock($member, MemberStatusLogTypeEnum::LOGIN_PASSWORD_LIMIT);

        Log::shouldHaveReceived('error')
            ->withArgs(static fn (string $message, array $context): bool => $message === 'MemberAccessToken revoke failed after member lock'
                && $context['member_id'] === $member->id
                && $context['type'] === MemberStatusLogTypeEnum::LOGIN_PASSWORD_LIMIT->name
                && $context['exception'] === \RuntimeException::class)
            ->once();

        Exceptions::assertReported(
            static fn (\RuntimeException $e): bool => $e->getMessage() === 'simulated revoke failure'
        );
    }
}
