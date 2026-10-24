<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\AutoDeposit;

use App\Enums\CashFlowEnum;
use App\Enums\CashFlowTypeEnum;
use App\Enums\OrderStatusEnum;
use App\Helpers\Common;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\BankAccount;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderImage;
use App\Models\Vendor;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class AutoDepositDeleteOrderTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected Vendor $vendor1;

    protected Vendor $vendor2;

    protected string $token;

    const API_BANK_DELETE_ORDER = '/frontend/api/auto-deposit/order/delete';

    const API_BANK_CREATE_ORDER = '/frontend/api/auto-deposit/order/create';

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

    public function test_bank_delete_order_success(): void
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

        // Act
        $createResponse = $this->requestApi(self::API_BANK_CREATE_ORDER, [
            'bank_id' => $bankAccount->id,
            'amount' => 100,
        ]);

        $orderId = $createResponse->json('data.order_id');

        $deleteResponse = $this->requestApi(self::API_BANK_DELETE_ORDER, [
            'order_id' => $orderId,
        ]);

        $deleteResponse->assertStatus(200);

        $order = Order::where('member_id', $this->member->id)->first();
        $this->assertNotNull($order);
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status);

        // Assert
        // $response->assertStatus(200)
        //     ->assertJsonStructure($this->basicResponseDataStructure())
        //     ->assertJson([
        //         'data' => [
        //             'bank_name' => $orderType->name,
        //             'bank_code' => $orderType->key,
        //             'account_name' => $bankAccount->account_name,
        //             'account_no' => $bankAccount->account_no,
        //             'amount' => 100,
        //             'status' => 0,
        //         ],
        //     ]);

        // $order = Order::where('member_id', $this->member->id)->first();
        // $this->assertNotNull($order);
        // $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
        // $this->assertEquals(100, $order->amount);
        // $this->assertEquals($bankAccount->rate, $order->rate);
        // $this->assertEquals(Common::numMul(100, $bankAccount->rate, 4), $order->point);
        // $this->assertEquals(0, $order->balance);
        // $this->assertEquals('', $order->remark);
        // $this->assertEquals(0, $order->admin);

        // $orderImage = OrderImage::where('order_id', $order->id)->first();
        // $this->assertNotNull($orderImage);
        // $this->assertEquals($bankAccount->id, $orderImage->bank_id);
        // $this->assertEquals($order->id, $orderImage->order_id);
        // $this->assertEquals('', $orderImage->image);
    }
}
