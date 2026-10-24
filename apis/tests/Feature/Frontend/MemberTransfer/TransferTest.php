<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\MemberTransfer;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Vendor\VendorMaintainTypeEnum;
use App\Helpers\Common;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\PrizeContentFlowLimit;
use App\Models\TransactionLog;
use App\Models\Vendor;
use App\Models\VendorMaintenances;
use App\Services\MemberTransferService;
use App\Services\VendorService;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\AbstractTransferWallet;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Str;
use Tests\TestCase;

/**
 * MemberTransferController::transfer 行為固定測試（characterization tests）
 *
 * 固定 transfer() 的既有行為，作為重構的保護網：
 * - 轉回跳過條件（無 lastLog / lastLog 遊戲商錢包維護中 / lastLog 與目標相同）
 * - 維護分流（轉入：任一維護類型一律擋；轉回：只有錢包面向關閉才跳過，只關遊戲照常轉回）
 * - 打碼限制擋轉出、單一錢包與轉帳錢包回應、會員級別鎖
 *
 * php artisan test tests/Feature/Frontend/MemberTransfer/TransferTest.php
 */
class TransferTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected Vendor $vendor1;

    protected Vendor $vendor2;

    protected string $token;

    protected MemberTransferService&MockInterface $transferServiceMock;

    const API_NAME = 'frontend/api/member/transfer';

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->agent = $this->createAgentAndDomain([
            'vendor_customize_group_id' => 1,
        ]);
        $this->member = $this->createMember($this->agent);
        $this->token = [REDACTED];
        $this->vendor1 = $this->createBasicVendorGames($this->agent);
        $this->vendor2 = $this->createBasicVendorGames($this->agent);
    }

    /**
     * 無 lastLog（新會員）：跳過轉回，回傳本地餘額
     */
    public function test_refresh_without_last_log_skips_transfer_back()
    {
        $this->mockTransferService(transferToLocalTimes: 0);
        $this->mockVendorService($this->fakeTransferDriver());

        $response = $this->requestApi(self::API_NAME, []);

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEquals(0, Common::numCompare($json['data']['balance'], 0));
        $this->assertEquals(0, Common::numCompare($json['data']['vendor_balance'], 0));
    }

    /**
     * 有 lastLog：先將該遊戲商餘額轉回本地
     */
    public function test_refresh_with_last_log_transfers_back()
    {
        $this->createTransferOutLog($this->vendor1);
        $this->mockTransferService(transferToLocalTimes: 1);
        $this->mockVendorService($this->fakeTransferDriver());

        $response = $this->requestApi(self::API_NAME, []);

        $response->assertStatus(200);
    }

    /**
     * lastLog 遊戲商「關錢包」維護中：跳過轉回（靜默略過，仍回成功）
     */
    public function test_transfer_back_skipped_when_last_vendor_wallet_maintained()
    {
        $this->createTransferOutLog($this->vendor1);
        $this->createMaintenance($this->vendor1, VendorMaintainTypeEnum::WALLET_CLOSED);
        $this->mockTransferService(transferToLocalTimes: 0);
        $this->mockVendorService($this->fakeTransferDriver());

        $response = $this->requestApi(self::API_NAME, []);

        $response->assertStatus(200);
    }

    /**
     * lastLog 與目標遊戲商相同（二次充值）：跳過轉回，直接轉出
     */
    public function test_transfer_back_skipped_when_last_vendor_equals_target()
    {
        $this->member->update(['balance' => 100]);
        $this->createTransferOutLog($this->vendor2);
        $this->mockTransferService(transferToLocalTimes: 0, transferToVendorTimes: 1);
        $this->mockVendorService($this->fakeTransferDriver());

        $response = $this->requestApi(self::API_NAME, ['vendor_id' => $this->vendor2->id]);

        $response->assertStatus(200);
    }

    /**
     * 目標遊戲商「全關」維護中：擋轉出（error 1938）
     */
    public function test_transfer_in_blocked_when_target_both_closed()
    {
        $this->member->update(['balance' => 100]);
        $this->createMaintenance($this->vendor2, VendorMaintainTypeEnum::BOTH_CLOSED);
        $this->mockTransferService(transferToVendorTimes: 0);
        $this->mockVendorService($this->fakeTransferDriver());

        $response = $this->requestApi(self::API_NAME, ['vendor_id' => $this->vendor2->id]);

        $response->assertStatus(400);
        $this->assertEquals(__('error_message.1938'), $response->json()['message']);
    }

    /**
     * 目標遊戲商「關錢包」維護中：擋轉出（error 1938）
     */
    public function test_transfer_in_blocked_when_target_wallet_closed()
    {
        $this->member->update(['balance' => 100]);
        $this->createMaintenance($this->vendor2, VendorMaintainTypeEnum::WALLET_CLOSED);
        $this->mockTransferService(transferToVendorTimes: 0);
        $this->mockVendorService($this->fakeTransferDriver());

        $response = $this->requestApi(self::API_NAME, ['vendor_id' => $this->vendor2->id]);

        $response->assertStatus(400);
        $this->assertEquals(__('error_message.1938'), $response->json()['message']);
    }

    /**
     * 目標遊戲商「只關遊戲」維護中：一樣擋轉入（維護期間不論錢包是否開啟，一律禁止轉入）
     */
    public function test_transfer_in_blocked_when_target_game_closed()
    {
        $this->member->update(['balance' => 100]);
        $this->createMaintenance($this->vendor2, VendorMaintainTypeEnum::GAME_CLOSED);
        $this->mockTransferService(transferToVendorTimes: 0);
        $this->mockVendorService($this->fakeTransferDriver());

        $response = $this->requestApi(self::API_NAME, ['vendor_id' => $this->vendor2->id]);

        $response->assertStatus(400);
        $this->assertEquals(__('error_message.1938'), $response->json()['message']);
    }

    /**
     * lastLog 遊戲商「只關遊戲」維護中：錢包面向未關，照常轉回本地
     */
    public function test_transfer_back_proceeds_when_last_vendor_game_closed()
    {
        $this->createTransferOutLog($this->vendor1);
        $this->createMaintenance($this->vendor1, VendorMaintainTypeEnum::GAME_CLOSED);
        $this->mockTransferService(transferToLocalTimes: 1);
        $this->mockVendorService($this->fakeTransferDriver());

        $response = $this->requestApi(self::API_NAME, []);

        $response->assertStatus(200);
    }

    /**
     * 轉回本地失敗不阻斷流程：仍繼續轉出到目標遊戲商
     */
    public function test_transfer_back_failure_does_not_block_transfer_in()
    {
        $this->member->update(['balance' => 100]);
        $this->createTransferOutLog($this->vendor1);

        $serviceMock = Mockery::mock(MemberTransferService::class)->makePartial();
        $serviceMock->shouldReceive('transferToLocal')
            ->once()
            ->andThrow(new RuntimeException('vendor api down'));
        $serviceMock->shouldReceive('transferToVendor')
            ->once()
            ->andReturn(['balance' => '0', 'vendor_balance' => '100.0000']);
        $this->app->instance(MemberTransferService::class, $serviceMock);

        $this->mockVendorService($this->fakeTransferDriver());

        $response = $this->requestApi(self::API_NAME, ['vendor_id' => $this->vendor2->id]);

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEquals(0, Common::numCompare($json['data']['vendor_balance'], 100));
    }

    /**
     * 轉出到轉帳錢包：以本地錢包全額呼叫 transferToVendor，回傳遊戲商餘額
     */
    public function test_transfer_in_full_local_balance_to_transfer_wallet()
    {
        $this->member->update(['balance' => 100]);

        $serviceMock = Mockery::mock(MemberTransferService::class)->makePartial();
        $serviceMock->shouldReceive('transferToLocal')->never();
        $serviceMock->shouldReceive('transferToVendor')
            ->once()
            ->withArgs(function ($driver, $amount) {
                return Common::numCompare((string) $amount, 100) === 0;
            })
            ->andReturn(['balance' => '0', 'vendor_balance' => '100.0000']);
        $this->app->instance(MemberTransferService::class, $serviceMock);

        $this->mockVendorService($this->fakeTransferDriver());

        $response = $this->requestApi(self::API_NAME, ['vendor_id' => $this->vendor2->id]);

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEquals(0, Common::numCompare($json['data']['vendor_balance'], 100));
        $this->assertEquals(0, Common::numCompare($json['data']['rolling_chip'], 0));
    }

    /**
     * 目標為單一錢包：不轉帳，直接回傳本地餘額換算（rate=2）
     */
    public function test_single_wallet_target_returns_converted_local_balance()
    {
        $this->member->update(['balance' => 50]);
        $this->mockTransferService(transferToVendorTimes: 0);
        $this->mockVendorService($this->fakeSingleDriver(rate: '2'));

        $response = $this->requestApi(self::API_NAME, ['vendor_id' => $this->vendor2->id]);

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEquals(0, Common::numCompare($json['data']['balance'], 50));
        $this->assertEquals(0, Common::numCompare($json['data']['vendor_balance'], 100));
    }

    /**
     * 鎖分模式且目標遊戲商不被允許：擋轉出
     */
    public function test_flow_limit_blocks_transfer_in()
    {
        $this->member->update(['balance' => 100, 'event_mode' => 9]);
        PrizeContentFlowLimit::query()->forceCreate([
            'prize_content_id' => 9,
            'vendor_id' => $this->vendor1->id,
            'game_type_id' => 5,
            'admin' => 0,
        ]);
        $this->mockTransferService(transferToVendorTimes: 0);
        $this->mockVendorService($this->fakeTransferDriver());

        $response = $this->requestApi(self::API_NAME, ['vendor_id' => $this->vendor2->id]);

        $response->assertStatus(400);
        $this->assertEquals(
            __('error_message.event_mode.vendor_not_allowed_to_transfer_out'),
            $response->json()['message'],
        );
    }

    /**
     * 會員級別鎖：鎖被占用時請求立即失敗（HTTP 423 / error 99993）
     */
    public function test_concurrent_request_rejected_by_member_lock()
    {
        $lock = Cache::lock(sprintf('member:%d:transfer', $this->member->id), 60);
        $this->assertTrue($lock->get());

        try {
            $response = $this->requestApi(self::API_NAME, []);

            $response->assertStatus(423);
            $this->assertEquals(__('error_message.99993'), $response->json()['message']);
        } finally {
            $lock->release();
        }
    }

    /**
     * 建立一筆成功的轉出（本地 → 遊戲商）交易紀錄，作為 lastLog
     */
    private function createTransferOutLog(Vendor $vendor): TransactionLog
    {
        return TransactionLog::create([
            'transaction_no' => Str::uuid(),
            'member_id' => $this->member->id,
            'agent_id' => $this->agent->id,
            'vendor_id' => $vendor->id,
            'type' => TransactionEnum::TO_VENDOR->value,
            'point' => 10,
            'vendor_point' => 10,
            'rate' => 1,
            'before_balance' => 10,
            'balance' => 0,
            'vendor_balance' => 10,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'operator' => 0,
            'remark' => '',
            'admin' => 0,
        ]);
    }

    /**
     * 建立生效中的維護單（狀態啟用、時間涵蓋當下、掛在測試代理上）
     */
    private function createMaintenance(Vendor $vendor, VendorMaintainTypeEnum $type): VendorMaintenances
    {
        return VendorMaintenances::query()->forceCreate([
            'vendor_id' => $vendor->id,
            'type' => $type->value,
            'start_time' => now()->subHour()->format('Y-m-d H:i:s'),
            'end_time' => now()->addHour()->format('Y-m-d H:i:s'),
            'agent_id' => $this->agent->id,
            'exclude_agent_id' => 0,
            'status' => 1,
            'memo' => '',
            'admin' => 0,
        ]);
    }

    private function fakeTransferDriver(): AbstractTransferWallet&MockInterface
    {
        $driver = Mockery::mock(AbstractTransferWallet::class);
        $driver->shouldReceive('getBalance')->andReturn('0');
        $driver->shouldReceive('getName')->andReturn('fake-transfer');
        $driver->shouldReceive('getRate')->andReturn('1');

        return $driver;
    }

    private function fakeSingleDriver(string $rate = '1'): AbstractSingleWallet&MockInterface
    {
        $driver = Mockery::mock(AbstractSingleWallet::class);
        $driver->shouldReceive('getName')->andReturn('fake-single');
        $driver->shouldReceive('getRate')->andReturn($rate);

        return $driver;
    }

    /**
     * 以固定期望次數 stub 掉 Service 的轉回 / 轉出（controller 邏輯保持真實）
     */
    private function mockTransferService(?int $transferToLocalTimes = null, ?int $transferToVendorTimes = null): void
    {
        $serviceMock = Mockery::mock(MemberTransferService::class)->makePartial();

        if (!is_null($transferToLocalTimes)) {
            $serviceMock->shouldReceive('transferToLocal')
                ->times($transferToLocalTimes)
                ->andReturn(['withdrawal_amount' => '0', 'balance' => '0', 'vendor_balance' => '0']);
        }

        if (!is_null($transferToVendorTimes)) {
            $serviceMock->shouldReceive('transferToVendor')
                ->times($transferToVendorTimes)
                ->andReturn(['balance' => '0', 'vendor_balance' => '0']);
        }

        $this->app->instance(MemberTransferService::class, $serviceMock);
        $this->transferServiceMock = $serviceMock;
    }

    private function mockVendorService(AbstractTransferWallet|AbstractSingleWallet $driver): void
    {
        $vendorServiceMock = Mockery::mock(VendorService::class)->makePartial();
        $vendorServiceMock->shouldReceive('getDriver')->andReturn($driver);
        $this->app->instance(VendorService::class, $vendorServiceMock);
    }
}
