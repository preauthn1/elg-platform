<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Vendor;

use App\Console\Commands\Vendor\TransferMoneyBack;
use App\Enums\AdminOperationStatusEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\General\ContextKeyEnum;
use App\Exceptions\AccountNotExistsException;
use App\Models\AdminOperationLog;
use App\Models\Agent;
use App\Models\AgentVendor;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\MemberTransferService;
use App\Services\VendorService;
use App\Vendors\AbstractTransferWallet;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * vendor:transfer_money_back 經後台 queue 觸發時的範疇、收尾行為（exit code、摘要、續跑起點）。
 *
 * 三方轉帳以 mock 取代（VendorService::getDriver／MemberTransferService::transferToLocal），
 * 只驗指令自身的撈取範疇與收尾邏輯。
 *
 * php artisan test tests/Feature/Console/Commands/Vendor/TransferMoneyBackTest.php --compact
 */
class TransferMoneyBackTest extends TestCase
{
    #[TestDox('vendor 不存在：回 FAILURE 而非 exit（queue worker 內 exit 會砍掉 worker）')]
    public function test_missing_vendor_returns_failure(): void
    {
        $this->artisan('vendor:transfer_money_back', ['--vendorId' => 999999, '--agentId' => 1])
            ->expectsOutputToContain('vendor 不存在')
            ->assertExitCode(Command::FAILURE);
    }

    #[TestDox('代理未開通該遊戲商（查無 vendor_info）：回 FAILURE')]
    public function test_missing_vendor_info_returns_failure(): void
    {
        $vendor = Vendor::factory()->create();
        $agent = Agent::factory()->create();

        $this->artisan('vendor:transfer_money_back', ['--vendorId' => $vendor->id, '--agentId' => $agent->id])
            ->expectsOutputToContain('vendor_info 不存在')
            ->assertExitCode(Command::FAILURE);
    }

    #[TestDox('沒有任何啟用中遊戲帳號：輸出「共 0 筆」摘要、不印續跑起點，回 SUCCESS')]
    public function test_summary_with_no_accounts_returns_success(): void
    {
        [$vendor, $agent] = $this->vendorWithAgent();

        $this->artisan('vendor:transfer_money_back', ['--vendorId' => $vendor->id, '--agentId' => $agent->id])
            ->expectsOutputToContain('處理完成：共 0 筆，已處理 0，成功 0、失敗 0、略過 0（其中三方查無帳號 0、錢包類型不符 0）')
            ->doesntExpectOutputToContain(TransferMoneyBack::RESUME_MARKER)
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('只撈該代理子樹的帳號：其他子樹的會員、已不存在的會員都不出現在範疇內')]
    public function test_only_accounts_under_agent_subtree_are_fetched(): void
    {
        [$vendor, $agent, $vendorInfo] = $this->vendorWithAgent();
        $this->mockTransferToLocal('1.5');

        $inScope = $this->seedInScopeAccounts($vendorInfo, $agent, 1)->first();

        // 同一商戶（vendor_info）下、掛在別的代理子樹的會員：VWorld 多白標共用商戶的情境
        $otherAgent = Agent::factory()->create();
        $otherAgent->forceFill(['hierarchy_path' => '/1/' . $otherAgent->id])->save();
        $outOfScope = Member::factory()->create(['parent' => $otherAgent->id, 'status' => 1, 'admin' => 0, 'username' => 'tmb_out_' . uniqid()]);
        // 綁的會員已不存在（帳號表殘留）
        DB::table('mega888_accounts')->insert([
            ['vendor_info_id' => $vendorInfo->id, 'username' => 'tmb_o_' . uniqid(), 'member_id' => $outOfScope->id, 'status' => 1, 'admin' => 0],
            ['vendor_info_id' => $vendorInfo->id, 'username' => 'tmb_x_' . uniqid(), 'member_id' => 2_000_000_000, 'status' => 1, 'admin' => 0],
        ]);

        $this->artisan('vendor:transfer_money_back', ['--vendorId' => $vendor->id, '--agentId' => $agent->id])
            ->expectsOutputToContain(sprintf('進度: 1/1, 帳號id: %s', $inScope['account_id']))
            ->doesntExpectOutputToContain($outOfScope->username)
            ->expectsOutputToContain('處理完成：共 1 筆，已處理 1，成功 1、失敗 0、略過 0（其中三方查無帳號 0、錢包類型不符 0），轉回總額(平台幣) 1.5000')
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('帳號超過一批（MEMBER_BATCH）時 member 分批載入：第二批仍找得到、全部成功轉回')]
    public function test_members_are_loaded_in_batches_across_boundary(): void
    {
        [$vendor, $agent, $vendorInfo] = $this->vendorWithAgent();
        $this->mockTransferToLocal('2');
        // 三方已整段 mock，沒有真的在打 API：不放掉每秒配額的話這 11 筆會真的睡掉兩秒
        RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
        RateLimiter::shouldReceive('hit')->andReturn(1);

        $seeded = $this->seedInScopeAccounts($vendorInfo, $agent, 11);
        // 指令依帳號 id desc：最後建立的排第 1、最先建立的排第 11（第二批）
        $first = $seeded->last();
        $last = $seeded->first();

        $this->artisan('vendor:transfer_money_back', ['--vendorId' => $vendor->id, '--agentId' => $agent->id])
            ->expectsOutputToContain(sprintf('進度: 1/11, 帳號id: %s, 用戶: %s', $first['account_id'], $first['member']->username))
            ->expectsOutputToContain(sprintf('進度: 11/11, 帳號id: %s, 用戶: %s', $last['account_id'], $last['member']->username))
            ->expectsOutputToContain('處理完成：共 11 筆，已處理 11，成功 11、失敗 0、略過 0（其中三方查無帳號 0、錢包類型不符 0），轉回總額(平台幣) 22.0000')
            ->doesntExpectOutputToContain(TransferMoneyBack::RESUME_MARKER)
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('--beforeId 只處理 id 小於該值的帳號；--limit 之外確實還有帳號才印「續跑起點 beforeId: <最後處理的帳號 id>」')]
    public function test_before_id_cursor_and_limit_emit_resume_marker_only_when_more_remain(): void
    {
        [$vendor, $agent, $vendorInfo] = $this->vendorWithAgent();
        $this->mockTransferToLocal('0');

        // 帳號 id desc：ids[0] 最新（被 beforeId 排除）、ids[1] ids[2] ids[3] 是範圍內的三筆
        $ids = $this->seedInScopeAccounts($vendorInfo, $agent, 4)->pluck('account_id')->sortDesc()->values();

        // limit=2、範圍內還剩 1 筆 → 印續跑起點 = 第 2 筆的帳號 id
        $this->artisan('vendor:transfer_money_back', [
            '--vendorId' => $vendor->id,
            '--agentId' => $agent->id,
            '--beforeId' => $ids[0],
            '--limit' => 2,
        ])
            ->expectsOutputToContain(sprintf('進度: 1/2, 帳號id: %s', $ids[1]))
            ->expectsOutputToContain('處理完成：共 2 筆，已處理 2')
            ->expectsOutputToContain(TransferMoneyBack::RESUME_MARKER . $ids[2])
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('--limit 剛好等於剩餘筆數：全部跑完，不誤印續跑起點')]
    public function test_limit_equal_to_remaining_does_not_emit_resume_marker(): void
    {
        [$vendor, $agent, $vendorInfo] = $this->vendorWithAgent();
        $this->mockTransferToLocal('0');

        $ids = $this->seedInScopeAccounts($vendorInfo, $agent, 3)->pluck('account_id')->sortDesc()->values();

        $this->artisan('vendor:transfer_money_back', [
            '--vendorId' => $vendor->id,
            '--agentId' => $agent->id,
            '--beforeId' => $ids[0],
            '--limit' => 2,
        ])
            ->expectsOutputToContain('處理完成：共 2 筆，已處理 2')
            ->doesntExpectOutputToContain(TransferMoneyBack::RESUME_MARKER)
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('--memberUsername 只抽指定那一位會員，同子樹其餘會員不動')]
    public function test_single_member_by_username(): void
    {
        [$vendor, $agent, $vendorInfo] = $this->vendorWithAgent();
        $this->mockTransferToLocal('3');

        $target = $this->seedInScopeAccounts($vendorInfo, $agent, 3)[1];

        $this->artisan('vendor:transfer_money_back', [
            '--vendorId' => $vendor->id,
            '--agentId' => $agent->id,
            '--memberUsername' => $target['member']->username,
        ])
            ->expectsOutputToContain('只抽指定會員: ' . $target['member']->username)
            ->expectsOutputToContain(sprintf('進度: 1/1, 帳號id: %s, 用戶: %s', $target['account_id'], $target['member']->username))
            ->expectsOutputToContain('處理完成：共 1 筆，已處理 1，成功 1、失敗 0、略過 0（其中三方查無帳號 0、錢包類型不符 0），轉回總額(平台幣) 3.0000')
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('三方查無此帳號（AccountNotExistsException）：算略過不算失敗，不進失敗名單，整批仍回 SUCCESS')]
    public function test_purged_vendor_account_is_skipped_not_failed(): void
    {
        [$vendor, $agent, $vendorInfo] = $this->vendorWithAgent();
        $this->seedInScopeAccounts($vendorInfo, $agent, 3);

        $this->mock(VendorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getDriver')->andReturn(Mockery::mock(AbstractTransferWallet::class));
        });
        $this->mock(MemberTransferService::class, function (MockInterface $mock): void {
            // Mockery 依定義順序消耗 once() 期望：第 1 位成功、第 2 位在三方已被清除、其餘成功
            // （once() 走 CompositeExpectation::__call，phpstan 沒有 stub，逐行忽略）
            $mock->shouldReceive('transferToLocal')->once()->andReturn(['withdrawal_amount' => '1']); // @phpstan-ignore method.notFound
            $mock->shouldReceive('transferToLocal')->once()->andThrow(new AccountNotExistsException('[Mega888][getBalance] account not exists')); // @phpstan-ignore method.notFound
            $mock->shouldReceive('transferToLocal')->andReturn(['withdrawal_amount' => '1']);
        });

        $this->artisan('vendor:transfer_money_back', ['--vendorId' => $vendor->id, '--agentId' => $agent->id])
            ->expectsOutputToContain('三方查無此帳號，略過')
            ->expectsOutputToContain('處理完成：共 3 筆，已處理 3，成功 2、失敗 0、略過 1（其中三方查無帳號 1、錢包類型不符 0），轉回總額(平台幣) 2.0000')
            ->doesntExpectOutputToContain('失敗 member_id')
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('打三方時不另外包事務：transferToLocal 自己切好的交易邊界不被外層吞掉')]
    public function test_third_party_call_is_not_wrapped_in_an_extra_transaction(): void
    {
        [$vendor, $agent, $vendorInfo] = $this->vendorWithAgent();

        // 測試基底自己用 DatabaseTransactions 包了一層，故以當下層數為基準而非 0
        $baselineLevel = DB::transactionLevel();
        $levelAtThirdPartyCall = null;

        $this->mock(VendorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getDriver')->andReturn(Mockery::mock(AbstractTransferWallet::class));
        });
        $this->mock(MemberTransferService::class, function (MockInterface $mock) use (&$levelAtThirdPartyCall): void {
            $mock->shouldReceive('transferToLocal')->andReturnUsing(function () use (&$levelAtThirdPartyCall): array { // @phpstan-ignore method.notFound
                $levelAtThirdPartyCall = DB::transactionLevel();

                return ['withdrawal_amount' => '1'];
            });
        });

        $this->seedInScopeAccounts($vendorInfo, $agent, 1);

        $this->artisan('vendor:transfer_money_back', ['--vendorId' => $vendor->id, '--agentId' => $agent->id])
            ->assertExitCode(Command::SUCCESS);

        // 指令若把這段包回 DB::transaction，層數會比基準多一層。那會讓 transferToLocal
        // 刻意寫在事務外的交易紀錄與轉丟紀錄一起被回滾，三方已扣款卻查無紀錄
        $this->assertSame($baselineLevel, $levelAtThirdPartyCall);
    }

    /**
     * @return array{0: Vendor, 1: Agent, 2: VendorInfo}
     */
    #[TestDox('會員參加活動中（is_event_wallet）但本次指定非活動商戶：略過，不建 Driver 也不打三方')]
    public function test_event_wallet_member_is_skipped_when_event_v2_not_requested(): void
    {
        [$vendor, $agent, $vendorInfo] = $this->vendorWithAgent();
        // transferToLocal 一旦被呼叫就是做錯對象（Driver 會去拿活動商戶）
        $this->mock(MemberTransferService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('transferToLocal');
        });

        $seeded = $this->seedInScopeAccounts($vendorInfo, $agent, 1)->first();
        $this->joinEventV2($seeded['member']);

        $this->artisan('vendor:transfer_money_back', [
            '--vendorId' => $vendor->id,
            '--agentId' => $agent->id,
            '--eventV2' => 0,
        ])
            ->expectsOutputToContain('會員錢包類型與本次指定不符')
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('沒參加活動的會員遇上 --eventV2=1：同樣略過，摘要計入「錢包類型不符」')]
    public function test_plain_member_is_skipped_when_event_v2_requested(): void
    {
        [$vendor, $agent] = $this->vendorWithAgent();
        $eventVendorInfo = VendorInfo::factory()->create(['vendor_id' => $vendor->id, 'type' => 2]);
        AgentVendor::create([
            'agent_id' => $agent->id,
            'vendor_id' => $vendor->id,
            'vendor_info_id' => $eventVendorInfo->id,
            'status' => 1,
            'self_status' => 1,
            'admin' => 0,
        ]);
        $this->mock(MemberTransferService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('transferToLocal');
        });
        $this->seedInScopeAccounts($eventVendorInfo, $agent, 1);

        $this->artisan('vendor:transfer_money_back', [
            '--vendorId' => $vendor->id,
            '--agentId' => $agent->id,
            '--eventV2' => 1,
        ])
            ->expectsOutputToContain('錢包類型不符 1')
            ->assertExitCode(Command::SUCCESS);
    }

    /** 讓會員成為 is_event_wallet：有一筆進行中的活動參加紀錄 */
    private function joinEventV2(Member $member): void
    {
        $detail = EventV2Detail::factory()->create(['event_v2_id' => 1, 'agent_id' => $this->agentIdOf($member)]);

        // EventV2UserJoinObserver 建立時要 UserContext（指令情境沒有），這裡只需要「有一筆參加紀錄」
        EventV2UserJoin::withoutEvents(fn () => EventV2UserJoin::create([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $member->id,
            'order_id' => 0,
            'reward' => 0,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
        ]));
    }

    private function agentIdOf(Member $member): int
    {
        return (int) $member->parent;
    }

    #[TestDox('後台按下中斷：讀到 cancel_requested_at 就停下，印續跑起點並正常收尾（不算失敗）')]
    public function test_cancel_requested_stops_the_run(): void
    {
        [$vendor, $agent, $vendorInfo] = $this->vendorWithAgent();
        $this->mockTransferToLocal('0');

        $ids = $this->seedInScopeAccounts($vendorInfo, $agent, 3)->pluck('account_id')->sortDesc()->values();
        // 續跑那一輪被中斷：一筆都沒處理也要沿用本次起點，否則下一輪會退回從頭
        $this->runningOperationLog(cancelRequested: true);

        $this->artisan('vendor:transfer_money_back', [
            '--vendorId' => $vendor->id,
            '--agentId' => $agent->id,
            '--beforeId' => $ids[0],
        ])
            ->expectsOutputToContain('收到後台中斷要求，已停在第 0 筆，剩餘 2 筆未處理')
            ->expectsOutputToContain(TransferMoneyBack::RESUME_MARKER . $ids[0])
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('未按中斷：旗標為空就一路跑完，不受輪詢影響')]
    public function test_running_operation_without_cancel_flag_completes(): void
    {
        [$vendor, $agent, $vendorInfo] = $this->vendorWithAgent();
        $this->mockTransferToLocal('0');
        $this->seedInScopeAccounts($vendorInfo, $agent, 2);
        $this->runningOperationLog(cancelRequested: false);

        $this->artisan('vendor:transfer_money_back', ['--vendorId' => $vendor->id, '--agentId' => $agent->id])
            ->expectsOutputToContain('處理完成：共 2 筆，已處理 2')
            ->doesntExpectOutputToContain('收到後台中斷要求')
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('每筆查詢都計入該遊戲商的每秒預算（三方限流用）')]
    public function test_each_account_consumes_rate_limit_budget(): void
    {
        [$vendor, $agent, $vendorInfo] = $this->vendorWithAgent();
        $this->mockTransferToLocal('0');
        $this->seedInScopeAccounts($vendorInfo, $agent, 3);

        $this->artisan('vendor:transfer_money_back', ['--vendorId' => $vendor->id, '--agentId' => $agent->id])
            ->assertExitCode(Command::SUCCESS);

        $limiterKey = 'transfer_money_back:' . $vendor->id;
        $this->assertSame(3, RateLimiter::attempts($limiterKey));
        // 配額窗口必須是 1 秒：hit() 的衰減秒數預設 60，漏傳會讓上限變成「每分鐘 5 筆」
        $this->assertLessThanOrEqual(1, RateLimiter::availableIn($limiterKey));
    }

    #[TestDox('每秒配額以遊戲商為界：兩商同時抽分各記各的，不會互相吃掉名額')]
    public function test_rate_limit_budget_is_per_vendor(): void
    {
        [$vendorA, $agentA, $vendorInfoA] = $this->vendorWithAgent();
        [$vendorB, $agentB, $vendorInfoB] = $this->vendorWithAgent();
        $this->mockTransferToLocal('0');
        $this->seedInScopeAccounts($vendorInfoA, $agentA, 3);
        $this->seedInScopeAccounts($vendorInfoB, $agentB, 2);

        $this->artisan('vendor:transfer_money_back', ['--vendorId' => $vendorA->id, '--agentId' => $agentA->id])
            ->assertExitCode(Command::SUCCESS);
        $this->artisan('vendor:transfer_money_back', ['--vendorId' => $vendorB->id, '--agentId' => $agentB->id])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(3, RateLimiter::attempts('transfer_money_back:' . $vendorA->id));
        $this->assertSame(2, RateLimiter::attempts('transfer_money_back:' . $vendorB->id));
    }

    #[TestDox('--perSecond 夾回 1 ~ MAX_PER_SECOND：超過上限、零與負數都不成立')]
    public function test_per_second_is_clamped(): void
    {
        $this->assertSame(TransferMoneyBack::MAX_PER_SECOND, TransferMoneyBack::resolvePerSecond(99));
        $this->assertSame(TransferMoneyBack::MAX_PER_SECOND, TransferMoneyBack::resolvePerSecond(TransferMoneyBack::MAX_PER_SECOND));
        $this->assertSame(1, TransferMoneyBack::resolvePerSecond(0));
        $this->assertSame(1, TransferMoneyBack::resolvePerSecond(-3));
        $this->assertSame(3, TransferMoneyBack::resolvePerSecond(3));
    }

    /**
     * 模擬後台觸發：建一筆執行中的操作紀錄，並把 id 放進 Context 供指令輪詢中斷旗標
     */
    private function runningOperationLog(bool $cancelRequested): AdminOperationLog
    {
        $log = AdminOperationLog::create([
            'user_id' => 1,
            'operation' => 'vendor:transfer_money_back',
            'status' => AdminOperationStatusEnum::RUNNING,
            'started_at' => now(),
            'cancel_requested_at' => $cancelRequested ? now() : null,
        ]);

        Context::addHidden(ContextKeyEnum::ADMIN_OPERATION_LOG_ID->value, $log->id);

        return $log;
    }

    private function vendorWithAgent(): array
    {
        // factory 的隨機 tname 沒有對應 xxx_accounts 表；借 migration 已建的 mega888_accounts（工單對象）
        $vendor = Vendor::factory()->create(['tname' => 'mega888']);
        $agent = Agent::factory()->create();
        // 指令以 agents.hierarchy_path 前綴切子樹；factory 不填此欄，補成 Admin 直屬
        $agent->forceFill(['hierarchy_path' => '/1/' . $agent->id])->save();
        $vendorInfo = VendorInfo::factory()->create(['vendor_id' => $vendor->id]);
        AgentVendor::create([
            'agent_id' => $agent->id,
            'vendor_id' => $vendor->id,
            'vendor_info_id' => $vendorInfo->id,
            'status' => 1,
            'self_status' => 1,
            'admin' => 0,
        ]);

        return [$vendor, $agent, $vendorInfo];
    }

    /**
     * 三方轉帳整段 mock：不打 API、不動錢包，每人回固定轉回金額
     */
    private function mockTransferToLocal(string $withdrawalAmount): void
    {
        $this->mock(VendorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getDriver')->andReturn(Mockery::mock(AbstractTransferWallet::class));
        });
        $this->mock(MemberTransferService::class, function (MockInterface $mock) use ($withdrawalAmount): void {
            $mock->shouldReceive('transferToLocal')->andReturn(['withdrawal_amount' => $withdrawalAmount]);
        });
    }

    /**
     * 在代理子樹下建 $count 個會員並各綁一個啟用中的 Mega888 帳號；依建立順序回傳。
     *
     * @return Collection<int, array{member: Member, account_id: int}>
     */
    private function seedInScopeAccounts(VendorInfo $vendorInfo, Agent $agent, int $count): Collection
    {
        $tag = uniqid();
        $members = Member::factory()
            ->count($count)
            ->state(new Sequence(fn (Sequence $sequence): array => ['username' => sprintf('tmb%s_%d', $tag, $sequence->index)]))
            ->create(['parent' => $agent->id, 'status' => 1, 'admin' => 0]);

        // ponytail: 單純塞 fixture，無對應 Model 專屬邏輯，走 DB::table 批次寫入
        DB::table('mega888_accounts')->insert(
            $members->map(fn (Member $member): array => [
                'vendor_info_id' => $vendorInfo->id,
                'username' => 'acc_' . $member->username,
                'member_id' => $member->id,
                'status' => 1,
                'admin' => 0,
            ])->all(),
        );

        $accountIds = DB::table('mega888_accounts')
            ->whereIn('member_id', $members->pluck('id'))
            ->pluck('id', 'member_id');

        return $members->map(fn (Member $member): array => [
            'member' => $member,
            'account_id' => (int) $accountIds[$member->id],
        ]);
    }
}
