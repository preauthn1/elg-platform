<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Exceptions\ApiException;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Member;
use App\Models\MemberGroup;
use App\Models\VipContent;
use App\Services\AgentService;
use App\Services\MemberLimitResolver;
use App\Services\MemberRestrictionGuard;
use App\Services\MemberService;
use App\Services\WithdrawalService;
use Mockery;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * MemberLimitResolver 測試（會員群組限額解析）。
 *
 * 合併模型（全取嚴／交集）：有設定的層全部同時生效，下限取最大、上限取最小：
 *   - 單筆：銀行(渠道) ∩ 群組 ∩ VIP 上限（VIP 只有上限、無下限）。
 *   - 每日金額（無銀行層）：群組 ∩ VIP 取小。
 *   - 每日次數（無銀行層）：base = 代理 ∩ 群組 取低；VIP 次數為額外加總（base + VIP，非取嚴）。
 *   - 存款：群組 ∩ 渠道（無 VIP）。各層未設（-1/null）不貢獻。
 *
 * 提款擋單由 WithdrawalService 三隻方法（checkWithdrawalTimes / checkDailyWithdrawalAmountLimit /
 * checkWithdrawalAmountLimit）內以「換值、沿用原本比較」的方式接管——原方法 body 不重寫，僅前置換值。
 * 本檔涵蓋 resolver 的值計算方法（withdrawCountBaseLimit / withdrawDailyAmountLimit / withdrawFloorAmount /
 * withdrawCeilingAmount）與整合後的 WithdrawalService 三隻方法。
 *
 * php artisan test tests/Unit/Services/MemberLimitResolverTest.php
 */
class MemberLimitResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = 'mlr-' . uniqid('', true) . '.local';
    }

    private function resolver(): MemberLimitResolver
    {
        return app(MemberLimitResolver::class);
    }

    /**
     * 以 mock 的 MemberService / AgentService 建 resolver。
     */
    private function resolverWithMocks(?MemberService $memberService = null, ?AgentService $agentService = null): MemberLimitResolver
    {
        return new MemberLimitResolver(
            $memberService ?? Mockery::mock(MemberService::class),
            $agentService ?? Mockery::mock(AgentService::class),
        );
    }

    /**
     * AgentService mock：getAgentSettings（hasVip / 次數用）＋ getWithdrawalAmountLimit（銀行 fallback 用）。
     */
    private function agentServiceMock(array $settings = [], array $withdrawalLimit = ['min_amount' => 0, 'max_amount' => 0]): AgentService
    {
        $mock = Mockery::mock(AgentService::class);
        $mock->allows('getAgentSettings')->andReturn($settings);
        $mock->allows('getWithdrawalAmountLimit')->andReturn($withdrawalLimit);

        return $mock;
    }

    /**
     * MemberService mock：VIP 內容與當日累計。
     */
    private function memberServiceMock(array $opts = []): MemberService
    {
        $mock = Mockery::mock(MemberService::class);
        $mock->allows('getVipContent')->andReturn($opts['vip'] ?? null);
        $mock->allows('getTodayWithdrawalAmount')->andReturn($opts['todayAmount'] ?? '0');
        $mock->allows('getTodayWithdrawalCount')->andReturn($opts['todayCount'] ?? 0);
        $mock->allows('getTodayDepositAmount')->andReturn($opts['todayDepositAmount'] ?? '0');
        $mock->allows('getTodayDepositCount')->andReturn($opts['todayDepositCount'] ?? 0);
        $mock->allows('getTodayMemberTransferOutAmount')->andReturn($opts['todayTransferAmount'] ?? '0');
        $mock->allows('getTodayMemberTransferOutCount')->andReturn($opts['todayTransferCount'] ?? 0);

        return $mock;
    }

    private function agentServiceWithoutVip(): AgentService
    {
        return $this->agentServiceMock([]);
    }

    /**
     * 建帶 mock 的 resolver（提款接管方法測試用）。
     */
    private function makeResolver(array $settings = [], array $agentLimit = ['min_amount' => 0, 'max_amount' => 0], array $msOpts = []): MemberLimitResolver
    {
        return $this->resolverWithMocks(
            $this->memberServiceMock($msOpts),
            $this->agentServiceMock($settings, $agentLimit),
        );
    }

    private function makeBank(mixed $min, mixed $max, mixed $rate = 1): Bank
    {
        $bank = new Bank;
        $bank->min_amount = $min;
        $bank->max_amount = $max;
        $bank->rate = $rate;

        return $bank;
    }

    private function makeVip(mixed $amountLimit = -1, mixed $timesLimit = -1, mixed $tax = 0): VipContent
    {
        $vip = new VipContent;
        $vip->withdrawal_amount_limit = $amountLimit;
        $vip->withdrawal_times_limit = $timesLimit;
        $vip->tax = $tax;

        return $vip;
    }

    private function createGroup(int $agentId, array $overrides = []): MemberGroup
    {
        return MemberGroup::create(array_merge([
            'agent_id' => $agentId,
            'name' => 'test-group-' . uniqid('', true),
            'status' => 1,
            'default_setting' => 0,
        ], $overrides));
    }

    /**
     * 建「代理 + 群組 + 已指派會員」三件套（會員掛在群組所屬的同一個代理下）。
     *
     * @return array{0: MemberGroup, 1: Member}
     */
    private function createGroupWithMember(array $groupOverrides = []): array
    {
        $agent = $this->createAgentAndDomain();
        $group = $this->createGroup($agent->id, $groupOverrides);
        $member = $this->createMember($agent);
        $member->update(['member_group_id' => $group->id]);

        return [$group, $member->refresh()];
    }

    // ==================== resolveGroup ====================

    #[TestDox('無指派且代理無預設群組時回傳 null')]
    public function test_resolve_group_returns_null_without_group(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $this->assertNull($this->resolver()->resolveGroup($member));
    }

    #[TestDox('會員指派的群組已停用時回傳 null（不再回退預設群組）')]
    public function test_resolve_group_returns_null_when_assigned_group_disabled(): void
    {
        $agent = $this->createAgentAndDomain();
        $disabled = $this->createGroup($agent->id, ['status' => 0]);
        $this->createGroup($agent->id, ['default_setting' => 1]);
        $member = $this->createMember($agent);
        $member->update(['member_group_id' => $disabled->id]);

        $this->assertNull($this->resolver()->resolveGroup($member->refresh()));
    }

    #[TestDox('會員指派的群組有效時優先於預設群組')]
    public function test_resolve_group_prefers_member_assigned_group(): void
    {
        $agent = $this->createAgentAndDomain();
        $this->createGroup($agent->id, ['default_setting' => 1]);
        $assigned = $this->createGroup($agent->id);
        $member = $this->createMember($agent);
        $member->update(['member_group_id' => $assigned->id]);

        $resolved = $this->resolver()->resolveGroup($member->refresh());

        $this->assertSame($assigned->id, $resolved->id);
    }

    #[TestDox('usesMemberGroupLimit：無群組無 VIP 為假，指派群組後為真')]
    public function test_uses_member_group_limit(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        // 無群組無 VIP → false
        $this->assertFalse(
            $this->resolverWithMocks($this->memberServiceMock(), $this->agentServiceWithoutVip())->usesMemberGroupLimit($member)
        );

        // 指派群組後 → true（用新 resolver 避開 groupCache）
        $group = $this->createGroup($agent->id);
        $member->update(['member_group_id' => $group->id]);
        $this->assertTrue(
            $this->resolverWithMocks($this->memberServiceMock(), $this->agentServiceWithoutVip())->usesMemberGroupLimit($member->refresh())
        );
    }

    // ==================== 存款單筆（群組獨佔，無 VIP）====================

    #[TestDox('無群組時 checkDeposit 完全 no-op')]
    public function test_check_deposit_noop_without_group(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $this->resolver()->checkDeposit($member, '0.0001');

        $this->assertTrue(true);
    }

    #[TestDox('存款限額為 -1（不限制）時不擋任何金額')]
    public function test_check_deposit_noop_when_unlimited(): void
    {
        [, $member] = $this->createGroupWithMember();

        $this->resolver()->checkDeposit($member, '99999999');

        $this->assertTrue(true);
    }

    #[TestDox('存款低於群組下限時拋出 ApiException')]
    public function test_check_deposit_throws_below_min(): void
    {
        [, $member] = $this->createGroupWithMember(['deposit_min' => 100]);

        $this->expectException(ApiException::class);
        $this->resolver()->checkDeposit($member, '99.9999');
    }

    #[TestDox('存款高於群組上限時拋出 ApiException')]
    public function test_check_deposit_throws_above_max(): void
    {
        [, $member] = $this->createGroupWithMember(['deposit_max' => 500]);

        $this->expectException(ApiException::class);
        $this->resolver()->checkDeposit($member, '500.0001');
    }

    #[TestDox('存款金額等於上下限邊界時通過')]
    public function test_check_deposit_passes_on_boundary(): void
    {
        [, $member] = $this->createGroupWithMember(['deposit_min' => 100, 'deposit_max' => 500]);
        $resolver = $this->resolver();

        $resolver->checkDeposit($member, '100');
        $resolver->checkDeposit($member, '500');

        $this->assertTrue(true);
    }

    #[TestDox('存款每日金額累計超過群組上限時拋出 ApiException（無 VIP 概念）')]
    public function test_check_deposit_daily_amount_exceeded(): void
    {
        [, $member] = $this->createGroupWithMember(['deposit_daily_amount' => 1000]);

        $memberService = $this->memberServiceMock(['todayDepositAmount' => '1000.0000']);

        $this->expectException(ApiException::class);
        $this->resolverWithMocks($memberService)->checkDepositDailyAmount($member, '0.0001');
    }

    #[TestDox('存款每日次數已達群組上限時拋出 ApiException（與金額無關，供無金額渠道防繞過）')]
    public function test_check_deposit_daily_count_exceeded(): void
    {
        [, $member] = $this->createGroupWithMember(['deposit_daily_count' => 3]);

        $memberService = $this->memberServiceMock(['todayDepositCount' => 3]);

        $this->expectException(ApiException::class);
        $this->resolverWithMocks($memberService)->checkDepositDailyCount($member);
    }

    #[TestDox('無群組時 checkDepositDailyAmount / checkDepositDailyCount 完全 no-op')]
    public function test_check_deposit_daily_noop_without_group(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $resolver = $this->resolver();

        $resolver->checkDepositDailyAmount($member, '99999999');
        $resolver->checkDepositDailyCount($member);

        $this->assertTrue(true);
    }

    // ==================== 會員轉帳（群組獨佔，無 VIP／無銀行層；限額綁轉出方）====================

    #[TestDox('無群組時 checkMemberTransfer 完全 no-op')]
    public function test_check_member_transfer_noop_without_group(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $this->resolver()->checkMemberTransfer($member, '0.0001');

        $this->assertTrue(true);
    }

    #[TestDox('轉帳限額為 -1（不限制）時不擋任何金額')]
    public function test_check_member_transfer_noop_when_unlimited(): void
    {
        [, $member] = $this->createGroupWithMember();

        $this->resolver()->checkMemberTransfer($member, '99999999');

        $this->assertTrue(true);
    }

    #[TestDox('轉帳低於群組單筆下限時拋出 ApiException')]
    public function test_check_member_transfer_throws_below_min(): void
    {
        [, $member] = $this->createGroupWithMember(['transfer_min' => 100]);

        $this->expectException(ApiException::class);
        $this->resolver()->checkMemberTransfer($member, '99.9999');
    }

    #[TestDox('轉帳高於群組單筆上限時拋出 ApiException')]
    public function test_check_member_transfer_throws_above_max(): void
    {
        [, $member] = $this->createGroupWithMember(['transfer_max' => 500]);

        $this->expectException(ApiException::class);
        $this->resolver()->checkMemberTransfer($member, '500.0001');
    }

    #[TestDox('轉帳金額等於單筆上下限邊界時通過')]
    public function test_check_member_transfer_passes_on_boundary(): void
    {
        [, $member] = $this->createGroupWithMember(['transfer_min' => 100, 'transfer_max' => 500]);
        $resolver = $this->resolver();

        $resolver->checkMemberTransfer($member, '100');
        $resolver->checkMemberTransfer($member, '500');

        $this->assertTrue(true);
    }

    #[TestDox('轉帳每日累計金額超過群組上限時拋出 ApiException')]
    public function test_check_member_transfer_daily_amount_exceeded(): void
    {
        [, $member] = $this->createGroupWithMember(['transfer_daily_amount' => 1000]);

        $memberService = $this->memberServiceMock(['todayTransferAmount' => '1000.0000']);

        $this->expectException(ApiException::class);
        $this->resolverWithMocks($memberService)->checkMemberTransferDailyAmount($member, '0.0001');
    }

    #[TestDox('轉帳每日累計金額恰好等於群組上限時通過（超過才擋）')]
    public function test_check_member_transfer_daily_amount_passes_on_boundary(): void
    {
        [, $member] = $this->createGroupWithMember(['transfer_daily_amount' => 1000]);

        $memberService = $this->memberServiceMock(['todayTransferAmount' => '900.0000']);

        $this->resolverWithMocks($memberService)->checkMemberTransferDailyAmount($member, '100');

        $this->assertTrue(true);
    }

    #[TestDox('轉帳每日次數已達群組上限時拋出 ApiException（與金額無關）')]
    public function test_check_member_transfer_daily_count_exceeded(): void
    {
        [, $member] = $this->createGroupWithMember(['transfer_daily_count' => 3]);

        $memberService = $this->memberServiceMock(['todayTransferCount' => 3]);

        $this->expectException(ApiException::class);
        $this->resolverWithMocks($memberService)->checkMemberTransferDailyCount($member);
    }

    #[TestDox('群組每日轉帳次數設 0 時當日一律不可轉帳')]
    public function test_check_member_transfer_daily_count_zero_blocks_all(): void
    {
        [, $member] = $this->createGroupWithMember(['transfer_daily_count' => 0]);

        $memberService = $this->memberServiceMock(['todayTransferCount' => 0]);

        $this->expectException(ApiException::class);
        $this->resolverWithMocks($memberService)->checkMemberTransferDailyCount($member);
    }

    #[TestDox('無群組時 checkMemberTransferDailyAmount / checkMemberTransferDailyCount 完全 no-op')]
    public function test_check_member_transfer_daily_noop_without_group(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $resolver = $this->resolver();

        $resolver->checkMemberTransferDailyAmount($member, '99999999');
        $resolver->checkMemberTransferDailyCount($member);

        $this->assertTrue(true);
    }

    // ==================== 提款單筆 WithdrawalService::checkWithdrawalAmountLimit（換值沿用原比較；銀行 ∩ 群組 ∩ VIP 全取嚴）====================

    /**
     * 執行 checkWithdrawalAmountLimit（透過 WithdrawalService，測群組/VIP 換值後沿用原本 9997 比較）。
     * $agentLimit 預設用一個較大的技術上限（而非 0/0），避免撞到「銀行+代理皆 0」時 0 被當成真實上限的既有邊界（顯示端亦同）。
     */
    private function runCheckWithdrawalAmountLimit(Member $member, Bank $bank, $amount, array $settings = [], array $agentLimit = ['min_amount' => 0, 'max_amount' => 0], array $msOpts = []): void
    {
        $memberService = $this->memberServiceMock($msOpts);
        $agentService = $this->agentServiceMock($settings, $agentLimit);
        $service = new WithdrawalService($memberService, $agentService, new MemberLimitResolver($memberService, $agentService), new MemberRestrictionGuard);

        $service->checkWithdrawalAmountLimit($member->agent, $bank, $amount, $member);
    }

    #[TestDox('提款低於群組下限時拋出 ApiException（2072 對應的 9997）')]
    public function test_withdrawal_amount_limit_below_group_floor(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_min' => 100]);

        $this->expectException(ApiException::class);
        $this->runCheckWithdrawalAmountLimit($member, $this->makeBank(0, 0), '99.9999');
    }

    #[TestDox('提款高於群組上限時拋出 ApiException')]
    public function test_withdrawal_amount_limit_above_group_ceiling(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_max' => 1000]);

        $this->expectException(ApiException::class);
        $this->runCheckWithdrawalAmountLimit($member, $this->makeBank(0, 100000), '1000.0001');
    }

    #[TestDox('提款金額落在群組範圍內時通過')]
    public function test_withdrawal_amount_limit_passes_within_group_range(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_min' => 100, 'withdraw_max' => 1000]);

        $this->runCheckWithdrawalAmountLimit($member, $this->makeBank(0, 100000), '500');

        $this->assertTrue(true);
    }

    #[TestDox('銀行＝技術上限：群組 100000 放寬無效，超過銀行 10000 照擋')]
    public function test_withdrawal_amount_limit_bank_technical_cap_blocks_above_bank(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_max' => 100000]);

        // 群組 100000 > 銀行 10000：上限取小 = 10000（渠道能力），50000 擋下
        $this->expectException(ApiException::class);
        $this->runCheckWithdrawalAmountLimit($member, $this->makeBank(0, 10000), '50000');
    }

    #[TestDox('銀行＝技術上限：群組 100000、銀行 10000，提 10000 邊界通過')]
    public function test_withdrawal_amount_limit_bank_technical_cap_boundary_passes(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_max' => 100000]);

        $this->runCheckWithdrawalAmountLimit($member, $this->makeBank(0, 10000), '10000');

        $this->assertTrue(true);
    }

    #[TestDox('群組比銀行嚴時生效：群組 5000、銀行 10000，無 VIP 提 8000 擋下')]
    public function test_withdrawal_amount_limit_group_ceiling_blocks_without_vip(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_max' => 5000]);

        $this->expectException(ApiException::class);
        $this->runCheckWithdrawalAmountLimit($member, $this->makeBank(0, 10000), '8000');
    }

    #[TestDox('全取嚴：VIP 較寬不放寬群組（群組 5000 ∩ VIP 10000000 ∩ 銀行 10000 = 5000），提 8000 擋下')]
    public function test_withdrawal_amount_limit_vip_does_not_relax_group(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_max' => 5000]);

        $this->expectException(ApiException::class);
        $this->runCheckWithdrawalAmountLimit(
            $member,
            $this->makeBank(0, 10000),
            '8000',
            [AgentCustomizedSettingKeyEnum::VIP_VISIBLE => 1],
            ['min_amount' => 0, 'max_amount' => 0],
            ['vip' => $this->makeVip(amountLimit: 10000000)],
        );
    }

    #[TestDox('全取嚴：VIP 比銀行嚴時 VIP 生效（VIP 8000 ∩ 銀行 10000 = 8000），提 9000 擋下')]
    public function test_withdrawal_amount_limit_vip_tightens_below_bank(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_max' => -1]);

        $this->expectException(ApiException::class);
        $this->runCheckWithdrawalAmountLimit(
            $member,
            $this->makeBank(0, 10000),
            '9000',
            [AgentCustomizedSettingKeyEnum::VIP_VISIBLE => 1],
            ['min_amount' => 0, 'max_amount' => 0],
            ['vip' => $this->makeVip(amountLimit: 8000)],
        );
    }

    #[TestDox('下限也取嚴：銀行下限 100 > 群組下限 50，提 60 擋下（渠道最低處理金額）')]
    public function test_withdrawal_amount_limit_bank_floor_caps_group_floor(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_min' => 50]);

        $this->expectException(ApiException::class);
        $this->runCheckWithdrawalAmountLimit($member, $this->makeBank(100, 0), '60');
    }

    #[TestDox('VIP 無下限：下限仍由群組提供，低於群組下限照擋')]
    public function test_withdrawal_amount_limit_vip_has_no_floor_group_floor_applies(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_min' => 100, 'withdraw_max' => -1]);

        $this->expectException(ApiException::class);
        $this->runCheckWithdrawalAmountLimit(
            $member,
            $this->makeBank(0, 0),
            '50',
            [AgentCustomizedSettingKeyEnum::VIP_VISIBLE => 1],
            ['min_amount' => 0, 'max_amount' => 0],
            ['vip' => $this->makeVip(amountLimit: 10000000)],
        );
    }

    #[TestDox('單筆比較走 point 換算 amount：rate=2、群組上限 1000(point)→amount 500，amount 600 擋下')]
    public function test_withdrawal_amount_limit_uses_point_with_rate(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_max' => 1000]);

        $this->expectException(ApiException::class);
        $this->runCheckWithdrawalAmountLimit($member, $this->makeBank(0, 100000, 2), '600');
    }

    #[TestDox('rate=2、群組上限 1000(point)→amount 500，amount 500 邊界通過')]
    public function test_withdrawal_amount_limit_point_boundary_passes(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_max' => 1000]);

        $this->runCheckWithdrawalAmountLimit($member, $this->makeBank(0, 100000, 2), '500');

        $this->assertTrue(true);
    }

    #[TestDox('無群組會員：checkWithdrawalAmountLimit 沿用原本銀行/代理判斷，行為零變')]
    public function test_withdrawal_amount_limit_ungrouped_member_uses_original_logic(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $this->expectException(ApiException::class);
        $this->runCheckWithdrawalAmountLimit($member, $this->makeBank(0, 10000), '10000.01');
    }

    // ==================== 提款每日金額 WithdrawalService::checkDailyWithdrawalAmountLimit（換值沿用原比較；群組 ∩ VIP 取小）====================

    /**
     * 執行 checkDailyWithdrawalAmountLimit（透過 WithdrawalService，測群組/VIP 換值後沿用原本 9990 比較）。
     */
    private function runCheckDailyWithdrawalAmountLimit(Member $member, Bank $bank, $amount, array $settings = [], array $msOpts = []): void
    {
        $memberService = $this->memberServiceMock($msOpts);
        $agentService = $this->agentServiceMock($settings);
        $service = new WithdrawalService($memberService, $agentService, new MemberLimitResolver($memberService, $agentService), new MemberRestrictionGuard);

        $service->checkDailyWithdrawalAmountLimit($member, $bank, $amount, $settings);
    }

    #[TestDox('每日金額全取嚴：VIP 較寬不放寬群組（群組 1000 ∩ VIP 10000000 = 1000），累計 900 再提 200 擋下')]
    public function test_daily_withdrawal_amount_limit_vip_does_not_relax_group(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_amount' => 1000]);

        $this->expectException(ApiException::class);
        $this->runCheckDailyWithdrawalAmountLimit(
            $member,
            $this->makeBank(0, 0),
            '200',
            [AgentCustomizedSettingKeyEnum::VIP_VISIBLE => 1],
            ['vip' => $this->makeVip(amountLimit: 10000000), 'todayAmount' => '900.0000'],
        );
    }

    #[TestDox('每日金額全取嚴：VIP 比群組嚴時 VIP 生效（群組 10000 ∩ VIP 1000 = 1000），累計 900 再提 200 擋下')]
    public function test_daily_withdrawal_amount_limit_vip_tightens_group(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_amount' => 10000]);

        $this->expectException(ApiException::class);
        $this->runCheckDailyWithdrawalAmountLimit(
            $member,
            $this->makeBank(0, 0),
            '200',
            [AgentCustomizedSettingKeyEnum::VIP_VISIBLE => 1],
            ['vip' => $this->makeVip(amountLimit: 1000), 'todayAmount' => '900.0000'],
        );
    }

    #[TestDox('每日金額：無 VIP 時群組每日上限累計超過即擋（9990）')]
    public function test_daily_withdrawal_amount_limit_group_exceeded(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_amount' => 1000]);

        $this->expectException(ApiException::class);
        $this->runCheckDailyWithdrawalAmountLimit($member, $this->makeBank(0, 0), '100.0001', [], ['todayAmount' => '900.0000']);
    }

    #[TestDox('每日金額：有群組但群組與 VIP 皆未設每日上限時不擋（原「未開 VIP 即 return」閘門正確跳過）')]
    public function test_daily_withdrawal_amount_limit_unlimited_when_neither_set(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_amount' => -1]);

        $this->runCheckDailyWithdrawalAmountLimit($member, $this->makeBank(0, 0), '99999999');

        $this->assertTrue(true);
    }

    #[TestDox('無群組會員：checkDailyWithdrawalAmountLimit 沿用原本純 VIP 判斷，行為零變（未開 VIP 直接放行）')]
    public function test_daily_withdrawal_amount_limit_ungrouped_member_uses_original_logic(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $this->runCheckDailyWithdrawalAmountLimit($member, $this->makeBank(0, 0), '99999999');

        $this->assertTrue(true);
    }

    // ==================== 提款每日次數（WithdrawalService::checkWithdrawalTimes 內換值沿用原檢查）====================

    /**
     * 建帶 mock 的 WithdrawalService（每日次數經原方法 checkWithdrawalTimes 走群組換值路徑測試）。
     */
    private function makeWithdrawalService(array $settings, array $msOpts = []): WithdrawalService
    {
        $memberService = $this->memberServiceMock($msOpts);
        $agentService = $this->agentServiceMock($settings);

        return new WithdrawalService($memberService, $agentService, new MemberLimitResolver($memberService, $agentService), new MemberRestrictionGuard);
    }

    #[TestDox('每日次數：base 取低(代理10 ∩ 群組2)=2、VIP 5 額外加總 → 上限 7，已提 6 次通過')]
    public function test_withdrawal_times_vip_additive_on_group_base(): void
    {
        // 代理 10、群組 2、VIP 5：base=min(10,2)=2、+VIP 5=7
        // 若誤做全取嚴 min(10,2,5)=2 則 6 次會被擋；取低+額外=7 則 6 次放行 → 驗證 VIP 為額外加總
        [, $member] = $this->createGroupWithMember(['withdraw_daily_count' => 2]);

        $settings = [
            AgentCustomizedSettingKeyEnum::VIP_VISIBLE => 1,
            AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT => 10,
        ];

        $this->makeWithdrawalService($settings, ['vip' => $this->makeVip(timesLimit: 5), 'todayCount' => 6])
            ->checkWithdrawalTimes($member, $settings);

        $this->assertTrue(true);
    }

    #[TestDox('每日次數：base 取低 2 + VIP 5 = 7，已提 7 次即擋')]
    public function test_withdrawal_times_blocks_at_base_plus_vip(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_count' => 2]);

        $settings = [
            AgentCustomizedSettingKeyEnum::VIP_VISIBLE => 1,
            AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT => 10,
        ];

        $this->expectException(ApiException::class);
        $this->makeWithdrawalService($settings, ['vip' => $this->makeVip(timesLimit: 5), 'todayCount' => 7])
            ->checkWithdrawalTimes($member, $settings);
    }

    #[TestDox('每日次數：base 取低(代理10 ∩ 群組2)=2（無 VIP），已提 2 次即擋')]
    public function test_withdrawal_times_group_tightens_agent(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_count' => 2]);

        $settings = [AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT => 10];

        $this->expectException(ApiException::class);
        $this->makeWithdrawalService($settings, ['todayCount' => 2])
            ->checkWithdrawalTimes($member, $settings);
    }

    #[TestDox('每日次數：群組設 0（禁止提款）時直接擋（原 >0 慣例無法表達 0）')]
    public function test_withdrawal_times_group_zero_blocks(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_count' => 0]);

        $settings = [AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT => 10];

        $this->expectException(ApiException::class);
        $this->makeWithdrawalService($settings, ['todayCount' => 0])
            ->checkWithdrawalTimes($member, $settings);
    }

    // ==================== 銀行白名單 ====================

    #[TestDox('白名單開關關閉時回傳 null（不過濾）')]
    public function test_bank_whitelist_null_when_switch_off(): void
    {
        [, $member] = $this->createGroupWithMember(['bank_whitelist_enabled' => 0]);

        $this->assertNull($this->resolver()->bankWhitelist($member));
    }

    #[TestDox('白名單開關開啟但未勾選任何帳號時回傳空陣列（完全不可用）')]
    public function test_bank_whitelist_empty_array_when_switch_on_without_accounts(): void
    {
        [, $member] = $this->createGroupWithMember(['bank_whitelist_enabled' => 1]);

        $this->assertSame([], $this->resolver()->bankWhitelist($member));
    }

    #[TestDox('白名單開關開啟時回傳勾選的 bank_accounts.id 清單')]
    public function test_bank_whitelist_returns_checked_account_ids(): void
    {
        [$group, $member] = $this->createGroupWithMember(['bank_whitelist_enabled' => 1]);
        $bankAccount = BankAccount::factory()->create(['order_type_id' => 0, 'is_deposit' => 1]);
        $group->bankAccounts()->attach($bankAccount->id);

        $this->assertSame([$bankAccount->id], $this->resolver()->bankWhitelist($member));
    }

    // ==================== 優先覆蓋合併與單位換算（顯示用）====================

    #[TestDox('mergeFloor / mergeCeiling 取嚴語意（下限取大、上限取小、-1 不貢獻）')]
    public function test_merge_strict_semantics(): void
    {
        $resolver = $this->resolver();

        // $value = -1（不限制）→ 保留 $existing
        $this->assertSame('50', $resolver->mergeFloor('50', -1));
        $this->assertSame(null, $resolver->mergeFloor(null, -1));
        // $existing = null → 用 $value
        $this->assertSame('100', $resolver->mergeFloor(null, 100));
        // 下限取大
        $this->assertSame('100', $resolver->mergeFloor('50', 100));
        $this->assertSame('200', $resolver->mergeFloor('200', 100));
        // 上限取小
        $this->assertSame('100', $resolver->mergeCeiling('500', 100));
        $this->assertSame('80', $resolver->mergeCeiling('80', 100));
        // 0 是有效限額（不是不限制）
        $this->assertSame('0', $resolver->mergeCeiling('50', 0));
    }

    #[TestDox('point 限額換算 amount：下限無條件進位、上限無條件捨去、rate 無效回 null')]
    public function test_point_to_amount_conversion(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('100', $resolver->pointFloorToAmount(100, '1.0000'));
        $this->assertSame('100', $resolver->pointCeilingToAmount(100, '1.0000'));
        $this->assertSame('500', $resolver->pointFloorToAmount(1000, '2'));
        $this->assertSame('500', $resolver->pointCeilingToAmount(1000, '2'));
        $this->assertSame('334', $resolver->pointFloorToAmount(1000, '3'));
        $this->assertSame('333', $resolver->pointCeilingToAmount(1000, '3'));
        $this->assertSame('101', $resolver->pointFloorToAmount('100.5000', '1'));
        $this->assertNull($resolver->pointFloorToAmount(-1, '1'));
        $this->assertNull($resolver->pointCeilingToAmount(null, '1'));
        $this->assertNull($resolver->pointFloorToAmount(100, '0'));
        $this->assertNull($resolver->pointCeilingToAmount(100, ''));
    }

    // ==================== 提款顯示助手（withdrawFloorAmount / withdrawCeilingAmount / withdrawCountBaseLimit / withdrawDailyAmountLimit）====================

    #[TestDox('withdrawCeilingAmount：群組比 base 嚴時生效（群組 5000、base 10000 → 5000）')]
    public function test_withdraw_ceiling_amount_group_tightens_base(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_max' => 5000]);
        $resolver = $this->resolverWithMocks($this->memberServiceMock(), $this->agentServiceWithoutVip());

        $this->assertSame('5000', $resolver->withdrawCeilingAmount($member, '10000', 1));
    }

    #[TestDox('withdrawCeilingAmount：群組放寬被 base 技術上限壓住（群組 100000、base 10000 → 10000）')]
    public function test_withdraw_ceiling_amount_capped_by_base(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_max' => 100000]);
        $resolver = $this->resolverWithMocks($this->memberServiceMock(), $this->agentServiceWithoutVip());

        $this->assertSame('10000', $resolver->withdrawCeilingAmount($member, '10000', 1));
    }

    #[TestDox('withdrawCeilingAmount 全取嚴：base 10000 ∩ 群組 5000 ∩ VIP 5000000 → 5000（VIP 較寬不放寬）')]
    public function test_withdraw_ceiling_amount_takes_strictest_with_vip(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_max' => 5000]);
        $resolver = $this->resolverWithMocks(
            $this->memberServiceMock(['vip' => $this->makeVip(amountLimit: 5000000)]),
            $this->agentServiceMock([AgentCustomizedSettingKeyEnum::VIP_VISIBLE => 1]),
        );

        $this->assertSame('5000', $resolver->withdrawCeilingAmount($member, '10000', 1));
    }

    #[TestDox('withdrawCeilingAmount：無群組無 VIP 時原樣回傳 base')]
    public function test_withdraw_ceiling_amount_passthrough(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $resolver = $this->resolverWithMocks($this->memberServiceMock(), $this->agentServiceWithoutVip());

        $this->assertSame('10000', $resolver->withdrawCeilingAmount($member, '10000', 1));
    }

    #[TestDox('withdrawFloorAmount：下限取大——群組 50 低於 base 100 時 base 生效（渠道最低金額）')]
    public function test_withdraw_floor_amount_base_floor_wins(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_min' => 50]);
        $resolver = $this->resolverWithMocks($this->memberServiceMock(), $this->agentServiceWithoutVip());

        $this->assertSame('100', $resolver->withdrawFloorAmount($member, '100', 1));
    }

    #[TestDox('withdrawFloorAmount：下限取大——群組 200 高於 base 100 時群組生效')]
    public function test_withdraw_floor_amount_group_floor_wins(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_min' => 200]);
        $resolver = $this->resolverWithMocks($this->memberServiceMock(), $this->agentServiceWithoutVip());

        $this->assertSame('200', $resolver->withdrawFloorAmount($member, '100', 1));
    }

    #[TestDox('withdrawCountBaseLimit 取低（不含 VIP）：代理 10 ∩ 群組 2 → 2')]
    public function test_withdraw_count_base_limit_group_tightens(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_count' => 2]);
        $resolver = $this->resolverWithMocks($this->memberServiceMock(), $this->agentServiceWithoutVip());

        $this->assertSame(2, $resolver->withdrawCountBaseLimit($member, [
            AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT => 10,
        ]));
    }

    #[TestDox('withdrawCountBaseLimit 取低（不含 VIP）：代理 3 ∩ 群組 5 → 3（VIP 不參與基礎值）')]
    public function test_withdraw_count_base_limit_excludes_vip(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_count' => 5]);
        $resolver = $this->resolverWithMocks(
            $this->memberServiceMock(['vip' => $this->makeVip(timesLimit: 7)]),
            $this->agentServiceMock([AgentCustomizedSettingKeyEnum::VIP_VISIBLE => 1]),
        );

        // 基礎值僅代理 ∩ 群組 = min(3,5) = 3；VIP 7 由呼叫端額外加總、不進基礎值
        $this->assertSame(3, $resolver->withdrawCountBaseLimit($member, [
            AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT => 3,
            AgentCustomizedSettingKeyEnum::VIP_VISIBLE => 1,
        ]));
    }

    #[TestDox('withdrawCountBaseLimit：群組設 0 → 0（禁止）')]
    public function test_withdraw_count_base_limit_group_zero(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_count' => 0]);
        $resolver = $this->resolverWithMocks($this->memberServiceMock(), $this->agentServiceWithoutVip());

        $this->assertSame(0, $resolver->withdrawCountBaseLimit($member, [
            AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT => 10,
        ]));
    }

    #[TestDox('withdrawCountBaseLimit：代理 0 且群組 -1 → null（皆不限制）')]
    public function test_withdraw_count_base_limit_unlimited(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_count' => -1]);
        $resolver = $this->resolverWithMocks($this->memberServiceMock(), $this->agentServiceWithoutVip());

        $this->assertNull($resolver->withdrawCountBaseLimit($member, [
            AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT => 0,
        ]));
    }

    #[TestDox('withdrawDailyAmountLimit 全取嚴：群組 1000 ∩ VIP 10000000 → 1000（VIP 較寬不放寬）')]
    public function test_withdraw_daily_amount_limit_vip_does_not_relax_group(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_amount' => 1000]);
        $resolver = $this->resolverWithMocks(
            $this->memberServiceMock(['vip' => $this->makeVip(amountLimit: 10000000)]),
            $this->agentServiceMock([AgentCustomizedSettingKeyEnum::VIP_VISIBLE => 1]),
        );

        // 群組贏得合併（VIP 較寬未生效）；MemberGroup decimal 欄位回傳固定 4 位小數字串
        $this->assertSame('1000.0000', $resolver->withdrawDailyAmountLimit($member));
    }

    #[TestDox('withdrawDailyAmountLimit 全取嚴：群組 10000 ∩ VIP 1000 → 1000（VIP 較嚴生效）')]
    public function test_withdraw_daily_amount_limit_vip_tightens_group(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_amount' => 10000]);
        $resolver = $this->resolverWithMocks(
            $this->memberServiceMock(['vip' => $this->makeVip(amountLimit: 1000)]),
            $this->agentServiceMock([AgentCustomizedSettingKeyEnum::VIP_VISIBLE => 1]),
        );

        $this->assertSame('1000', $resolver->withdrawDailyAmountLimit($member));
    }

    #[TestDox('withdrawDailyAmountLimit：無 VIP 時僅群組（1000）生效')]
    public function test_withdraw_daily_amount_limit_group_only(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_amount' => 1000]);
        $resolver = $this->resolverWithMocks($this->memberServiceMock(), $this->agentServiceWithoutVip());

        // MemberGroup decimal 欄位回傳固定 4 位小數字串
        $this->assertSame('1000.0000', $resolver->withdrawDailyAmountLimit($member));
    }

    #[TestDox('withdrawDailyAmountLimit：群組與 VIP 皆未設 → null（不限制）')]
    public function test_withdraw_daily_amount_limit_unlimited(): void
    {
        [, $member] = $this->createGroupWithMember(['withdraw_daily_amount' => -1]);
        $resolver = $this->resolverWithMocks($this->memberServiceMock(), $this->agentServiceWithoutVip());

        $this->assertNull($resolver->withdrawDailyAmountLimit($member));
    }
}
