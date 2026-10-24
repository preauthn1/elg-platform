<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\MemberTransfer;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Helpers\Common;
use App\Http\Middleware\Frontend\DecryptRequest;
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
 * php artisan test tests/Feature/Frontend/MemberTransfer/TransferBackTest.php
 */
class TransferBackTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected Vendor $vendor1;

    protected Vendor $vendor2;

    protected string $token;

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

    public function test_no_vendor()
    {
        $response = $this->requestApi('frontend/api/member/transfer', []);
        $json = $response->json();
        $response->assertStatus(200);
        $this->assertEquals(0, Common::numCompare($json['data']['balance'], 0));
        // $this->assertEquals(0, Common::numCompare($json['data']['vendor_balance'], 0)); // optional
        $this->assertEquals(0, Common::numCompare($json['data']['rolling_chip'], 0));
    }

    /**
     * vendor1 轉到本地錢包再轉到 vendor2
     * php artisan test tests/Feature/Frontend/MemberTransfer/TransferBackTest.php --filter=test_success
     */
    public function test_success()
    {
        $transactionLog = $this->createTransactionLog();

        // 建立假的 TransferWallet 物件
        $fakeDriver = Mockery::mock(AbstractTransferWallet::class);
        $fakeDriver->shouldReceive('getBalance')->andReturn('10');
        $fakeDriver->shouldReceive('isMaintain')->andReturn(false);
        $fakeDriver->shouldReceive('getName')->andReturn('1');
        $fakeDriver->shouldReceive('getRate')->andReturn('10');

        // Mock VendorService
        $vendorServiceMock = Mockery::mock(VendorService::class)->makePartial();
        $vendorServiceMock->shouldReceive('getDriver')->andReturn($fakeDriver);

        // Mock MemberTransferService
        $memberTransferServiceMock = Mockery::mock(MemberTransferService::class)->makePartial();
        $memberTransferServiceMock->shouldReceive('transferToLocal')->andReturnUsing(function () use ($transactionLog) {
            $this->member->balance = $transactionLog->point;
            $this->member->save();

            return [
                'balance' => $transactionLog->point,
                'vendor_balance' => '0',
            ];
        });
        $memberTransferServiceMock->shouldReceive('transferToVendor')->andReturnUsing(function () use ($transactionLog) {
            $this->member->balance = 0;
            $this->member->save();

            return [
                'balance' => 0,
                'vendor_balance' => $transactionLog->point,
            ];
        });

        $this->app->instance(VendorService::class, $vendorServiceMock);
        $this->app->instance(MemberTransferService::class, $memberTransferServiceMock);

        $response = $this->requestApi('frontend/api/member/transfer', ['vendor_id' => $this->vendor2->id]);
        $json = $response->json();
        $response->assertStatus(200);
        $this->assertEquals(0, Common::numCompare($json['data']['balance'], 0));
        $this->assertEquals(0, Common::numCompare($json['data']['game_wallet_balance'], 0));
        $this->assertEquals(0, Common::numCompare($json['data']['vendor_balance'], $transactionLog->point));
        $this->assertEquals(0, Common::numCompare($json['data']['rolling_chip'], 0));
    }

    public function createTransactionLog(): TransactionLog
    {
        $transactionLog = TransactionLog::create([
            'transaction_no' => Str::uuid(),
            'member_id' => $this->member->id,
            'agent_id' => $this->agent->id,
            'vendor_id' => $this->vendor1->id,
            'type' => TransactionEnum::TO_VENDOR->value,
            'point' => 10,
            'vendor_point' => 10 * $this->vendor1->rate,
            'rate' => $this->vendor1->rate,
            'before_balance' => 10,
            'balance' => 0,
            'vendor_balance' => 10 * $this->vendor1->rate,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'operator' => 0,
            'remark' => '',
            'admin' => 0,
        ]);

        return $transactionLog;
    }
}
