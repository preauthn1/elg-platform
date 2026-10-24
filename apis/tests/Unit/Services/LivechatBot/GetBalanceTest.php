<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LivechatBot;

use App\Data\MemberAllWalletData;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\LivechatBot;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Services\LivechatBot\GetBalanceService;
use App\Services\LivechatBot\LivechatBotContext;
use App\Services\LivechatBot\MemberAgentGuard;
use App\Services\LivechatBot\ProviderWalletBalanceResolver;
use App\Services\WalletService;
use Mockery;
use Tests\TestCase;

/**
 * Livechat Bot 查詢餘額測試
 *
 * php artisan test tests/Unit/Services/LivechatBot/GetBalanceTest.php
 */
class GetBalanceTest extends TestCase
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
     * AC-BE-001：正常查詢餘額（is_multi_wallet=false）
     */
    public function test_get_balance_single_wallet(): void
    {
        $member = $this->createMember($this->agent);

        [$walletService, $resolver] = $this->mockServices();

        $walletData = $this->buildWalletData(
            member: $member,
            mainBalance: '420.00',
            gameBalance: '50.00',
            rollingChipBalance: '20.00',
            isMultipleWallet: false,
        );

        $walletService->shouldReceive('getMemberAllWallets')->andReturn($walletData);
        $resolver->shouldReceive('resolve')->andReturn('0.00');

        $this->createEventWallet($member, '100.00');

        $service = new GetBalanceService($walletService, $resolver, $this->context, $this->guard);
        $result = $service->getBalance($member->username);

        $this->assertEquals('420.00', $result['main_wallet_balance']);
        $this->assertEquals('0.00', $result['sum_provider_wallet_balance']);
        $this->assertEquals('420.00', $result['total_wallet_balance']);
        $this->assertEquals('20.00', $result['rolling_chips']);
        $this->assertEquals('100.00', $result['event_wallet_balance']);
        $this->assertArrayNotHasKey('game_wallet_balance', $result);
    }

    /**
     * AC-BE-002：正常查詢餘額（is_multi_wallet=true，包含 game_wallet_balance）
     */
    public function test_get_balance_multi_wallet(): void
    {
        $member = $this->createMember($this->agent);

        [$walletService, $resolver] = $this->mockServices();

        $walletData = $this->buildWalletData(
            member: $member,
            mainBalance: '420.00',
            gameBalance: '50.00',
            rollingChipBalance: '20.00',
            isMultipleWallet: true,
        );

        $walletService->shouldReceive('getMemberAllWallets')->andReturn($walletData);
        $resolver->shouldReceive('resolve')->andReturn('0.00');

        $this->createEventWallet($member, '100.00');

        $service = new GetBalanceService($walletService, $resolver, $this->context, $this->guard);
        $result = $service->getBalance($member->username);

        $this->assertEquals('420.00', $result['main_wallet_balance']);
        $this->assertEquals('50.00', $result['game_wallet_balance']);
        $this->assertEquals('0.00', $result['sum_provider_wallet_balance']);
        $this->assertEquals('470.00', $result['total_wallet_balance']);
        $this->assertArrayHasKey('game_wallet_balance', $result);
    }

    /**
     * AC-BE-007：sum_provider_wallet_balance 為最後轉入遊戲商餘額
     */
    public function test_sum_provider_wallet_balance_from_last_vendor(): void
    {
        $member = $this->createMember($this->agent);

        [$walletService, $resolver] = $this->mockServices();

        $walletData = $this->buildWalletData(
            member: $member,
            mainBalance: '100.00',
            gameBalance: '0.00',
            rollingChipBalance: '0.00',
            isMultipleWallet: false,
        );

        $walletService->shouldReceive('getMemberAllWallets')->andReturn($walletData);

        // 直接 mock resolver 回傳遊戲商即時餘額
        $resolver->shouldReceive('resolve')
            ->with(Mockery::type(Member::class))
            ->once()
            ->andReturn('200.50');

        $this->createEventWallet($member, '0.00');

        $service = new GetBalanceService($walletService, $resolver, $this->context, $this->guard);
        $result = $service->getBalance($member->username);

        $this->assertEquals('200.50', $result['sum_provider_wallet_balance']);
    }

    /**
     * AC-BE-008：無轉入記錄 → sum_provider_wallet_balance = 0
     */
    public function test_no_transfer_log_returns_zero_provider_balance(): void
    {
        $member = $this->createMember($this->agent);

        [$walletService, $resolver] = $this->mockServices();

        $walletData = $this->buildWalletData(
            member: $member,
            mainBalance: '100.00',
            gameBalance: '0.00',
            rollingChipBalance: '0.00',
            isMultipleWallet: false,
        );

        $walletService->shouldReceive('getMemberAllWallets')->andReturn($walletData);
        $resolver->shouldReceive('resolve')->andReturn('0.00');

        $this->createEventWallet($member, '0.00');

        $service = new GetBalanceService($walletService, $resolver, $this->context, $this->guard);
        $result = $service->getBalance($member->username);

        $this->assertEquals('0.00', $result['sum_provider_wallet_balance']);
    }

    /**
     * AC-BE-009：total_wallet_balance 計算正確（multi_wallet=false）
     */
    public function test_total_wallet_balance_single_wallet(): void
    {
        $member = $this->createMember($this->agent);

        [$walletService, $resolver] = $this->mockServices();

        $walletData = $this->buildWalletData(
            member: $member,
            mainBalance: '300.00',
            gameBalance: '50.00',
            rollingChipBalance: '0.00',
            isMultipleWallet: false,
        );

        $walletService->shouldReceive('getMemberAllWallets')->andReturn($walletData);
        $resolver->shouldReceive('resolve')->andReturn('100.00');

        $this->createEventWallet($member, '200.00');

        $service = new GetBalanceService($walletService, $resolver, $this->context, $this->guard);
        $result = $service->getBalance($member->username);

        // total = main(300) + provider(100) = 400, event(200) 不計入
        $this->assertEquals('400.00', $result['total_wallet_balance']);
        $this->assertEquals('200.00', $result['event_wallet_balance']);
    }

    /**
     * AC-BE-010：total_wallet_balance 計算正確（multi_wallet=true，含 game_wallet）
     */
    public function test_total_wallet_balance_multi_wallet(): void
    {
        $member = $this->createMember($this->agent);

        [$walletService, $resolver] = $this->mockServices();

        $walletData = $this->buildWalletData(
            member: $member,
            mainBalance: '300.00',
            gameBalance: '50.00',
            rollingChipBalance: '0.00',
            isMultipleWallet: true,
        );

        $walletService->shouldReceive('getMemberAllWallets')->andReturn($walletData);
        $resolver->shouldReceive('resolve')->andReturn('100.00');

        $this->createEventWallet($member, '0.00');

        $service = new GetBalanceService($walletService, $resolver, $this->context, $this->guard);
        $result = $service->getBalance($member->username);

        // total = main(300) + game(50) + provider(100) = 450
        $this->assertEquals('450.00', $result['total_wallet_balance']);
        $this->assertEquals('50.00', $result['game_wallet_balance']);
    }

    /**
     * AC-BE-011：message 陣列格式正確
     */
    public function test_message_array_format(): void
    {
        $member = $this->createMember($this->agent);

        [$walletService, $resolver] = $this->mockServices();

        $walletData = $this->buildWalletData(
            member: $member,
            mainBalance: '100.00',
            gameBalance: '0.00',
            rollingChipBalance: '10.00',
            isMultipleWallet: false,
        );

        $walletService->shouldReceive('getMemberAllWallets')->andReturn($walletData);
        $resolver->shouldReceive('resolve')->andReturn('0.00');

        $this->createEventWallet($member, '50.00');

        $service = new GetBalanceService($walletService, $resolver, $this->context, $this->guard);
        $result = $service->getBalance($member->username);

        $this->assertIsArray($result['message']);
        $this->assertContains('Total Wallet Balance: 100.00', $result['message']);
        $this->assertContains('Main Wallet Balance: 100.00', $result['message']);
        $this->assertContains('Sum Provider Wallet Balance: 0.00', $result['message']);
        $this->assertContains('Rolling Chips: 10.00', $result['message']);
        $this->assertContains('Event Wallet Balance: 50.00', $result['message']);
        // 不含 Game Wallet Balance（single wallet）
        $this->assertNotContains('Game Wallet Balance: 0.00', $result['message']);
    }

    /**
     * AC-BE-012：in_promo 正確反映 is_event_v2 狀態
     */
    public function test_in_promo_reflects_is_event_v2(): void
    {
        $member = $this->createMember($this->agent);

        [$walletService, $resolver] = $this->mockServices();

        $walletData = $this->buildWalletData(
            member: $member,
            mainBalance: '100.00',
            gameBalance: '0.00',
            rollingChipBalance: '0.00',
            isMultipleWallet: false,
        );

        $walletService->shouldReceive('getMemberAllWallets')->andReturn($walletData);
        $resolver->shouldReceive('resolve')->andReturn('0.00');

        $this->createEventWallet($member, '0.00');

        $service = new GetBalanceService($walletService, $resolver, $this->context, $this->guard);
        $result = $service->getBalance($member->username);

        // is_event_v2 預設為 false
        $this->assertIsBool($result['in_promo']);
        $this->assertFalse($result['in_promo']);
        $this->assertContains('In Promo: No', $result['message']);
    }

    /**
     * 跨 agent 存取（bot 綁不同 agent）→ 404，避免洩漏 cross-tenant 資訊
     */
    public function test_member_not_in_bot_agent_hierarchy_throws_404(): void
    {
        $member = $this->createMember($this->agent);

        // 建立另一個獨立 agent，並讓 bot 綁定它
        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $otherAgent = $this->createAgentAndDomain();
        $otherContext = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($otherAgent->id)));

        [$walletService, $resolver] = $this->mockServices();
        $service = new GetBalanceService($walletService, $resolver, $otherContext, $this->guard);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member not found');
        $this->expectExceptionCode(404);

        $service->getBalance($member->username);
    }

    /**
     * 建立模擬服務
     *
     * @return array{0: Mockery\MockInterface, 1: Mockery\MockInterface}
     */
    private function mockServices(): array
    {
        return [
            // partial mock：只 stub 各測試指定的方法（getMemberAllWallets），
            // 其餘（如 queryMemberWallet）走真實實作查測試庫——
            // 各測試以 createEventWallet() 建的真實列要能被查到
            Mockery::mock(WalletService::class)->makePartial(),
            Mockery::mock(ProviderWalletBalanceResolver::class),
        ];
    }

    /**
     * 建立 MemberAllWalletData 測試資料
     */
    private function buildWalletData(
        Member $member,
        string $mainBalance,
        string $gameBalance,
        string $rollingChipBalance,
        bool $isMultipleWallet,
    ): MemberAllWalletData {
        $mainWallet = Member::find($member->id);
        $mainWallet->balance = $mainBalance;

        $gameWallet = MemberWallet::factory()->create([
            'member_id' => $member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME->value,
            'balance' => $gameBalance,
        ]);

        $rollingChipWallet = MemberWallet::factory()->create([
            'member_id' => $member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP->value,
            'balance' => $rollingChipBalance,
        ]);

        return new MemberAllWalletData(
            mainWallet: $mainWallet,
            gameWallet: $gameWallet,
            rollingChipWallet: $rollingChipWallet,
            walletBalance: $mainBalance,
            isMultipleWallet: $isMultipleWallet,
        );
    }

    /**
     * 建立活動錢包
     */
    private function createEventWallet(Member $member, string $balance): MemberWallet
    {
        return MemberWallet::factory()->create([
            'member_id' => $member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET->value,
            'balance' => $balance,
        ]);
    }
}
