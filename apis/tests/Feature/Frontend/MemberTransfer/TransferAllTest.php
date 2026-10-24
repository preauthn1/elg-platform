<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\MemberTransfer;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Helpers\Common;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Models\Vendor;
use App\Services\MemberTransferService;
use App\Services\VendorService;
use App\Vendors\AbstractTransferWallet;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Mockery;
use Str;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/MemberTransfer/TransferAllTest.php
 */
class TransferAllTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    /**
     * @var Vendor[]
     */
    protected array $vendors;

    protected string $token;

    const API_NAME = 'frontend/api/member/transfer-all';

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);
        // $this->withoutMiddleware(RequestLogger::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->agent = $this->createAgentAndDomain([
            'vendor_customize_group_id' => 1,
        ]);
        $this->member = $this->createMember($this->agent);
        $this->token = [REDACTED];

        // 建立10個假的遊戲商
        for ($i = 0; $i < 10; $i++) {
            $this->vendors[] = $this->createBasicVendorGames($this->agent);
        }
    }

    /**
     * php artisan test tests/Feature/Frontend/MemberTransfer/TransferAllTest.php --filter=test_no_vendor
     *
     * @return void
     */
    public function test_no_vendor()
    {
        $response = $this->requestApi(self::API_NAME, []);
        $json = $response->json();
        $response->assertStatus(200);
        $this->assertEquals(0, Common::numCompare($json['data']['balance'], 0, 4));
        // $this->assertEquals(0, Common::numCompare($json['data']['vendor_balance'], 0, 4));
        $this->assertEquals(0, Common::numCompare($json['data']['rolling_chip'], 0, 4));
    }

    /**
     * 測試第 1 次呼叫
     * php artisan test tests/Feature/Frontend/MemberTransfer/TransferAllTest.php --filter=test_1_times
     */
    public function test_1_times()
    {
        $transactionLog = $this->createTransactionLog();
        $this->createMocks(1, $transactionLog);

        $response = $this->requestApi(self::API_NAME, []);
        $json = $response->json();
        $response->assertStatus(200);
        $this->assertEquals(0, Common::numCompare($json['data']['balance'], 10, 4));
        // $this->assertEquals(0, Common::numCompare($json['data']['vendor_balance'], 0, 4));
        $this->assertEquals(0, Common::numCompare($json['data']['rolling_chip'], 0, 4));
    }

    /**
     * 測試第 2 次呼叫
     * php artisan test tests/Feature/Frontend/MemberTransfer/TransferAllTest.php --filter=test_2_times
     */
    public function test_2_times()
    {
        $transactionLog = $this->createTransactionLog();
        $this->createMocks(2, $transactionLog);

        $response = $this->requestApi('frontend/api/member/transfer-all', []);
        $json = $response->json();
        $response->assertStatus(200);
        $this->assertEquals(0, Common::numCompare($json['data']['balance'], 40, 4));
        // $this->assertEquals(0, Common::numCompare($json['data']['vendor_balance'], 0, 4));
        $this->assertEquals(0, Common::numCompare($json['data']['rolling_chip'], 0, 4));
    }

    /**
     * 測試第 10 次呼叫
     * php artisan test tests/Feature/Frontend/MemberTransfer/TransferAllTest.php --filter=test_10times
     */
    public function test_10times()
    {
        $transactionLog = $this->createTransactionLog();
        $this->createMocks(10, $transactionLog);

        $response = $this->requestApi(self::API_NAME, []);
        $json = $response->json();
        $response->assertStatus(200);
        $this->assertEquals(0, Common::numCompare($json['data']['balance'], 100, 4));
        // $this->assertEquals(0, Common::numCompare($json['data']['vendor_balance'], 0, 4));
        $this->assertEquals(0, Common::numCompare($json['data']['rolling_chip'], 0, 4));
    }

    public function createMocks(int $callApiCount, TransactionLog $transactionLog)
    {
        // 建立假的呼叫次數, 因不是 mock 且內部呼叫就會增加一次, 所以減掉一次
        $callApiCount -= 1;
        Common::clearApiCallCount(self::API_NAME, $this->member->id);
        for ($i = 0; $i < $callApiCount; $i++) {
            $result = Common::getApiCallCount(self::API_NAME, 300, $this->member->id);
        }

        // 建立假的 TransferWallet 物件
        $fakeDriver = Mockery::mock(AbstractTransferWallet::class);
        $fakeDriver->shouldReceive('isMaintain')->andReturn(false);
        $fakeDriver->shouldReceive('getName')->andReturn('TestVendor');

        // Mock VendorService
        $vendorServiceMock = Mockery::mock(VendorService::class)->makePartial();
        $vendorServiceMock->shouldReceive('getDriver')->andReturn($fakeDriver);

        // Mock MemberTransferService
        $memberTransferServiceMock = Mockery::mock(MemberTransferService::class)->makePartial();
        $memberTransferServiceMock->shouldReceive('transferToLocal')->andReturnUsing(function () use ($transactionLog) {
            $this->member->balance += $transactionLog->point;
            $this->member->save();

            return [
                'balance' => $transactionLog->point,
                // 'vendor_balance' => '0',
            ];
        });

        // $this->app->instance(Common::class, $common);
        $this->app->instance(VendorService::class, $vendorServiceMock);
        $this->app->instance(MemberTransferService::class, $memberTransferServiceMock);
    }

    public function createTransactionLog(): TransactionLog
    {
        // 關閉 model 的 guard
        TransactionLog::unguard();
        foreach ($this->vendors as $i => $vendor) {
            $createdAt = now()->subMonths($i);
            $transactionLog = TransactionLog::create([
                'transaction_no' => Str::uuid(),
                'member_id' => $this->member->id,
                'agent_id' => $this->agent->id,
                'vendor_id' => $vendor->id,
                'type' => TransactionEnum::TO_VENDOR->value,
                'point' => 10,
                'vendor_point' => 10 * $vendor->rate,
                'rate' => $vendor->rate,
                'before_balance' => 10,
                'balance' => 0,
                'vendor_balance' => 10 * $vendor->rate,
                'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                'operator' => 0,
                'remark' => '',
                'created_at' => $createdAt,
                'admin' => 0,
            ]);
        }
        TransactionLog::reguard();

        return $transactionLog;
    }
}
