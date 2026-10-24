<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\DgPay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Providers\DgPay\Deposit;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DgPay 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php
 */
class DepositTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    /** V1 MYR 基礎 info（providerId=5, providerType=10 → Route 1 銀行轉帳） */
    private array $infoV1Myr = [
        'APIUrl' => 'https://dgpayapi.test',
        'Merchant' => 'dgpay_test',
        'MerchantKey' => 'test-merchant-key-abc',
        'Currency' => 'MYR',
        'PROVIDER_ID' => '5',
        'PROVIDER_TYPE' => '10',
    ];

    /** V1 MYR 動態路由 info（providerId=0, providerType=0 → E-Wallet 動態解析） */
    private array $infoV1MyrDynamic = [
        'APIUrl' => 'https://dgpayapi.test',
        'Merchant' => 'dgpay_test',
        'MerchantKey' => 'test-merchant-key-abc',
        'Currency' => 'MYR',
        'PROVIDER_ID' => '0',
        'PROVIDER_TYPE' => '0',
    ];

    /** V1 IDR OVO info（providerId=26, providerType=20 → 直接成功） */
    private array $infoV1IdrOvo = [
        'APIUrl' => 'https://dgpayapi.test',
        'Merchant' => 'dgpay_test',
        'MerchantKey' => 'test-merchant-key-abc',
        'Currency' => 'IDR',
        'PROVIDER_ID' => '26',
        'PROVIDER_TYPE' => '20',
    ];

    /** V1 IDR QRIS info */
    private array $infoV1IdrQris = [
        'APIUrl' => 'https://dgpayapi.test',
        'Merchant' => 'dgpay_test',
        'MerchantKey' => 'test-merchant-key-abc',
        'Currency' => 'IDR',
        'PROVIDER_ID' => '10',
        'PROVIDER_TYPE' => '10',
    ];

    /** V1 IDR 動態路由 info（providerId=0, providerType=0 → channel key 解析） */
    private array $infoV1IdrDynamic = [
        'APIUrl' => 'https://dgpayapi.test',
        'Merchant' => 'dgpay_test',
        'MerchantKey' => 'test-merchant-key-abc',
        'Currency' => 'IDR',
        'PROVIDER_ID' => '0',
        'PROVIDER_TYPE' => '0',
    ];

    /** V2 SGD info */
    private array $infoV2Sgd = [
        'APIUrl' => 'https://dgpayapi.test',
        'VERSION' => 'V2',
        'Merchant' => 'dgpay_test',
        'MerchantKey' => 'test-merchant-key-abc',
        'Currency' => 'SGD',
        'PAYMENT_CODE' => 'SGD_BANK',
        'PROVIDER_ID' => '0',
        'PROVIDER_TYPE' => '0',
    ];

    /** V2 SGD 動態路由 info（PAYMENT_CODE 空 → channel key 解析 paymentType） */
    private array $infoV2SgdDynamic = [
        'APIUrl' => 'https://dgpayapi.test',
        'VERSION' => 'V2',
        'Merchant' => 'dgpay_test',
        'MerchantKey' => 'test-merchant-key-abc',
        'Currency' => 'SGD',
        'PAYMENT_CODE' => '',
        'PROVIDER_ID' => '0',
        'PROVIDER_TYPE' => '0',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'mobile' => '0123456789',
            'email' => 'user@example.invalid',
            'admin' => 0,
        ]);
    }

    // ─────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────

    private function makeAgentCashFlow(array $info): AgentCashFlow
    {
        $cashFlow = new AgentCashFlow([
            'cash_flow_id' => 99,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $cashFlow->info = $info;

        return $cashFlow;
    }

    private function makeOrderType(string $key, int $currencyId = 1): OrderType
    {
        return OrderType::create([
            'cash_flow_id' => 99,
            'currency_id' => $currencyId,
            'name' => 'DgPay Test ' . $key,
            'type' => 1,
            'status' => 1,
            'key' => $key,
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    private function makeChildOrderType(OrderType $parent, string $key): OrderType
    {
        return OrderType::create([
            'cash_flow_id' => $parent->cash_flow_id,
            'parent' => $parent->id,
            'currency_id' => $parent->currency_id,
            'name' => 'DgPay Test Bank ' . $key,
            'type' => $parent->type,
            'status' => 1,
            'key' => $key,
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    private function makeOrder(OrderType $orderType, string $orderNo, array $extra = []): Order
    {
        return Order::create(array_merge([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'amount' => 100,
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => $orderNo,
            'status' => 1,
            'admin' => 0,
            'third_party_order_no' => '',
            'rate' => 1,
        ], $extra));
    }

    private function makeRequestData(Order $order): DepositRequestData
    {
        return DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test/dgpay',
            'returnUrl' => 'https://return.test/dgpay',
            'ip' => '0.0.0.0',
        ]);
    }

    private function makeAgentRequestData(Order $order): DepositRequestData
    {
        return DepositRequestData::from([
            'member' => $this->agent,
            'order' => $order,
            'callbackUrl' => 'https://callback.test/dgpay',
            'returnUrl' => 'https://return.test/dgpay',
            'ip' => '0.0.0.0',
        ]);
    }

    private function successResponse(string $paymentUrl = 'https://pay.dgpay.test/checkout'): array
    {
        return [
            'code' => '0',
            'description' => 'Success',
            'refId' => 'REF_001',
            'providerRefId' => 'PREF_001',
            'paymentUrl' => $paymentUrl,
            'merchantOrderId' => 'ORDER_001',
            'amount' => 100,
        ];
    }

    // ─────────────────────────────────────────
    // Tests
    // ─────────────────────────────────────────

    /**
     * V1 MYR Route 1 銀行轉帳：成功回傳 URL_REDIRECT
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_myr_bank_transfer_returns_url_redirect
     */
    public function test_v1_myr_bank_transfer_returns_url_redirect(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('CIMB');
        $order = $this->makeOrder($orderType, 'DGPAY_MYR_001');

        Http::fake([
            'https://dgpayapi.test/*' => Http::response($this->successResponse(), 200),
        ]);

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1Myr));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
        $this->assertEquals('https://pay.dgpay.test/checkout', $result->url);
        $this->assertEquals($order->order_no, $result->orderNo);
        $this->assertEquals('PREF_001', $result->providerOrderNo);
    }

    /**
     * V1 MYR Route 1 銀行轉帳，發起方為 Agent（代理商自儲值）：DepositRequestData::member 型別為
     * Member|Agent，DgPay 的 getRequestData/buildXxxRequestData 系列須同時接受兩者，不得只收窄 Member
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_myr_bank_transfer_accepts_agent_as_member
     */
    public function test_v1_myr_bank_transfer_accepts_agent_as_member(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('CIMB');
        $order = $this->makeOrder($orderType, 'DGPAY_AGENT_001');

        Http::fake([
            'https://dgpayapi.test/*' => Http::response($this->successResponse(), 200),
        ]);

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1Myr));

        // Act
        $result = $deposit->handle($this->makeAgentRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V1 MYR Route 1 銀行轉帳：驗證請求帶入 fromBankCode
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_myr_bank_transfer_sends_from_bank_code
     */
    public function test_v1_myr_bank_transfer_sends_from_bank_code(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('CIMB');
        $order = $this->makeOrder($orderType, 'DGPAY_MYR_002');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertArrayHasKey('data', $data);

            $requestData = json_decode($data['data'], true);
            $this->assertArrayHasKey('fromBankCode', $requestData);
            $this->assertSame('CIMB', $requestData['fromBankCode']);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1Myr));

        // Act & Assert (no exception = pass)
        $result = $deposit->handle($this->makeRequestData($order));

        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V1 MYR E-Wallet 動態路由（TNG）：驗證自動解析 providerId/providerType
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_myr_ewallet_dynamic_provider_tng
     */
    public function test_v1_myr_ewallet_dynamic_provider_tng(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('TNG');
        $order = $this->makeOrder($orderType, 'DGPAY_TNG_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            // 動態解析後 TNG → providerId=17, providerType=12
            $this->assertSame('17', (string) $data['providerId']);
            $this->assertSame('12', (string) $data['providerType']);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1MyrDynamic));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V1 MYR E-Wallet 動態路由（DUI Duit Now）：驗證自動解析 providerId/providerType
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_myr_ewallet_dynamic_provider_dui
     */
    public function test_v1_myr_ewallet_dynamic_provider_dui(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('DUI');
        $order = $this->makeOrder($orderType, 'DGPAY_DUI_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            // DUI → providerId=17, providerType=11
            $this->assertSame('17', (string) $data['providerId']);
            $this->assertSame('11', (string) $data['providerType']);

            $requestData = json_decode($data['data'], true);
            $this->assertSame('DUI', $requestData['code']);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1MyrDynamic));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V1 IDR OVO：請求成功後直接回傳 SUCCESS（不需跳轉 URL）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_idr_ovo_returns_success_type
     */
    public function test_v1_idr_ovo_returns_success_type(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('OVO');
        $order = $this->makeOrder($orderType, 'DGPAY_OVO_001');

        Http::fake([
            'https://dgpayapi.test/*' => Http::response(
                array_merge($this->successResponse(''), ['paymentUrl' => null]),
                200
            ),
        ]);

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1IdrOvo));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert：OVO 特殊場景直接回傳 SUCCESS，不跳轉
        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertEquals(DepositResponseType::SUCCESS, $result->type);
        $this->assertSame('', $result->url);
        $this->assertEquals($order->order_no, $result->orderNo);
    }

    /**
     * V1 IDR QRIS：驗證請求帶入 accName
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_idr_qris_sends_acc_name
     */
    public function test_v1_idr_qris_sends_acc_name(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('QRIS');
        $order = $this->makeOrder($orderType, 'DGPAY_IDR_QRIS_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $requestData = json_decode($data['data'], true);
            $this->assertArrayHasKey('accName', $requestData);
            $this->assertSame($this->member->username, $requestData['accName']);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1IdrQris));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V2 SGD 銀行轉帳：使用 /v2/deposit 端點，sender 帶入存款人資訊
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v2_sgd_uses_v2_endpoint_and_sender_field
     */
    public function test_v2_sgd_uses_v2_endpoint_and_sender_field(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('DBS');
        $extraContent = json_encode([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'bank_number' => '1234567890',
        ]);
        $order = $this->makeOrder($orderType, 'DGPAY_SGD_001', [
            'extra_content' => $extraContent,
        ]);

        Http::fake(function (HttpRequest $request) {
            // 驗證使用 V2 端點
            $this->assertStringContainsString('/v2/deposit', $request->url());

            // 驗證 V2 必要欄位
            $data = $request->data();
            $this->assertArrayHasKey('paymentType', $data);
            $this->assertArrayHasKey('sender', $data);
            $this->assertArrayHasKey('redirectUrl', $data);

            $sender = json_decode($data['sender'], true);
            $this->assertSame('John', $sender['senderFirstName'] ?? '');
            $this->assertSame('Doe', $sender['senderLastName'] ?? '');
            $this->assertSame('1234567890', $sender['senderNo'] ?? '');
            $this->assertSame('DBS', $sender['bankCode'] ?? '');

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV2Sgd));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
        $this->assertNotEmpty($result->url);
    }

    /**
     * API 回傳 code != '0'：應拋出 ApiException 並帶入 description 訊息
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_api_error_code_throws_api_exception
     */
    public function test_api_error_code_throws_api_exception(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('CIMB');
        $order = $this->makeOrder($orderType, 'DGPAY_ERR_001');

        Http::fake([
            'https://dgpayapi.test/*' => Http::response([
                'code' => '1001',
                'description' => 'Invalid merchant',
            ], 200),
        ]);

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1Myr));

        // Act & Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid merchant');

        $deposit->handle($this->makeRequestData($order));
    }

    /**
     * API 回傳無 description/providerMsg：應拋出帶有預設訊息的 ApiException
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_api_error_without_description_uses_fallback_message
     */
    public function test_api_error_without_description_uses_fallback_message(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('CIMB');
        $order = $this->makeOrder($orderType, 'DGPAY_ERR_002');

        Http::fake([
            'https://dgpayapi.test/*' => Http::response([
                'code' => '9999',
            ], 200),
        ]);

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1Myr));

        // Act & Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unknown error');

        $deposit->handle($this->makeRequestData($order));
    }

    /**
     * HTTP 請求失敗（網路錯誤）：原樣拋出 RequestException（不轉包 ApiException，避免告警盲區）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_http_request_failure_throws_api_exception
     */
    public function test_http_request_failure_throws_api_exception(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('CIMB');
        $order = $this->makeOrder($orderType, 'DGPAY_ERR_003');

        Http::fake([
            'https://dgpayapi.test/*' => Http::response('Server Error', 500),
        ]);

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1Myr));

        // Act & Assert
        $this->expectException(RequestException::class);

        $deposit->handle($this->makeRequestData($order));
    }

    // ─────────────────────────────────────────
    // Channel key 路由（providerId_providerType 編碼於父層 order_type.key）
    // ─────────────────────────────────────────

    /**
     * V1 MYR Route47 QR TNG：channel key「49_15」解析出 providerId/providerType，
     * data 僅帶 language + redirectUrl（不含 username）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_channel_key_pair_resolves_route47_tng
     */
    public function test_v1_channel_key_pair_resolves_route47_tng(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('49_15');
        $order = $this->makeOrder($orderType, 'DGPAY_R47_TNG_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('49', (string) $data['providerId']);
            $this->assertSame('15', (string) $data['providerType']);

            $requestData = json_decode($data['data'], true);
            $this->assertSame('EN', $requestData['language'] ?? null);
            $this->assertSame('https://return.test/dgpay', $requestData['redirectUrl'] ?? null);
            $this->assertArrayNotHasKey('username', $requestData);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1MyrDynamic));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V1 MYR Route2 網銀：選中銀行子項時，provider pair 取自父層 channel key「5_10」，
     * fromBankCode 取自子項 key
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_channel_key_pair_from_parent_of_bank_child
     */
    public function test_v1_channel_key_pair_from_parent_of_bank_child(): void
    {
        // Arrange
        $parent = $this->makeOrderType('5_10');
        $child = $this->makeChildOrderType($parent, 'MAYB');
        $order = $this->makeOrder($child, 'DGPAY_R2_BANK_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('5', (string) $data['providerId']);
            $this->assertSame('10', (string) $data['providerType']);

            $requestData = json_decode($data['data'], true);
            $this->assertSame('MAYB', $requestData['fromBankCode'] ?? null);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1MyrDynamic));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V1 MYR Route47 QR KYC（49_10）：data 帶 language + username + redirectUrl
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_myr_route47_qr_kyc_sends_username
     */
    public function test_v1_myr_route47_qr_kyc_sends_username(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('49_10');
        $order = $this->makeOrder($orderType, 'DGPAY_R47_KYC_001');

        Http::fake(function (HttpRequest $request) {
            $requestData = json_decode($request->data()['data'], true);
            $this->assertSame('EN', $requestData['language'] ?? null);
            $this->assertSame($this->member->username, $requestData['username'] ?? null);
            $this->assertSame('https://return.test/dgpay', $requestData['redirectUrl'] ?? null);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1MyrDynamic));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V1 MYR Route47 P2P（49_30）：data 帶 username + redirectUrl + bankCode（子項 key），
     * 不含 language
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_myr_route47_p2p_data_shape
     */
    public function test_v1_myr_route47_p2p_data_shape(): void
    {
        // Arrange
        $parent = $this->makeOrderType('49_30');
        $child = $this->makeChildOrderType($parent, 'MBB');
        $order = $this->makeOrder($child, 'DGPAY_R47_P2P_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('49', (string) $data['providerId']);
            $this->assertSame('30', (string) $data['providerType']);

            $requestData = json_decode($data['data'], true);
            $this->assertSame($this->member->username, $requestData['username'] ?? null);
            $this->assertSame('https://return.test/dgpay', $requestData['redirectUrl'] ?? null);
            $this->assertSame('MBB', $requestData['bankCode'] ?? null);
            $this->assertArrayNotHasKey('language', $requestData);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1MyrDynamic));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V1 IDR Dana（55_21）：channel key 解析 provider pair，data 帶 accName + redirectUrl + phoneNum
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_idr_dana_channel_key_pair
     */
    public function test_v1_idr_dana_channel_key_pair(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('55_21');
        $order = $this->makeOrder($orderType, 'DGPAY_IDR_DANA_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('55', (string) $data['providerId']);
            $this->assertSame('21', (string) $data['providerType']);

            $requestData = json_decode($data['data'], true);
            $this->assertSame($this->member->username, $requestData['accName'] ?? null);
            $this->assertSame('https://return.test/dgpay', $requestData['redirectUrl'] ?? null);
            $this->assertSame($this->member->mobile, $requestData['phoneNum'] ?? null);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1IdrDynamic));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V1 IDR OVO（55_20）：現行 Provider Definition 的 OVO 走 55_20，
     * 發起成功後 App 直接彈通知，回傳 SUCCESS 不跳轉
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_idr_ovo_55_returns_success_type
     */
    public function test_v1_idr_ovo_55_returns_success_type(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('55_20');
        $order = $this->makeOrder($orderType, 'DGPAY_IDR_OVO55_001');

        Http::fake([
            'https://dgpayapi.test/*' => Http::response(
                array_merge($this->successResponse(''), ['paymentUrl' => null]),
                200
            ),
        ]);

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1IdrDynamic));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::SUCCESS, $result->type);
        $this->assertSame('', $result->url);
    }

    /**
     * V1 IDR Virtual Account（55_15）：bankCode 取自銀行子項 key（數字碼）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_idr_va_child_bank_code
     */
    public function test_v1_idr_va_child_bank_code(): void
    {
        // Arrange
        $parent = $this->makeOrderType('55_15');
        $child = $this->makeChildOrderType($parent, '014');
        $order = $this->makeOrder($child, 'DGPAY_IDR_VA_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('55', (string) $data['providerId']);
            $this->assertSame('15', (string) $data['providerType']);

            $requestData = json_decode($data['data'], true);
            $this->assertSame($this->member->username, $requestData['accName'] ?? null);
            $this->assertSame('014', $requestData['bankCode'] ?? null);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1IdrDynamic));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * Binding 靜態 PROVIDER_ID/TYPE 優先於 channel key：舊組（DG）配置行為不變
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_binding_static_provider_overrides_channel_key
     */
    public function test_binding_static_provider_overrides_channel_key(): void
    {
        // Arrange：binding 靜態 5/10，channel key 卻是 49_15 → 應以 binding 為準
        $orderType = $this->makeOrderType('49_15');
        $order = $this->makeOrder($orderType, 'DGPAY_STATIC_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('5', (string) $data['providerId']);
            $this->assertSame('10', (string) $data['providerType']);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1Myr));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * Binding PROVIDER_ID/TYPE 為空字串（模板 seed 預設）：等同 0，channel key 路由照常生效
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_empty_string_provider_info_falls_back_to_channel_key
     */
    public function test_empty_string_provider_info_falls_back_to_channel_key(): void
    {
        // Arrange：credentials 模板 seed 的 PROVIDER_ID/TYPE 是 ''（非 null），
        // strict_types 下不得炸 TypeError，且應視同 0 走 channel key 解析
        $info = array_merge($this->infoV1MyrDynamic, [
            'PROVIDER_ID' => '',
            'PROVIDER_TYPE' => '',
        ]);
        $orderType = $this->makeOrderType('49_20');
        $order = $this->makeOrder($orderType, 'DGPAY_EMPTY_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('49', (string) $data['providerId']);
            $this->assertSame('20', (string) $data['providerType']);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($info));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    // ─────────────────────────────────────────
    // V2 paymentType 動態解析（官方 SGD V2 §7 Payment Type Definition）
    // ─────────────────────────────────────────

    /**
     * V2 SGD P2P：binding PAYMENT_CODE 空時，paymentType 由父層 channel key 解析，
     * sender.bankCode 取銀行子項 key（官方 §5：required for P2P）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v2_sgd_p2p_payment_type_resolved_from_channel_parent_key
     */
    public function test_v2_sgd_p2p_payment_type_resolved_from_channel_parent_key(): void
    {
        // Arrange
        $parent = $this->makeOrderType('P2P');
        $child = $this->makeChildOrderType($parent, 'DBS');
        $order = $this->makeOrder($child, 'DGPAY_V2_P2P_001', [
            'extra_content' => json_encode([
                'first_name' => 'John',
                'last_name' => 'Doe',
                'bank_number' => '1234567890',
            ]),
        ]);

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('P2P', $data['paymentType'] ?? null);

            $sender = json_decode($data['sender'], true);
            $this->assertSame('DBS', $sender['bankCode'] ?? null);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV2SgdDynamic));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V2 SGD QR：純掃碼通道，sender 不帶 bankCode（官方 §5：bankCode 僅 P2P；§6 QR 段無銀行選項）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v2_sgd_qr_channel_omits_bank_code
     */
    public function test_v2_sgd_qr_channel_omits_bank_code(): void
    {
        // Arrange：QR 通道無銀行子項，orderType 即父層（key = paymentType）
        $orderType = $this->makeOrderType('QR');
        $order = $this->makeOrder($orderType, 'DGPAY_V2_QR_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('QR', $data['paymentType'] ?? null);

            $sender = json_decode($data['sender'], true);
            // orderType key 為 'QR'（paymentType），不是銀行碼，不得誤入 bankCode
            $this->assertArrayNotHasKey('bankCode', $sender);
            $this->assertSame($this->member->username, $sender['username'] ?? null);
            $this->assertSame($this->member->mobile, $sender['phone'] ?? null);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV2SgdDynamic));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V2 SGD QRKYC：sender 帶 username（官方 §5：only for QRKYC）、不帶 bankCode
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v2_sgd_qrkyc_channel_sends_username_without_bank_code
     */
    public function test_v2_sgd_qrkyc_channel_sends_username_without_bank_code(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('QRKYC');
        $order = $this->makeOrder($orderType, 'DGPAY_V2_QRKYC_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('QRKYC', $data['paymentType'] ?? null);

            $sender = json_decode($data['sender'], true);
            $this->assertArrayNotHasKey('bankCode', $sender);
            $this->assertSame($this->member->username, $sender['username'] ?? null);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV2SgdDynamic));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V2 binding PAYMENT_CODE 非空時優先於 channel key（鏡像 V1 靜態 PROVIDER_ID/TYPE 行為）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v2_binding_payment_code_overrides_channel_key
     */
    public function test_v2_binding_payment_code_overrides_channel_key(): void
    {
        // Arrange：binding PAYMENT_CODE='SGD_BANK'，channel parent key 卻是 P2P → 以 binding 為準
        $parent = $this->makeOrderType('P2P');
        $child = $this->makeChildOrderType($parent, 'UOB');
        $order = $this->makeOrder($child, 'DGPAY_V2_OVERRIDE_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('SGD_BANK', $data['paymentType'] ?? null);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV2Sgd));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V2 舊組相容：PAYMENT_CODE 空 + orderType key 非官方 paymentType（銀行碼）
     * → paymentType 維持空、bankCode 照舊送出（舊組 binding 行為不變）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v2_legacy_bank_key_keeps_empty_payment_type_and_bank_code
     */
    public function test_v2_legacy_bank_key_keeps_empty_payment_type_and_bank_code(): void
    {
        // Arrange：舊組 SGD binding 無 PAYMENT_CODE、orderType 直接是銀行碼（無父層）
        $orderType = $this->makeOrderType('DBS');
        $order = $this->makeOrder($orderType, 'DGPAY_V2_LEGACY_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            // 非白名單 key 不得誤解析成 paymentType
            $this->assertSame('', $data['paymentType'] ?? null);

            $sender = json_decode($data['sender'], true);
            // 舊組行為：bankCode 照舊送 orderType key
            $this->assertSame('DBS', $sender['bankCode'] ?? null);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV2SgdDynamic));

        // Act
        $result = $deposit->handle($this->makeRequestData($order));

        // Assert
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
    }

    /**
     * V1 簽名生成：驗證 securityToken 欄位存在且為小寫 MD5
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_v1_signature_is_lowercase_md5
     */
    public function test_v1_signature_is_lowercase_md5(): void
    {
        // Arrange
        $orderType = $this->makeOrderType('CIMB');
        $order = $this->makeOrder($orderType, 'DGPAY_SIG_001');

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertArrayHasKey('securityToken', $data);
            // MD5 小寫 hex 格式：32 個字元
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $data['securityToken']);

            return Http::response($this->successResponse(), 200);
        });

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1Myr));

        // Act
        $deposit->handle($this->makeRequestData($order));
    }

    /**
     * 三方回 code -100（bankCode not support）：拋 ApiException 帶固定泛化訊息，不洩三方原文
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_code_minus_100_throws_api_exception
     */
    public function test_code_minus_100_throws_api_exception(): void
    {
        $orderType = $this->makeOrderType('TNGP');
        $order = $this->makeOrder($orderType, 'DGPAY_CFG_001');

        Http::fake([
            'https://dgpayapi.test/*' => Http::response([
                'code' => '-100',
                'description' => 'Internal Server Error',
                'providerMsg' => 'bankCode not support',
                'providerRespond' => '997',
            ], 200),
        ]);

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1Myr));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Payment channel unavailable, please try another option');
        $deposit->handle($this->makeRequestData($order));
    }

    /**
     * 三方回 code -175（Have no allow accounts）：拋 ApiException 帶固定泛化訊息，不洩三方原文
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_code_minus_175_throws_api_exception
     */
    public function test_code_minus_175_throws_api_exception(): void
    {
        $orderType = $this->makeOrderType('TNGP');
        $order = $this->makeOrder($orderType, 'DGPAY_BUSY_001');

        Http::fake([
            'https://dgpayapi.test/*' => Http::response([
                'code' => '-175',
                'description' => 'Have no allow accounts for transactions',
            ], 200),
        ]);

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1Myr));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Payment channel busy, please try again later');
        $deposit->handle($this->makeRequestData($order));
    }

    /**
     * description 含 "currently in use"（帳戶忙碌變體）：拋 ApiException 帶固定泛化訊息，不洩三方原文
     *
     * php artisan test tests/Unit/Services/Payment/Providers/DgPay/DepositTest.php --filter test_accounts_in_use_throws_api_exception
     */
    public function test_accounts_in_use_throws_api_exception(): void
    {
        $orderType = $this->makeOrderType('TNGP');
        $order = $this->makeOrder($orderType, 'DGPAY_BUSY_002');

        Http::fake([
            'https://dgpayapi.test/*' => Http::response([
                'code' => '-200',
                'description' => 'All accounts for this amount (100) are currently in use, please try again later.',
            ], 200),
        ]);

        $deposit = new Deposit($this->makeAgentCashFlow($this->infoV1Myr));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Payment channel busy, please try again later');
        $deposit->handle($this->makeRequestData($order));
    }
}
