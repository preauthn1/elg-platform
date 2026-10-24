<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Agent\RoleEnum;
use App\Models\Agent;
use App\Models\TotalBetRecord;
use App\Models\Vendor;
use App\Services\BotNotifyService;
use App\Services\LargeAmountNotifyService;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * LargeAmountNotifyService 大額通知測試
 *
 * php artisan test tests/Unit/Services/LargeAmountNotifyServiceTest.php
 */
class LargeAmountNotifyServiceTest extends TestCase
{
    private LargeAmountNotifyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = 'lan-' . uniqid('', true) . '.local';
        $this->service = app(LargeAmountNotifyService::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        config([
            'bot-notify.large_amount.threshold' => 5000,
            'bot-notify.large_amount.notify_channel' => 'test-channel-id',
            'bot-notify.large_amount.watch_agents' => '',
        ]);
    }

    private function mockBotNotify(): MockInterface
    {
        $mock = Mockery::mock(BotNotifyService::class);
        $mock->allows('channel')->andReturnSelf();
        $this->instance(BotNotifyService::class, $mock);

        return $mock;
    }

    private function createWatchedAgentAndMember(): array
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        config(['bot-notify.large_amount.watch_agents' => (string) $agent->id]);

        return [$agent, $member];
    }

    private function createTestVendor(): Vendor
    {
        return Vendor::factory()->create([
            'code' => 'lan_v_' . str_replace('.', '_', uniqid('', true)),
        ]);
    }

    // =========================================================================
    // notifyWithdrawal
    // =========================================================================

    public function test_withdrawal_no_notification_when_amount_below_threshold(): void
    {
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $this->service->notifyWithdrawal($member, $agent, '4999.9999', '1000');
    }

    public function test_withdrawal_no_notification_when_agent_not_in_watch_list(): void
    {
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        config(['bot-notify.large_amount.watch_agents' => '99999']);

        $this->service->notifyWithdrawal($member, $agent, '5000', '1000');
    }

    public function test_withdrawal_sends_notification_when_amount_equals_threshold(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, '【單次大額提款通知】')));

        $this->service->notifyWithdrawal($member, $agent, '5000', '500');
    }

    public function test_withdrawal_sends_notification_when_amount_exceeds_threshold(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, $member->username)));

        $this->service->notifyWithdrawal($member, $agent, '9999', '0');
    }

    public function test_withdrawal_sends_notification_when_watch_agents_has_whitespace_and_extra_commas(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        config(['bot-notify.large_amount.watch_agents' => " , {$agent->id} ,, "]);

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, '【單次大額提款通知】')));

        $this->service->notifyWithdrawal($member, $agent, '7000', '500');
    }

    public function test_withdrawal_no_notification_when_watch_agents_contains_only_empty_tokens(): void
    {
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        config(['bot-notify.large_amount.watch_agents' => ' , , , ']);

        $this->service->notifyWithdrawal($member, $agent, '7000', '500');
    }

    public function test_withdrawal_notification_message_contains_required_fields(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();

        $capturedMessage = null;
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->withArgs(function (string $msg) use (&$capturedMessage) {
                $capturedMessage = $msg;

                return true;
            });

        $this->service->notifyWithdrawal($member, $agent, '6000', '888');

        $this->assertStringContainsString('【單次大額提款通知】', $capturedMessage);
        $this->assertStringContainsString('會員帳號：' . $member->username . ' (上級代理 ' . $agent->username . ')', $capturedMessage);
        $this->assertStringContainsString('提款金額：6000', $capturedMessage);
        $this->assertStringContainsString('目前餘額：888', $capturedMessage);
    }

    // =========================================================================
    // notifyVendorTransferOut
    // =========================================================================

    public function test_vendor_transfer_no_notification_when_amount_below_threshold(): void
    {
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $this->service->notifyVendorTransferOut($member, $vendor, '4999.9999');
    }

    public function test_vendor_transfer_no_notification_when_agent_not_in_watch_list(): void
    {
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        config(['bot-notify.large_amount.watch_agents' => '99999']);

        $this->service->notifyVendorTransferOut($member, $vendor, '5000');
    }

    public function test_vendor_transfer_sends_notification_when_amount_equals_threshold(): void
    {
        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        config(['bot-notify.large_amount.watch_agents' => (string) $agent->id]);

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, '【遊戲商大額轉出通知】')));

        $this->service->notifyVendorTransferOut($member, $vendor, '5000');
    }

    public function test_vendor_transfer_sends_notification_when_amount_exceeds_threshold(): void
    {
        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        config(['bot-notify.large_amount.watch_agents' => (string) $agent->id]);

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, $member->username)));

        $this->service->notifyVendorTransferOut($member, $vendor, '9999');
    }

    public function test_vendor_transfer_notification_message_contains_required_fields(): void
    {
        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        config(['bot-notify.large_amount.watch_agents' => (string) $agent->id]);

        $capturedMessage = null;
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->withArgs(function (string $msg) use (&$capturedMessage) {
                $capturedMessage = $msg;

                return true;
            });

        $this->service->notifyVendorTransferOut($member, $vendor, '6000');

        $this->assertStringContainsString('【遊戲商大額轉出通知】', $capturedMessage);
        $this->assertStringContainsString('會員帳號：' . $member->username . ' (上級代理 ' . $agent->username . ')', $capturedMessage);
        $this->assertStringContainsString('遊戲商：' . $vendor->name, $capturedMessage);
        $this->assertStringContainsString('轉出金額：6000', $capturedMessage);
    }

    // =========================================================================
    // notifyVendorTransferIn
    // =========================================================================

    public function test_vendor_transfer_in_no_notification_when_amount_below_threshold(): void
    {
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $this->service->notifyVendorTransferIn($member, $vendor, '4999.9999');
    }

    public function test_vendor_transfer_in_no_notification_when_agent_not_in_watch_list(): void
    {
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        config(['bot-notify.large_amount.watch_agents' => '99999']);

        $this->service->notifyVendorTransferIn($member, $vendor, '5000');
    }

    public function test_vendor_transfer_in_sends_notification_when_amount_equals_threshold(): void
    {
        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        config(['bot-notify.large_amount.watch_agents' => (string) $agent->id]);

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, '【遊戲商大額轉入通知】')));

        $this->service->notifyVendorTransferIn($member, $vendor, '5000');
    }

    public function test_vendor_transfer_in_sends_notification_when_amount_exceeds_threshold(): void
    {
        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        config(['bot-notify.large_amount.watch_agents' => (string) $agent->id]);

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, $member->username)));

        $this->service->notifyVendorTransferIn($member, $vendor, '9999');
    }

    public function test_vendor_transfer_in_notification_message_contains_required_fields(): void
    {
        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        config(['bot-notify.large_amount.watch_agents' => (string) $agent->id]);

        $capturedMessage = null;
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->withArgs(function (string $msg) use (&$capturedMessage) {
                $capturedMessage = $msg;

                return true;
            });

        $this->service->notifyVendorTransferIn($member, $vendor, '6000');

        $this->assertStringContainsString('【遊戲商大額轉入通知】', $capturedMessage);
        $this->assertStringContainsString('會員帳號：' . $member->username . ' (上級代理 ' . $agent->username . ')', $capturedMessage);
        $this->assertStringContainsString('遊戲商：' . $vendor->name, $capturedMessage);
        $this->assertStringContainsString('轉入金額：6000', $capturedMessage);
    }

    // =========================================================================
    // notifyLargeBetRecord
    // =========================================================================

    private function createBetRecord(array $overrides = []): TotalBetRecord
    {
        return new TotalBetRecord(array_merge([
            'bet' => '0',
            'valid_bet' => '0',
            'win_lose' => '0',
            'rolling_chip' => '0',
            'jackpot' => '0',
            'bonus' => '0',
            'reference_no' => 'ref-' . uniqid(),
            'game_id' => 0,
        ], $overrides));
    }

    public function test_bet_record_no_notification_when_all_fields_below_threshold(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();
        $vendor = $this->createTestVendor();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $record = $this->createBetRecord([
            'bet' => '4999.9999',
            'valid_bet' => '4000',
            'win_lose' => '3000',
        ]);

        $this->service->notifyLargeBetRecord($member->id, $vendor->id, $record);
    }

    public function test_bet_record_no_notification_when_agent_not_in_watch_list(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $vendor = $this->createTestVendor();
        config(['bot-notify.large_amount.watch_agents' => '99999']);

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $record = $this->createBetRecord(['bet' => '6000']);

        $this->service->notifyLargeBetRecord($member->id, $vendor->id, $record);
    }

    public function test_bet_record_no_notification_when_member_not_found(): void
    {
        $vendor = $this->createTestVendor();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $record = $this->createBetRecord(['bet' => '6000']);

        $this->service->notifyLargeBetRecord(99999, $vendor->id, $record);
    }

    public function test_bet_record_no_notification_when_vendor_not_found(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $record = $this->createBetRecord(['bet' => '6000']);

        $this->service->notifyLargeBetRecord($member->id, 99999, $record);
    }

    public function test_bet_record_no_notification_when_only_win_lose_exceeds_threshold(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();
        $vendor = $this->createTestVendor();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $record = $this->createBetRecord(['win_lose' => '8000']);

        $this->service->notifyLargeBetRecord($member->id, $vendor->id, $record);
    }

    public function test_bet_record_sends_notification_when_any_field_exceeds_threshold(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();
        $vendor = $this->createTestVendor();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, '【大額投注紀錄通知】')));

        $record = $this->createBetRecord(['rolling_chip' => '8000']);

        $this->service->notifyLargeBetRecord($member->id, $vendor->id, $record);
    }

    public function test_bet_record_lists_all_exceeded_fields_in_message(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();
        $vendor = $this->createTestVendor();

        $captured = null;
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->withArgs(function (string $msg) use (&$captured) {
                $captured = $msg;

                return true;
            });

        $record = $this->createBetRecord([
            'bet' => '6000',
            'valid_bet' => '5500',
            'rolling_chip' => '5100',
        ]);

        $this->service->notifyLargeBetRecord($member->id, $vendor->id, $record);

        $this->assertStringContainsString('bet=6000', $captured);
        $this->assertStringContainsString('valid_bet=5500', $captured);
        $this->assertStringContainsString('rolling_chip=5100', $captured);
    }

    public function test_bet_record_message_contains_required_fields(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();
        $vendor = $this->createTestVendor();

        $captured = null;
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->withArgs(function (string $msg) use (&$captured) {
                $captured = $msg;

                return true;
            });

        $record = $this->createBetRecord([
            'bet' => '6000',
            'reference_no' => 'TX-ABC-123',
        ]);

        $this->service->notifyLargeBetRecord($member->id, $vendor->id, $record);

        $this->assertStringContainsString('【大額投注紀錄通知】', $captured);
        $this->assertStringContainsString('會員帳號：' . $member->username . ' (上級代理 ' . $agent->username . ')', $captured);
        $this->assertStringContainsString('遊戲商：' . $vendor->name, $captured);
        $this->assertStringContainsString('交易號：TX-ABC-123', $captured);
        $this->assertStringContainsString('超標欄位：bet=6000', $captured);
    }

    // =========================================================================
    // notifyLargePayout
    // =========================================================================

    public function test_payout_no_notification_when_member_not_found(): void
    {
        $vendor = $this->createTestVendor();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $record = $this->createBetRecord(['win_lose' => '8000']);

        $this->service->notifyLargePayout(99999, $vendor->id, $record);
    }

    public function test_payout_no_notification_when_vendor_not_found(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $record = $this->createBetRecord(['win_lose' => '8000']);

        $this->service->notifyLargePayout($member->id, 99999, $record);
    }

    public function test_payout_no_notification_when_all_fields_below_threshold(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();
        $vendor = $this->createTestVendor();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $record = $this->createBetRecord([
            'win_lose' => '4999.9999',
            'bonus' => '3000',
            'jackpot' => '0',
        ]);

        $this->service->notifyLargePayout($member->id, $vendor->id, $record);
    }

    public function test_payout_no_notification_when_agent_not_in_watch_list(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $vendor = $this->createTestVendor();
        config(['bot-notify.large_amount.watch_agents' => '99999']);

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $record = $this->createBetRecord(['win_lose' => '8000']);

        $this->service->notifyLargePayout($member->id, $vendor->id, $record);
    }

    public function test_payout_sends_notification_when_win_lose_exceeds_threshold(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();
        $vendor = $this->createTestVendor();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, '【大額派彩通知】')));

        $record = $this->createBetRecord(['win_lose' => '8000']);

        $this->service->notifyLargePayout($member->id, $vendor->id, $record);
    }

    public function test_payout_sends_notification_when_bonus_exceeds_threshold(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();
        $vendor = $this->createTestVendor();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, '【大額派彩通知】')));

        $record = $this->createBetRecord(['bonus' => '6000']);

        $this->service->notifyLargePayout($member->id, $vendor->id, $record);
    }

    public function test_payout_sends_notification_when_jackpot_exceeds_threshold(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();
        $vendor = $this->createTestVendor();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, '【大額派彩通知】')));

        $record = $this->createBetRecord(['jackpot' => '5000']);

        $this->service->notifyLargePayout($member->id, $vendor->id, $record);
    }

    public function test_payout_lists_all_exceeded_fields_in_message(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();
        $vendor = $this->createTestVendor();

        $captured = null;
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->withArgs(function (string $msg) use (&$captured) {
                $captured = $msg;

                return true;
            });

        $record = $this->createBetRecord([
            'win_lose' => '7000',
            'bonus' => '6000',
            'jackpot' => '5500',
        ]);

        $this->service->notifyLargePayout($member->id, $vendor->id, $record);

        $this->assertStringContainsString('win_lose=7000', $captured);
        $this->assertStringContainsString('bonus=6000', $captured);
        $this->assertStringContainsString('jackpot=5500', $captured);
    }

    public function test_payout_message_contains_required_fields(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();
        $vendor = $this->createTestVendor();

        $captured = null;
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->withArgs(function (string $msg) use (&$captured) {
                $captured = $msg;

                return true;
            });

        $record = $this->createBetRecord([
            'win_lose' => '9000',
            'reference_no' => 'PAY-XYZ-456',
        ]);

        $this->service->notifyLargePayout($member->id, $vendor->id, $record);

        $this->assertStringContainsString('【大額派彩通知】', $captured);
        $this->assertStringContainsString('會員帳號：' . $member->username . ' (上級代理 ' . $agent->username . ')', $captured);
        $this->assertStringContainsString('遊戲商：' . $vendor->name, $captured);
        $this->assertStringContainsString('交易號：PAY-XYZ-456', $captured);
        $this->assertStringContainsString('超標欄位：win_lose=9000', $captured);
    }

    // =========================================================================
    // notifyDeposit
    // =========================================================================

    public function test_deposit_no_notification_when_amount_below_threshold(): void
    {
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $this->service->notifyDeposit($member, $agent, '4999.9999', '1000');
    }

    public function test_deposit_no_notification_when_agent_not_in_watch_list(): void
    {
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')->never();

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        config(['bot-notify.large_amount.watch_agents' => '99999']);

        $this->service->notifyDeposit($member, $agent, '5000', '1000');
    }

    public function test_deposit_sends_notification_when_amount_equals_threshold(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, '【單次大額存款通知】')));

        $this->service->notifyDeposit($member, $agent, '5000', '500');
    }

    public function test_deposit_sends_notification_when_amount_exceeds_threshold(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();

        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->with(Mockery::on(fn (string $msg) => str_contains($msg, $member->username)));

        $this->service->notifyDeposit($member, $agent, '9999', '0');
    }

    public function test_deposit_notification_message_contains_required_fields(): void
    {
        [$agent, $member] = $this->createWatchedAgentAndMember();

        $capturedMessage = null;
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->withArgs(function (string $msg) use (&$capturedMessage) {
                $capturedMessage = $msg;

                return true;
            });

        $this->service->notifyDeposit($member, $agent, '6000', '888');

        $this->assertStringContainsString('【單次大額存款通知】', $capturedMessage);
        $this->assertStringContainsString('會員帳號：' . $member->username . ' (上級代理 ' . $agent->username . ')', $capturedMessage);
        $this->assertStringContainsString('存款金額：6000', $capturedMessage);
        $this->assertStringContainsString('目前餘額：888', $capturedMessage);
    }

    // =========================================================================
    // 一級代理 / 上級代理 顯示
    // =========================================================================

    public function test_message_includes_first_level_agent_below_general_agent(): void
    {
        // 一級代理 = 總代（GENERAL_AGENT）的下一層；上級代理 = members.parent
        $generalAgent = Agent::factory()->create(['role_id' => RoleEnum::GENERAL_AGENT->value]);
        $firstLevel = Agent::factory()->create(['role_id' => 0]);
        $middle = Agent::factory()->create(['role_id' => 0]);
        $parent = $this->createAgentAndDomain(['role_id' => 0]);
        $parent->hierarchy_path = "/1/{$generalAgent->id}/{$firstLevel->id}/{$middle->id}/{$parent->id}";
        $parent->save();
        $member = $this->createMember($parent);
        config(['bot-notify.large_amount.watch_agents' => (string) $parent->id]);

        $captured = null;
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->withArgs(function (string $msg) use (&$captured) {
                $captured = $msg;

                return true;
            });

        $this->service->notifyWithdrawal($member, $parent, '6000', '888');

        $this->assertStringContainsString('一級代理：' . $firstLevel->username, $captured);
        $this->assertStringContainsString('會員帳號：' . $member->username . ' (上級代理 ' . $parent->username . ')', $captured);
    }

    public function test_message_includes_first_level_agent_when_two_general_agents_stacked(): void
    {
        // VWorld 結構：白標 root 與總代皆 GENERAL_AGENT（兩層堆疊）→ 一級代理取最後一個總代的下一層
        $whitelabelRoot = Agent::factory()->create(['role_id' => RoleEnum::GENERAL_AGENT->value]);
        $generalAgent = Agent::factory()->create(['role_id' => RoleEnum::GENERAL_AGENT->value]);
        $firstLevel = Agent::factory()->create(['role_id' => 0]);
        $parent = $this->createAgentAndDomain(['role_id' => 0]);
        $parent->hierarchy_path = "/1/{$whitelabelRoot->id}/{$generalAgent->id}/{$firstLevel->id}/{$parent->id}";
        $parent->save();
        $member = $this->createMember($parent);
        config(['bot-notify.large_amount.watch_agents' => (string) $parent->id]);

        $captured = null;
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->withArgs(function (string $msg) use (&$captured) {
                $captured = $msg;

                return true;
            });

        $this->service->notifyWithdrawal($member, $parent, '6000', '888');

        $this->assertStringContainsString('一級代理：' . $firstLevel->username, $captured);
        $this->assertStringNotContainsString('一級代理：' . $generalAgent->username, $captured);
    }

    public function test_first_level_agent_line_omitted_when_hierarchy_too_shallow(): void
    {
        // createAgentAndDomain 產生的 hierarchy_path 僅一層 → 無第四層 → 不顯示一級代理行
        [$agent, $member] = $this->createWatchedAgentAndMember();

        $captured = null;
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->withArgs(function (string $msg) use (&$captured) {
                $captured = $msg;

                return true;
            });

        $this->service->notifyWithdrawal($member, $agent, '6000', '888');

        $this->assertStringNotContainsString('一級代理：', $captured);
        $this->assertStringContainsString('會員帳號：' . $member->username . ' (上級代理 ' . $agent->username . ')', $captured);
    }

    public function test_first_level_agent_shown_even_when_equal_to_superior(): void
    {
        // 玩家直接掛在總代的下一層：一級代理 == 上級代理（同一 agent），仍照樣顯示兩行
        $generalAgent = Agent::factory()->create(['role_id' => RoleEnum::GENERAL_AGENT->value]);
        $parent = $this->createAgentAndDomain(['role_id' => 0]);
        $parent->hierarchy_path = "/1/{$generalAgent->id}/{$parent->id}";
        $parent->save();
        $member = $this->createMember($parent);
        config(['bot-notify.large_amount.watch_agents' => (string) $parent->id]);

        $captured = null;
        $mock = $this->mockBotNotify();
        $mock->expects('sendOnCommit')
            ->once()
            ->withArgs(function (string $msg) use (&$captured) {
                $captured = $msg;

                return true;
            });

        $this->service->notifyWithdrawal($member, $parent, '6000', '888');

        $this->assertStringContainsString('一級代理：' . $parent->username, $captured);
        $this->assertStringContainsString('會員帳號：' . $member->username . ' (上級代理 ' . $parent->username . ')', $captured);
    }
}
