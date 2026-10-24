<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Models\Vendor;
use App\Models\WalletTransactionLog;
use App\Services\LargeAmountNotifyService;
use App\Services\MemberTransferService;
use App\Services\VendorService;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\BaseAbstractVendor;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\TestCase;

/**
 * MemberTransferService 測試（查詢最後轉出紀錄、時間區間內轉出遊戲商 ID）
 *
 * php artisan test tests/Unit/Services/MemberTransferServiceTest.php
 */
class MemberTransferServiceTest extends TestCase
{
    private MemberTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = 'mts-' . uniqid('', true) . '.local';
        $this->service = app(MemberTransferService::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createTestVendor(): Vendor
    {
        return Vendor::factory()->create([
            'code' => 'mts_v_' . str_replace('.', '_', uniqid('', true)),
        ]);
    }

    private function mockVendorServiceReturnsVendorIds(Vendor $vendor): void
    {
        $mock = Mockery::mock(VendorService::class);
        $mock->allows('getVendorIds')
            ->with(Mockery::type(Agent::class))
            ->andReturn([$vendor->id]);
        $this->instance(VendorService::class, $mock);
    }

    private function createToVendorTransactionLog(
        Member $member,
        Agent $agent,
        Vendor $vendor,
        array $overrides = [],
    ): TransactionLog {
        return TransactionLog::create(array_merge([
            'transaction_no' => (string) Str::uuid(),
            'member_id' => $member->id,
            'agent_id' => $agent->id,
            'vendor_id' => $vendor->id,
            'type' => TransactionEnum::TO_VENDOR->value,
            'point' => '10.0000',
            'tax' => '0.0000',
            'vendor_point' => '10.0000',
            'rate' => '1.0000',
            'before_balance' => '10.0000',
            'balance' => '0.0000',
            'vendor_balance' => '10.0000',
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'operator' => '0',
            'remark' => '',
            'admin' => 0,
        ], $overrides));
    }

    private function createGameWalletToVendorLog(
        Member $member,
        Agent $agent,
        Vendor $vendor,
        array $overrides = [],
    ): WalletTransactionLog {
        return WalletTransactionLog::create(array_merge([
            'transaction_no' => (string) Str::uuid(),
            'wallet_type_id' => WalletEnum::CODE_GAME->value,
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'vendor_id' => $vendor->id,
            'order_id' => 0,
            'type' => WalletTransactionEnum::GAME_WALLET_TO_VENDOR->value,
            'point' => '10.0000',
            'vendor_point' => '10.0000',
            'tax' => '0.0000',
            'rate' => '1.0000',
            'before_balance' => '10.0000',
            'balance' => '0.0000',
            'vendor_balance' => '10.0000',
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'operator' => '0',
            'admin' => 0,
        ], $overrides));
    }

    public function test_get_last_transfer_out_transaction_log_returns_null_when_no_match(): void
    {
        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'mts_none_' . uniqid()]);
        $this->mockVendorServiceReturnsVendorIds($vendor);

        $this->assertNull($this->service->getLastTransferOutTransactionLog($member));
    }

    public function test_get_last_transfer_out_transaction_log_returns_latest_transaction_log(): void
    {
        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'mts_tl_' . uniqid()]);
        $this->mockVendorServiceReturnsVendorIds($vendor);

        Carbon::setTestNow('2026-04-01 10:00:00');
        $this->createToVendorTransactionLog($member, $agent, $vendor);
        Carbon::setTestNow('2026-04-01 11:00:00');
        $newer = $this->createToVendorTransactionLog($member, $agent, $vendor);

        $member->refresh();
        $result = $this->service->getLastTransferOutTransactionLog($member);

        $this->assertInstanceOf(TransactionLog::class, $result);
        $this->assertSame($newer->id, $result->id);
    }

    public function test_get_last_transfer_out_transaction_log_uses_wallet_log_when_multi_wallet(): void
    {
        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $this->createAgentSetting($agent, AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_SWITCH, 1);
        $agent->refresh();

        $member = $this->createMember($agent, ['username' => 'mts_mw_' . uniqid()]);
        $this->mockVendorServiceReturnsVendorIds($vendor);

        Carbon::setTestNow('2026-04-02 12:00:00');
        $walletLog = $this->createGameWalletToVendorLog($member, $agent, $vendor);
        Carbon::setTestNow('2026-04-02 11:00:00');
        $this->createToVendorTransactionLog($member, $agent, $vendor);

        $member->refresh();
        $result = $this->service->getLastTransferOutTransactionLog($member);

        $this->assertInstanceOf(WalletTransactionLog::class, $result);
        $this->assertSame($walletLog->id, $result->id);
    }

    public function test_get_transfer_out_vendor_ids_returns_vendor_ids_in_range(): void
    {
        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'mts_vids_' . uniqid()]);
        $this->mockVendorServiceReturnsVendorIds($vendor);

        Carbon::setTestNow('2026-05-01 15:00:00');
        $this->createToVendorTransactionLog($member, $agent, $vendor);

        $member->refresh();
        $start = Carbon::parse('2026-05-01 14:00:00');
        $ids = $this->service->getTransferOutVendorIds($member, $start);

        $this->assertSame([$vendor->id], $ids);
    }

    public function test_get_transfer_out_vendor_ids_excludes_logs_before_start(): void
    {
        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'mts_excl_' . uniqid()]);
        $this->mockVendorServiceReturnsVendorIds($vendor);

        Carbon::setTestNow('2026-06-01 08:00:00');
        $this->createToVendorTransactionLog($member, $agent, $vendor);

        $member->refresh();
        $start = Carbon::parse('2026-06-01 09:00:00');
        $ids = $this->service->getTransferOutVendorIds($member, $start);

        $this->assertSame([], $ids);
    }

    public function test_get_transfer_out_vendor_ids_uses_wallet_logs_when_multi_wallet(): void
    {
        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $this->createAgentSetting($agent, AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_SWITCH, 1);
        $agent->refresh();

        $member = $this->createMember($agent, ['username' => 'mts_mwids_' . uniqid()]);
        $this->mockVendorServiceReturnsVendorIds($vendor);

        Carbon::setTestNow('2026-07-01 10:00:00');
        $this->createGameWalletToVendorLog($member, $agent, $vendor);

        $member->refresh();
        $start = Carbon::parse('2026-07-01 09:00:00');
        $ids = $this->service->getTransferOutVendorIds($member, $start);

        $this->assertSame([$vendor->id], $ids);
    }

    #[TestDox('transferToLocal 查詢餘額失敗時，建立含 member_id 的 vendor_request_fails')]
    public function test_transfer_to_local_creates_vendor_request_fail_with_member_id_when_get_balance_fails(): void
    {
        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'mts_fail_' . uniqid('', true)]);
        $member->load('agent');

        $driver = Mockery::mock(AbstractTransferWallet::class);
        $driver->allows('getName')->andReturn('TestVendor');
        $driver->allows('getBalance')->andThrow(new RuntimeException('balance query failed'));
        $this->bindTransferWalletContext($driver, $member, $vendor);

        try {
            $this->service->transferToLocal($driver);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('balance query failed', $exception->getMessage());
        }

        $this->assertDatabaseHas('vendor_request_fails', [
            'vendor_id' => $vendor->id,
            'general_agent_id' => $agent->id,
            'member_id' => $member->id,
            'status' => 0,
        ]);
    }

    // =========================================================================
    // transferToVendor（本地 → 遊戲商）
    // =========================================================================

    #[TestDox('transferToVendor 查詢餘額失敗時，建立 vendor_request_fails 且不進入扣款流程')]
    public function test_transfer_to_vendor_creates_vendor_request_fail_when_get_balance_fails(): void
    {
        [$member, $vendor] = $this->createMemberWithBalance('100');

        $driver = $this->mockTransferDriver($member, $vendor);
        $driver->allows('getBalance')->andThrow(new RuntimeException('balance query failed'));
        $driver->shouldNotReceive('transferToVendor');

        try {
            $this->makeServiceWithMutedNotify()->transferToVendor($driver, '100');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('balance query failed', $exception->getMessage());
        }

        $this->assertDatabaseHas('vendor_request_fails', [
            'vendor_id' => $vendor->id,
            'member_id' => $member->id,
        ]);
        $this->assertEquals(0, Common::numCompare((string) $member->fresh()->balance, '100'));
        $this->assertNoToVendorLog($member);
    }

    #[TestDox('transferToVendor 轉入金額換算後為 0：不扣款、不打三方、直接回傳')]
    public function test_transfer_to_vendor_returns_early_when_amount_zero(): void
    {
        [$member, $vendor] = $this->createMemberWithBalance('0');

        $driver = $this->mockTransferDriver($member, $vendor, vendorBalance: '50');
        $driver->shouldNotReceive('transferToVendor');

        $result = $this->makeServiceWithMutedNotify()->transferToVendor($driver, '0');

        $this->assertEquals(0, Common::numCompare((string) $result['vendor_balance'], '50'));
        $this->assertNoToVendorLog($member);
    }

    #[TestDox('transferToVendor 轉入金額為負值：拋 ApiException、不扣款')]
    public function test_transfer_to_vendor_throws_when_amount_negative(): void
    {
        [$member, $vendor] = $this->createMemberWithBalance('100');

        $driver = $this->mockTransferDriver($member, $vendor);
        $driver->shouldNotReceive('transferToVendor');

        $this->expectException(ApiException::class);

        try {
            $this->makeServiceWithMutedNotify()->transferToVendor($driver, '-10');
        } finally {
            $this->assertEquals(0, Common::numCompare((string) $member->fresh()->balance, '100'));
            $this->assertNoToVendorLog($member);
        }
    }

    #[TestDox('transferToVendor 成功：先扣本地餘額，交易紀錄標記成功')]
    public function test_transfer_to_vendor_success_deducts_and_marks_log_success(): void
    {
        [$member, $vendor] = $this->createMemberWithBalance('100');

        $driver = $this->mockTransferDriver($member, $vendor);
        $driver->allows('getBalance')->andReturn('0', '100');
        $driver->allows('transferToVendor')->once()->andReturn(VendorTransferResultEnum::SUCCESS);

        $result = $this->makeServiceWithMutedNotify()->transferToVendor($driver, '100');

        $this->assertEquals(0, Common::numCompare((string) $member->fresh()->balance, '0'));
        $this->assertEquals(0, Common::numCompare((string) $result['balance'], '0'));
        $this->assertEquals(0, Common::numCompare((string) $result['vendor_balance'], '100'));
        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $member->id,
            'vendor_id' => $vendor->id,
            'type' => TransactionEnum::TO_VENDOR->value,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
        ]);
    }

    #[TestDox('transferToVendor 三方明確失敗：退回本地餘額並建立退款紀錄')]
    public function test_transfer_to_vendor_failed_refunds_member(): void
    {
        [$member, $vendor] = $this->createMemberWithBalance('100');

        $driver = $this->mockTransferDriver($member, $vendor);
        $driver->allows('transferToVendor')->once()->andReturn(VendorTransferResultEnum::FAILED);

        $this->makeServiceWithMutedNotify()->transferToVendor($driver, '100');

        $this->assertEquals(0, Common::numCompare((string) $member->fresh()->balance, '100'));
        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $member->id,
            'type' => TransactionEnum::TO_VENDOR->value,
            'status' => TransactionStatusEnum::STATUS_TO_VENDOR_FAILED_REFUND->value,
        ]);
        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $member->id,
            'type' => TransactionEnum::TO_VENDOR_FAILED_REFUND->value,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
        ]);
    }

    #[TestDox('transferToVendor 三方逾時：不退錢，交易紀錄標記 UNKNOWN_TRANSFER_FAILED')]
    public function test_transfer_to_vendor_timeout_keeps_deduction(): void
    {
        [$member, $vendor] = $this->createMemberWithBalance('100');

        $driver = $this->mockTransferDriver($member, $vendor);
        $driver->allows('transferToVendor')->once()->andReturn(VendorTransferResultEnum::TIMEOUT);

        $this->makeServiceWithMutedNotify()->transferToVendor($driver, '100');

        $this->assertEquals(0, Common::numCompare((string) $member->fresh()->balance, '0'));
        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $member->id,
            'type' => TransactionEnum::TO_VENDOR->value,
            'status' => TransactionStatusEnum::UNKNOWN_TRANSFER_FAILED->value,
            'remark' => 'timeout',
        ]);
    }

    #[TestDox('transferToVendor 三方拋例外：不退錢、標記 UNKNOWN_TRANSFER_FAILED 並重拋')]
    public function test_transfer_to_vendor_third_party_exception_keeps_deduction(): void
    {
        [$member, $vendor] = $this->createMemberWithBalance('100');

        $driver = $this->mockTransferDriver($member, $vendor);
        $driver->allows('transferToVendor')->once()->andThrow(new RuntimeException('vendor api down'));

        try {
            $this->makeServiceWithMutedNotify()->transferToVendor($driver, '100');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('vendor api down', $exception->getMessage());
        }

        $this->assertEquals(0, Common::numCompare((string) $member->fresh()->balance, '0'));
        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $member->id,
            'type' => TransactionEnum::TO_VENDOR->value,
            'status' => TransactionStatusEnum::UNKNOWN_TRANSFER_FAILED->value,
        ]);
    }

    #[TestDox('transferToVendor 本地餘額不足：拋 ApiException 並回滾，失敗紀錄留存')]
    public function test_transfer_to_vendor_insufficient_balance_rolls_back(): void
    {
        [$member, $vendor] = $this->createMemberWithBalance('50');

        $driver = $this->mockTransferDriver($member, $vendor);
        $driver->shouldNotReceive('transferToVendor');

        try {
            $this->makeServiceWithMutedNotify()->transferToVendor($driver, '100');
            $this->fail('Expected ApiException was not thrown');
        } catch (ApiException $exception) {
            $this->assertSame(__('error_message.1022'), $exception->getMessage());
        }

        $this->assertEquals(0, Common::numCompare((string) $member->fresh()->balance, '50'));
        // 交易紀錄刻意建立於 transaction 之外，rollback 後仍須留存失敗紀錄
        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $member->id,
            'type' => TransactionEnum::TO_VENDOR->value,
            'status' => TransactionStatusEnum::STATUS_FAIL->value,
        ]);
    }

    /**
     * @return array{0: Member, 1: Vendor}
     */
    private function createMemberWithBalance(string $balance): array
    {
        $vendor = $this->createTestVendor();
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, [
            'username' => 'mts_tv_' . uniqid('', true),
            'balance' => $balance,
        ]);
        $member->load('agent');

        return [$member, $vendor];
    }

    /**
     * 建立 transferToVendor 用的轉帳錢包 driver mock（rate=1、最小轉帳單位=1）
     */
    private function mockTransferDriver(Member $member, Vendor $vendor, string $vendorBalance = '0'): AbstractTransferWallet&Mockery\MockInterface
    {
        $driver = Mockery::mock(AbstractTransferWallet::class);
        $driver->allows('getName')->andReturn('TestVendor');
        $driver->allows('getRate')->andReturn('1');
        $driver->allows('getTransferMinAmount')->andReturn('1');
        $driver->allows('getTransactionNo')->andReturnUsing(fn () => (string) Str::uuid());
        $driver->allows('getBalance')->andReturn($vendorBalance)->byDefault();
        $this->bindTransferWalletContext($driver, $member, $vendor);

        return $driver;
    }

    /**
     * 大額通知靜音的 service 實例（隔離 BotNotify 副作用）
     */
    private function makeServiceWithMutedNotify(): MemberTransferService
    {
        $this->instance(
            LargeAmountNotifyService::class,
            Mockery::mock(LargeAmountNotifyService::class)->shouldIgnoreMissing(),
        );

        return app(MemberTransferService::class);
    }

    private function assertNoToVendorLog(Member $member): void
    {
        $this->assertDatabaseMissing('transaction_logs', [
            'member_id' => $member->id,
            'type' => TransactionEnum::TO_VENDOR->value,
        ]);
    }

    private function bindTransferWalletContext(
        AbstractTransferWallet $driver,
        Member $member,
        Vendor $vendor,
    ): void {
        \Closure::bind(function () use ($member, $vendor): void {
            $this->member = $member;
            $this->vendor = $vendor;
        }, $driver, BaseAbstractVendor::class)();
    }
}
