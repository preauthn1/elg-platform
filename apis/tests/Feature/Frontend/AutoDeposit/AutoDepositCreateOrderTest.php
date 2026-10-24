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

class AutoDepositCreateOrderTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected Vendor $vendor1;

    protected Vendor $vendor2;

    protected string $token;

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

    public function test_bank_order_success(): void
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
        $response = $this->requestApi(self::API_BANK_CREATE_ORDER, [
            'bank_id' => $bankAccount->id,
            'amount' => 100,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure($this->basicResponseDataStructure())
            ->assertJson([
                'data' => [
                    'bank_name' => $orderType->name,
                    'bank_code' => $orderType->key,
                    'account_name' => $bankAccount->account_name,
                    'account_no' => $bankAccount->account_no,
                    'amount' => 100,
                    'status' => 0,
                ],
            ]);

        $order = Order::where('member_id', $this->member->id)->first();
        $this->assertNotNull($order);
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
        $this->assertEquals(100, $order->amount);
        $this->assertEquals($bankAccount->rate, $order->rate);
        $this->assertEquals(Common::numMul(100, $bankAccount->rate, 4), $order->point);
        $this->assertEquals(0, $order->balance);
        $this->assertEquals('', $order->remark);
        $this->assertEquals(0, $order->admin);

        $orderImage = OrderImage::where('order_id', $order->id)->first();
        $this->assertNotNull($orderImage);
        $this->assertEquals($bankAccount->id, $orderImage->bank_id);
        $this->assertEquals($order->id, $orderImage->order_id);
        $this->assertEquals('', $orderImage->image);

        // 建單當下的銀行戶口快照 target/tickets#4545（MySQL 會重排 JSON key，故不比順序）
        $this->assertEquals([
            'bank_name' => $orderType->name,
            'bank_code' => $orderType->key,
            'account_nickname' => $bankAccount->account_nickname ?? '',
            'account_name' => $bankAccount->account_name,
            'account_no' => $bankAccount->account_no,
        ], $orderImage->bank_snapshot);

        // 戶口之後被改，已建訂單的快照不跟著變（審核頁才查得到會員當時轉到哪個帳戶）
        $bankAccount->update([
            'account_name' => 'CHANGED NAME',
            'account_no' => '9999999999',
        ]);

        $this->assertSame($orderType->name, $orderImage->refresh()->bank_snapshot['bank_name']);
        $this->assertSame('CHANGED NAME', $bankAccount->fresh()->account_name);
        $this->assertNotSame('CHANGED NAME', $orderImage->bank_snapshot['account_name']);
        $this->assertNotSame('9999999999', $orderImage->bank_snapshot['account_no']);
    }

    public function test_bank_order_success_with_member_has_order_status_created(): void
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
        $this->requestApi(self::API_BANK_CREATE_ORDER, [
            'bank_id' => $bankAccount->id,
            'amount' => 100,
        ]);
        $response = $this->requestApi(self::API_BANK_CREATE_ORDER, []);

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure($this->basicResponseDataStructure())
            ->assertJson([
                'data' => [
                    'bank_name' => $orderType->name,
                    'bank_code' => $orderType->key,
                    'account_name' => $bankAccount->account_name,
                    'account_no' => $bankAccount->account_no,
                    'amount' => 100,
                    'status' => 0,
                ],
            ]);
    }

    public function test_bank_order_failed_empty_reuqest_no_order(): void
    {
        $response = $this->requestApi(self::API_BANK_CREATE_ORDER, []);
        $response->assertStatus(400)
            ->assertJson([
                'code' => 400,
                'message' => __('error_message.1307'),
            ]);
    }

    public function test_bank_order_failed_duplicate_order(): void
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
        $this->requestApi(self::API_BANK_CREATE_ORDER, [
            'bank_id' => $bankAccount->id,
            'amount' => 100,
        ]);
        $response = $this->requestApi(self::API_BANK_CREATE_ORDER, [
            'bank_id' => $bankAccount->id,
            'amount' => 100,
        ]);

        // Assert
        $response->assertStatus(400)
            ->assertJson([
                'code' => 400,
                'message' => __('error_message.2043'),
            ]);
    }

    public function test_bank_order_failed_amount_out_of_range(): void
    {
        [$cashFlow, $agentCashFlow, $orderType, $agentCashFlowOrderType] = $this->createDefaultCashFlow(
            $this->agent,
            CashFlowEnum::DEPOSIT_AUTO_DEPOSIT,
            CashFlowTypeEnum::AUTO_DEPOSIT->value
        );

        $bankAccount = BankAccount::factory()->create([
            'order_type_id' => $orderType->id,
            'is_deposit' => 1,
            'min_amount' => 100,
            'max_amount' => 1000,
        ]);

        // Act
        $response = $this->requestApi(self::API_BANK_CREATE_ORDER, [
            'bank_id' => $bankAccount->id,
            'amount' => 10000,
        ]);

        // Assert
        $response->assertStatus(400)
            ->assertJson([
                'code' => 400,
                'message' => __('error_message.9997'),
            ]);
    }

    private function basicResponseDataStructure(): array
    {
        return [
            'code',
            'message',
            'data' => [
                'order_id',
                'order_no',
                'bank_name',
                'bank_code',
                'account_name',
                'account_no',
                'amount',
                'status',
            ],
        ];
    }
}
