<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LivechatBot;

use App\Data\MemberAllWalletData;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\LivechatBotWithdrawEligibilityEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\EventV2UserJoin;
use App\Models\LivechatBot;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Services\LivechatBot\GetWithdrawBalanceService;
use App\Services\LivechatBot\LivechatBotContext;
use App\Services\LivechatBot\MemberAgentGuard;
use App\Services\LivechatBot\ProviderWalletBalanceResolver;
use App\Services\MemberService;
use App\Services\WalletService;
use Mockery;
use Tests\TestCase;

/**
 * Livechat Bot 查詢可提款餘額測試
 *
 * php artisan test tests/Unit/Services/LivechatBot/GetWithdrawBalanceTest.php
 */
class GetWithdrawBalanceTest extends TestCase
{
    private ?Agent $agent = null;

    private ?LivechatBotContext $context = null;

    private ?MemberAgentGuard $guard = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->context = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($this->agent->id)));
        $this->guard = new MemberAgentGuard;
    }

    private function makeBot(int $agentId): LivechatBot
    {
        return LivechatBot::create([
            'name' => 'unit_bot_' . bin2hex(random_bytes(4)),
            'agent_id' => $agentId,
            'api_key' => bin2hex(random_bytes(16)),
            'vip_url' => null,
            'is_active' => true,
        ]);
    }

    /**
     * 無活動、流水通過、主錢包 > 0 → ELIGIBLE
     */
    public function test_eligible_when_no_promotion_flow_passed_and_balance_positive(): void
    {
        $member = $this->createMember($this->agent);

        [$walletService, $memberService, $resolver] = $this->mockServices();

        $walletService->shouldReceive('getMemberAllWallets')
            ->andReturn($this->buildWalletData($member, mainBalance: '500.00'));

        $resolver->shouldReceive('resolve')->andReturn('200.00');
        $memberService->shouldReceive('getFlowLimit')->andReturn([
            'flow_limit' => 0,
            'reach' => 0,
            'pass' => 1,
        ]);

        $service = new GetWithdrawBalanceService($walletService, $memberService, $resolver, $this->context, $this->guard);
        $result = $service->getWithdrawBalance($member->username);

        $this->assertSame(
            LivechatBotWithdrawEligibilityEnum::ELIGIBLE->value,
            $result['withdraw_eligibility'],
        );
        $this->assertSame('500.00', $result['withdrawable_balance']);
        $this->assertSame('0.00', $result['non_withdrawable_balance']);
        $this->assertSame('500.00', $result['main_wallet_balance']);
        $this->assertSame('200.00', $result['provider_wallet_balance']);
    }

    /**
     * 會員有進行中的活動 (is_event_v2=true) → ACTIVE_PROMOTION
     */
    public function test_ineligible_active_promotion_returns_promotion_message(): void
    {
        $member = $this->createMember($this->agent);

        // 建立 JOIN 狀態的 EventV2UserJoin，使 is_event_v2 為 true
        EventV2UserJoin::insert([
            'event_v2_detail_id' => 1,
            'member_id' => $member->id,
            'reward' => 10,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$walletService, $memberService, $resolver] = $this->mockServices();

        $walletService->shouldReceive('getMemberAllWallets')
            ->andReturn($this->buildWalletData($member, mainBalance: '300.00'));

        $resolver->shouldReceive('resolve')->andReturn('100.00');
        $memberService->shouldReceive('getFlowLimit')->andReturn([
            'flow_limit' => 0,
            'reach' => 0,
            'pass' => 1,
        ]);

        $service = new GetWithdrawBalanceService($walletService, $memberService, $resolver, $this->context, $this->guard);
        $result = $service->getWithdrawBalance($member->username);

        $this->assertSame(
            LivechatBotWithdrawEligibilityEnum::ACTIVE_PROMOTION->value,
            $result['withdraw_eligibility'],
        );
        $this->assertSame('0.00', $result['withdrawable_balance']);
        // 400 = main(300) + provider(100)
        $this->assertSame('400.00', $result['non_withdrawable_balance']);
    }

    /**
     * 流水未達標 (pass=0) → TURNOVER_NOT_MET
     */
    public function test_ineligible_turnover_not_met_returns_turnover_message(): void
    {
        $member = $this->createMember($this->agent);

        [$walletService, $memberService, $resolver] = $this->mockServices();

        $walletService->shouldReceive('getMemberAllWallets')
            ->andReturn($this->buildWalletData($member, mainBalance: '500.00'));

        $resolver->shouldReceive('resolve')->andReturn('50.00');
        $memberService->shouldReceive('getFlowLimit')->andReturn([
            'flow_limit' => 1000,
            'reach' => 200,
            'pass' => 0,
        ]);

        $service = new GetWithdrawBalanceService($walletService, $memberService, $resolver, $this->context, $this->guard);
        $result = $service->getWithdrawBalance($member->username);

        $this->assertSame(
            LivechatBotWithdrawEligibilityEnum::TURNOVER_NOT_MET->value,
            $result['withdraw_eligibility'],
        );
        $this->assertSame('0.00', $result['withdrawable_balance']);
        // 550 = main(500) + provider(50)
        $this->assertSame('550.00', $result['non_withdrawable_balance']);
    }

    /**
     * 主錢包餘額 = 0 → INSUFFICIENT_BALANCE
     */
    public function test_ineligible_zero_balance_returns_insufficient_balance_message(): void
    {
        $member = $this->createMember($this->agent);

        [$walletService, $memberService, $resolver] = $this->mockServices();

        $walletService->shouldReceive('getMemberAllWallets')
            ->andReturn($this->buildWalletData($member, mainBalance: '0.00'));

        $resolver->shouldReceive('resolve')->andReturn('150.00');
        $memberService->shouldReceive('getFlowLimit')->andReturn([
            'flow_limit' => 0,
            'reach' => 0,
            'pass' => 1,
        ]);

        $service = new GetWithdrawBalanceService($walletService, $memberService, $resolver, $this->context, $this->guard);
        $result = $service->getWithdrawBalance($member->username);

        $this->assertSame(
            LivechatBotWithdrawEligibilityEnum::INSUFFICIENT_BALANCE->value,
            $result['withdraw_eligibility'],
        );
        $this->assertSame('0.00', $result['withdrawable_balance']);
        // 150 = main(0) + provider(150)
        $this->assertSame('150.00', $result['non_withdrawable_balance']);
    }

    /**
     * Ineligible 情境：non_withdrawable = main + provider
     */
    public function test_non_withdrawable_equals_sum_when_ineligible(): void
    {
        $member = $this->createMember($this->agent);

        [$walletService, $memberService, $resolver] = $this->mockServices();

        $walletService->shouldReceive('getMemberAllWallets')
            ->andReturn($this->buildWalletData($member, mainBalance: '123.45'));

        $resolver->shouldReceive('resolve')->andReturn('77.55');
        // 流水未達標 → ineligible
        $memberService->shouldReceive('getFlowLimit')->andReturn([
            'flow_limit' => 1000,
            'reach' => 500,
            'pass' => 0,
        ]);

        $service = new GetWithdrawBalanceService($walletService, $memberService, $resolver, $this->context, $this->guard);
        $result = $service->getWithdrawBalance($member->username);

        $this->assertSame(
            LivechatBotWithdrawEligibilityEnum::TURNOVER_NOT_MET->value,
            $result['withdraw_eligibility'],
        );
        $this->assertSame('0.00', $result['withdrawable_balance']);
        // 123.45 + 77.55 = 201.00
        $this->assertSame('201.00', $result['non_withdrawable_balance']);
    }

    /**
     * main_wallet_turnover_progress 格式為 "reach / required"，4 位小數
     */
    public function test_turnover_progress_formatted_4dp(): void
    {
        $member = $this->createMember($this->agent);

        [$walletService, $memberService, $resolver] = $this->mockServices();

        $walletService->shouldReceive('getMemberAllWallets')
            ->andReturn($this->buildWalletData($member, mainBalance: '100.00'));

        $resolver->shouldReceive('resolve')->andReturn('0.00');
        $memberService->shouldReceive('getFlowLimit')->andReturn([
            'flow_limit' => '1000',
            'reach' => '250.5',
            'pass' => 0,
        ]);

        $service = new GetWithdrawBalanceService($walletService, $memberService, $resolver, $this->context, $this->guard);
        $result = $service->getWithdrawBalance($member->username);

        $this->assertSame('250.5000 / 1000.0000', $result['main_wallet_turnover_progress']);
    }

    /**
     * message 陣列 6 筆且格式正確
     */
    public function test_message_array_format(): void
    {
        $member = $this->createMember($this->agent);

        [$walletService, $memberService, $resolver] = $this->mockServices();

        $walletService->shouldReceive('getMemberAllWallets')
            ->andReturn($this->buildWalletData($member, mainBalance: '500.00'));

        $resolver->shouldReceive('resolve')->andReturn('200.00');
        $memberService->shouldReceive('getFlowLimit')->andReturn([
            'flow_limit' => '1000',
            'reach' => '1000',
            'pass' => 1,
        ]);

        $service = new GetWithdrawBalanceService($walletService, $memberService, $resolver, $this->context, $this->guard);
        $result = $service->getWithdrawBalance($member->username);

        $this->assertIsArray($result['message']);
        $this->assertCount(6, $result['message']);
        $this->assertContains('Main Wallet Balance: 500.00', $result['message']);
        $this->assertContains('Provider Wallet Balance: 200.00', $result['message']);
        $this->assertContains('Main Wallet Turnover Progress: 1000.0000 / 1000.0000', $result['message']);
        $this->assertContains('Withdrawable Balance: 500.00', $result['message']);
        $this->assertContains('Non-Withdrawable Balance: 0.00', $result['message']);
        $this->assertContains(
            'Withdraw Eligibility: ' . LivechatBotWithdrawEligibilityEnum::ELIGIBLE->value,
            $result['message'],
        );
    }

    /**
     * 跨 agent 存取（bot 綁不同 agent）→ 404
     */
    public function test_member_not_in_bot_agent_hierarchy_throws_404(): void
    {
        $member = $this->createMember($this->agent);

        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $otherAgent = $this->createAgentAndDomain();
        $otherContext = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($otherAgent->id)));

        [$walletService, $memberService, $resolver] = $this->mockServices();
        $service = new GetWithdrawBalanceService($walletService, $memberService, $resolver, $otherContext, $this->guard);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member not found');
        $this->expectExceptionCode(404);

        $service->getWithdrawBalance($member->username);
    }

    /**
     * 建立模擬服務
     *
     * @return array{0: Mockery\MockInterface, 1: Mockery\MockInterface, 2: Mockery\MockInterface}
     */
    private function mockServices(): array
    {
        return [
            Mockery::mock(WalletService::class),
            Mockery::mock(MemberService::class),
            Mockery::mock(ProviderWalletBalanceResolver::class),
        ];
    }

    /**
     * 建立 MemberAllWalletData 測試資料
     */
    private function buildWalletData(Member $member, string $mainBalance): MemberAllWalletData
    {
        $mainWallet = Member::find($member->id);
        $mainWallet->balance = $mainBalance;

        $gameWallet = MemberWallet::factory()->create([
            'member_id' => $member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME->value,
            'balance' => '0',
        ]);

        $rollingChipWallet = MemberWallet::factory()->create([
            'member_id' => $member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP->value,
            'balance' => '0',
        ]);

        return new MemberAllWalletData(
            mainWallet: $mainWallet,
            gameWallet: $gameWallet,
            rollingChipWallet: $rollingChipWallet,
            walletBalance: $mainBalance,
            isMultipleWallet: false,
        );
    }
}
