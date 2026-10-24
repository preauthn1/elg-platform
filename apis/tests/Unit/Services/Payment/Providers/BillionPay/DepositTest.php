<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\BillionPay;

use App\Enums\DepositResponseType;
use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Providers\BillionPay\Deposit;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BillionPay 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositTest.php
 */
class DepositTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $info = [
            'SECRET_KEY' => 'test_secret_key',
            'USERNAME' => 'test_username',
            'PASSWORD' => 'test_password',
            'LOGIN_URL' => 'https://example.invalid/api/login',
            'DEPOSIT_URL' => 'https://example.invalid/api/deposit',
            'WITHDRAWAL_URL' => 'https://example.invalid/api/withdrawal',
            'CURRENCY' => 'SGD',
        ];

        $this->agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->realnameValidated()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * 測試成功創建代收請求
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositTest.php --filter test_success
     */
    public function test_success()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'BillionPay - Card',
            'type' => 1,
            'status' => 1,
            'key' => 'card',
            'info' => '{}',
            'admin' => 0,
        ]);

        /**
         * @var Order $order
         */
        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '202512011764580556645493',
            'order_no' => 'BP123456789',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $capturedUrl = null;
        $capturedParams = null;

        $deposit = $this->mockDeposit(function ($url, $params) use (&$capturedUrl, &$capturedParams) {
            $capturedUrl = $url;
            $capturedParams = $params;

            $json = <<<JSON
{
    "transaction_id":"2025112900000020",
    "internal_id":"202512011764580556645493",
    "currency":"SGD",
    "amount":"10000.00",
    "method":"card",
    "bank_account_number":"7053710936",
    "bank_name":"CIMB",
    "bank_account_name":"Ckw",
    "uen":"10000",
    "qr":"https:\/\/example.invalid\/development\/bank_qr\/100751966595.jpg",
    "shift":null
}
JSON;

            return json_decode($json, true);
        });

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert - 響應
        $order->refresh();
        Http::assertSentCount(2);
        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertEquals(DepositResponseType::QR_CODE_IMAGE_PATH, $result->type);
        $this->assertEquals(
            'https://example.invalid/development/bank_qr/100751966595.jpg',
            $result->url
        );
        $this->assertEquals('BP123456789', $result->orderNo);

        // Assert - 請求 URL
        $this->assertEquals('https://example.invalid/api/deposit', $capturedUrl);

        // Assert - 請求參數
        $this->assertEquals('100.00', $capturedParams['amount']);
        $this->assertEquals('https://example.invalid/callback', $capturedParams['callback_url']);
        $this->assertEquals('BP123456789', $capturedParams['transaction_id']); // transaction_id = order_no
        $this->assertEquals('SGD', $capturedParams['currency']); // currency = order_no
        $this->assertEquals('card', $capturedParams['method']);
        $this->assertEquals($this->member->username, $capturedParams['customer_id']);
        $this->assertEquals('202512011764580556645493', $order->third_party_order_no);

        // Assert - 簽名應存在且為小寫 MD5（32 個字符）
        $this->assertNotNull($capturedParams['signature'] ?? null);
        $this->assertEquals(32, strlen($capturedParams['signature']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $capturedParams['signature']);
    }

    /**
     * 測試 API 返回錯誤代碼
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositTest.php --filter test_api_error_response
     */
    public function test_api_error_response()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => 'card',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'BP123456790',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $deposit = $this->mockDeposit(['message' => 'Insufficient balance']);

        // Assert & Act
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('BillionPay deposit error: Insufficient balance');
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試 API 返回缺少 qr 欄位且無 message，應使用預設錯誤訊息
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositTest.php --filter test_missing_code_in_response
     */
    public function test_missing_code_in_response()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => 'card',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'BP123456791',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        // 空響應：無 qr 也無 message，應回傳預設錯誤訊息
        $deposit = $this->mockDeposit([]);

        // Assert & Act
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('BillionPay deposit error: Unknown error');
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試 sendRequest HTTP 錯誤時原樣拋出 RequestException（不轉包 ApiException，避免告警盲區）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositTest.php --filter test_send_request_exception
     */
    public function test_send_request_exception()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => 'card',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'BP123456792',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        // 500 觸發 ->throw()，sendRequest 不再轉包，原樣拋出 RequestException
        $deposit = $this->mockDeposit([], 500);

        // Assert & Act
        $this->expectException(RequestException::class);
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試金額格式處理（小數點）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositTest.php --filter test_amount_with_decimals
     */
    public function test_amount_with_decimals()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Card',
            'type' => 1,
            'status' => 1,
            'key' => 'card',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'BPDECIMAL',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '123.45', // 含小數點的金額
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $actualParams = null;

        $deposit = $this->mockDeposit(function ($url, $params) use (&$actualParams) {
            $actualParams = $params;

            return ['internal_id' => '202512011764563595028682', 'qr' => 'test_qr_code'];
        });

        // Act
        $deposit->handle($depositRequestData);

        // Assert - 金額應保留小數點
        $this->assertEquals('123.45', $actualParams['amount']);
    }

    /**
     * 測試 qr 欄位為 null 時應返回空字串
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositTest.php --filter test_null_qr
     */
    public function test_null_qr()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Card',
            'type' => 1,
            'status' => 1,
            'key' => 'card',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'BPNULLQR',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        // qr key 存在但值為 null
        $deposit = $this->mockDeposit(['internal_id' => '202512011764563595028682', 'qr' => null]);

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert - qr 為 null 時應返回空字串
        $this->assertEquals('', $result->url);
    }

    /**
     * 測試大額金額處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositTest.php --filter test_large_amount
     */
    public function test_large_amount()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Card',
            'type' => 1,
            'status' => 1,
            'key' => 'card',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'BPLARGE',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '999999.99', // 大額金額
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $actualParams = null;

        $deposit = $this->mockDeposit(function ($url, $params) use (&$actualParams) {
            $actualParams = $params;

            return ['internal_id' => '202512011764563595028682', 'qr' => 'test_qr_code'];
        });

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert
        $this->assertEquals('999999.99', $actualParams['amount']);
        $this->assertInstanceOf(DepositResponseData::class, $result);
    }

    /**
     * Mock 代收請求
     */
    private function mockDeposit(array|callable $mockResponse, int $status = 200): Deposit
    {
        Http::fake([
            'https://example.invalid/api/login' => Http::response([
                'bearer_token' => 'test_bearer_token',
                'token_type' => 'Bearer',
                'expires_at' => '3024-10-30 13:48:25',
            ]),
            'https://example.invalid/api/deposit' => function ($request) use ($mockResponse, $status) {
                if (is_callable($mockResponse)) {
                    return Http::response($mockResponse($request->url(), $request->data()));
                }

                return Http::response($mockResponse, $status);
            },
        ]);

        return App::make(Deposit::class, ['agentCashFlow' => $this->agentCashFlow]);
    }
}
