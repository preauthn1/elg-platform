<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\AutoDeposit;

use App\Enums\CashFlowEnum;
use App\Enums\CashFlowTypeEnum;
use App\Enums\OrderStatusEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\BankAccount;
use App\Models\Member;
use App\Models\Order;
use App\Models\Vendor;
use App\Services\FileStorageService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Mockery;
use Tests\TestCase;

class AutoDepositUploadImageOrderTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected Vendor $vendor1;

    protected Vendor $vendor2;

    protected string $token;

    const API_BANK_CREATE_ORDER = '/frontend/api/auto-deposit/order/create';

    const API_BANK_UPLOAD_IMAGE = '/frontend/api/auto-deposit/order/upload-image';

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

    public function test_bank_upload_image_success(): void
    {
        [$cashFlow, $agentCashFlow, $orderType, $agentCashFlowOrderType] = $this->createDefaultCashFlow(
            $this->agent,
            CashFlowEnum::DEPOSIT_AUTO_DEPOSIT,
            CashFlowTypeEnum::AUTO_DEPOSIT->value
        );

        $bankAccount = BankAccount::factory()->create([
            'order_type_id' => $orderType->id,
            'is_deposit' => 1,
        ]);

        $mock = Mockery::mock(FileStorageService::class)->makePartial();
        $mock->shouldReceive('storeBase64Image')->andReturn('filePath');
        $this->app->instance(FileStorageService::class, $mock);

        // Act
        $createResponse = $this->requestApi(self::API_BANK_CREATE_ORDER, [
            'bank_id' => $bankAccount->id,
            'amount' => 100,
        ]);

        $orderId = $createResponse->json('data.order_id');

        $uploadImageResponse = $this->requestApi(self::API_BANK_UPLOAD_IMAGE, [
            'order_id' => $orderId,
            'image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ]);

        $uploadImageResponse->assertStatus(200);
        $uploadImageResponse->assertJsonStructure([
            'data' => [
                'order_no',
                'image',
            ],
        ]);

        $order = Order::where('member_id', $this->member->id)->first();
        $this->assertNotNull($order);
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('filePath', $order->orderImages->first()->image);
    }
}
