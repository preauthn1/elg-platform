<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayMate;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Providers\PayMate\Deposit;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PayMate 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayMate/DepositTest.php
 */
class DepositTest extends TestCase
{
    use PayMateSignatureHelper;

    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private const MERCHANT_NO = 'PM134126';

    private const MD5_KEY = 'c3f839790505a40973613d51bb8e2b8e';

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

        $info = [
            'API_URL' => 'https://api.paymate.test',
            'MERCHANT_NO' => self::MERCHANT_NO,
            'MD5_KEY' => self::MD5_KEY,
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => 1,
            'cash_flow_id' => 121,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    private function createOrder(string $paymentCode = 'P2PDEPOSIT'): Order
    {
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 121, 'currency_id' => 1, 'key' => $paymentCode],
            [
                'name' => 'PayMate ' . $paymentCode,
                'status' => 1,
                'info' => '{}',
                'admin' => 0,
            ]
        );

        return Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'amount' => 100,
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'PM' . uniqid(),
            'status' => 0,
            'rate' => 1,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);
    }

    /**
     * 測試成功建立代收請求，回傳 resultUrl
     */
    public function test_deposit_returns_redirect_url(): void
    {
        $order = $this->createOrder();

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://notify.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);

        $innerResponse = [
            'code' => '0000',
            'msg' => '訂單建立成功',
            'amount' => '100.00',
            'RealOrderAmount' => '100.00',
            'orderNo' => $order->order_no,
            'gbpOrderNo' => '190101185511101',
            'createTime' => '20260101120000',
            'resultUrl' => 'https://cashier.paymate.test/pay/abc123',
            'QrCode' => '',
        ];

        Http::fake([
            '*' => Http::response(
                $this->buildResponseEnvelope($innerResponse, self::MERCHANT_NO, self::MD5_KEY),
                200
            ),
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        $result = $deposit->handle($depositRequestData);

        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertSame('https://cashier.paymate.test/pay/abc123', $result->url);
        $this->assertSame($order->order_no, $result->orderNo);
        $this->assertSame('190101185511101', $result->providerOrderNo);

        // 驗證送往 PayMate 的 request 已依 api.md 1.1 正確加簽並 Base64 包裝
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['version'] === '1.0'
                && $body['merchantNo'] === self::MERCHANT_NO
                && isset($body['signMsg'], $body['request']);
        });
    }

    /**
     * 測試 paymentCode 對應到正確的下單端點（本商戶 PM134126 實際配置，落差見 SpeedPay/integration.md §3）：
     * DUITNOWP2P 走 /qrh5，P2PDEPOSIT 走 /cashier
     */
    public function test_deposit_routes_to_correct_endpoint_by_payment_code(): void
    {
        $cases = [
            'DUITNOWP2P' => '/qrh5',
            'P2PDEPOSIT' => '/cashier',
        ];

        foreach ($cases as $paymentCode => $expectedPath) {
            $order = $this->createOrder($paymentCode);

            $depositRequestData = DepositRequestData::from([
                'member' => $this->member,
                'order' => $order,
                'callbackUrl' => 'https://notify.test',
                'returnUrl' => 'https://return.test',
                'ip' => '0.0.0.0',
            ]);

            $innerResponse = [
                'code' => '0000',
                'msg' => '訂單建立成功',
                'orderNo' => $order->order_no,
                'gbpOrderNo' => 'G' . $order->id,
                'resultUrl' => 'https://cashier.paymate.test/pay/' . $order->id,
            ];

            Http::fake([
                '*' => Http::response(
                    $this->buildResponseEnvelope($innerResponse, self::MERCHANT_NO, self::MD5_KEY),
                    200
                ),
            ]);

            (new Deposit($this->agentCashFlow))->handle($depositRequestData);

            Http::assertSent(fn ($request) => str_ends_with($request->url(), $expectedPath));
        }
    }

    /**
     * 測試 info[ENDPOINT_BY_PAYMENT_CODE] 覆寫類別常數的端點對照
     *
     * SpeedPay 商戶（SE PayCenter 同協定另一組商戶，共用本 Driver）兩個通道皆走 /qrh5，
     * 與 PayMate 商戶 PM134126 的 P2PDEPOSIT => /cashier 不同，見 SpeedPay/integration.md §3
     */
    public function test_deposit_endpoint_map_in_info_overrides_class_default(): void
    {
        $this->setEndpointMap(['DUITNOWP2P' => '/qrh5', 'P2PDEPOSIT' => '/qrh5']);

        foreach (['DUITNOWP2P', 'P2PDEPOSIT'] as $paymentCode) {
            $this->handleDepositFor($paymentCode);

            Http::assertSent(fn ($request) => str_ends_with($request->url(), '/qrh5'));
        }
    }

    /**
     * 測試 info 的對照表未列入該 paymentCode 時，回退類別常數
     */
    public function test_deposit_falls_back_to_class_default_when_payment_code_absent_from_info_map(): void
    {
        // 只覆寫 DUITNOWP2P，P2PDEPOSIT 應回退類別常數的 /cashier
        $this->setEndpointMap(['DUITNOWP2P' => '/duitnow-custom']);

        $this->handleDepositFor('DUITNOWP2P');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/duitnow-custom'));

        $this->handleDepositFor('P2PDEPOSIT');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/cashier'));
    }

    /**
     * 測試 info 的對照表結構不合法時（非陣列、或值非字串）一律回退類別常數，不得拼出畸形 URL
     */
    public function test_deposit_ignores_malformed_endpoint_map_in_info(): void
    {
        foreach (['not-an-array', ['P2PDEPOSIT' => ['/qrh5']], ['P2PDEPOSIT' => null]] as $malformed) {
            $this->setEndpointMap($malformed);

            $this->handleDepositFor('P2PDEPOSIT');

            Http::assertSent(fn ($request) => str_ends_with($request->url(), '/cashier'));
        }
    }

    /**
     * 測試值是字串但不是合法相對路徑時，一律回退類別常數
     *
     * 這些值都能通過 is_string：完整 URL（ops 誤把舊系統存的含尾綴 URL 貼進對照表）、
     * 帶 userinfo 的值（會讓 API_URL . $path 拼成 https://user@example.invalid/x，
     * 把簽名與訂單參數送往他站）、protocol-relative 與缺開頭斜線者。
     */
    public function test_deposit_rejects_endpoint_values_that_are_not_relative_paths(): void
    {
        $invalidPaths = [
            'https://example.invalid/qrh5',
            '@evil.tld/qrh5',
            '/user@example.invalid',
            '//evil.tld/qrh5',
            'qrh5',
            '',
        ];

        foreach ($invalidPaths as $invalidPath) {
            $this->setEndpointMap(['P2PDEPOSIT' => $invalidPath]);

            $this->handleDepositFor('P2PDEPOSIT');

            Http::assertSent(fn ($request) => str_ends_with($request->url(), '/cashier'));
        }
    }

    /** 覆寫 agentCashFlow->info 的端點對照欄位 */
    private function setEndpointMap(mixed $endpointMap): void
    {
        $this->agentCashFlow->info = [
            'API_URL' => 'https://api.paymate.test',
            'MERCHANT_NO' => self::MERCHANT_NO,
            'MD5_KEY' => self::MD5_KEY,
            'ENDPOINT_BY_PAYMENT_CODE' => $endpointMap,
        ];
    }

    /** 以指定 paymentCode 建單並送出代收請求（Http::fake 回成功信封） */
    private function handleDepositFor(string $paymentCode): void
    {
        $order = $this->createOrder($paymentCode);

        $innerResponse = [
            'code' => '0000',
            'msg' => '訂單建立成功',
            'orderNo' => $order->order_no,
            'gbpOrderNo' => 'G' . $order->id,
            'resultUrl' => 'https://cashier.paymate.test/pay/' . $order->id,
        ];

        Http::fake([
            '*' => Http::response(
                $this->buildResponseEnvelope($innerResponse, self::MERCHANT_NO, self::MD5_KEY),
                200
            ),
        ]);

        (new Deposit($this->agentCashFlow))->handle(DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://notify.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]));
    }

    /**
     * 測試第三方回傳錯誤代碼，拋出 ApiException
     */
    public function test_deposit_throws_on_error_code(): void
    {
        $order = $this->createOrder();

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://notify.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);

        $innerResponse = [
            'code' => '0101',
            'msg' => '訂單建立錯誤',
        ];

        Http::fake([
            '*' => Http::response(
                $this->buildResponseEnvelope($innerResponse, self::MERCHANT_NO, self::MD5_KEY),
                200
            ),
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        $this->expectException(ApiException::class);
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試 info 未配置 DRIVER_LABEL 時，訊息沿用 Provider 目錄名（PayMate 商戶行為不變）
     */
    public function test_error_message_uses_provider_directory_name_without_label(): void
    {
        $this->expectExceptionMessage('PayMate deposit error: 訂單建立錯誤');

        $this->handleFailingDeposit();
    }

    /**
     * 測試 info 配置 DRIVER_LABEL 時，訊息改用該商戶名
     *
     * SpeedPay 共用 PayMate 實作，目錄名無法區分商戶；未覆寫時事故告警會指向 VWorld
     * 不存在的 PayMate 商戶（見 DriverTrait::resolveDriverLabelFromInfo）。
     */
    public function test_error_message_uses_driver_label_from_info_when_present(): void
    {
        $this->agentCashFlow->info = [
            ...$this->agentCashFlow->info,
            'DRIVER_LABEL' => 'SpeedPay',
        ];

        $this->expectExceptionMessage('SpeedPay deposit error: 訂單建立錯誤');

        $this->handleFailingDeposit();
    }

    /** 以三方失敗信封（code 0101）送出代收請求，供 driver 名稱斷言使用 */
    private function handleFailingDeposit(): void
    {
        $order = $this->createOrder();

        Http::fake([
            '*' => Http::response(
                $this->buildResponseEnvelope(
                    ['code' => '0101', 'msg' => '訂單建立錯誤'],
                    self::MERCHANT_NO,
                    self::MD5_KEY
                ),
                200
            ),
        ]);

        (new Deposit($this->agentCashFlow))->handle(DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://notify.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]));
    }

    /**
     * 測試網路逾時，原樣拋出 RequestException（不轉包 ApiException，避免告警盲區）
     */
    public function test_deposit_throws_on_network_timeout(): void
    {
        $order = $this->createOrder();

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://notify.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);

        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        $this->expectException(RequestException::class);
        $deposit->handle($depositRequestData);
    }
}
