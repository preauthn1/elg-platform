<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayMate;

use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\PayMate\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * PayMate 代收回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayMate/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
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

    private function createOrder(): Order
    {
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 121, 'currency_id' => 1, 'key' => 'DUITNOWQR'],
            [
                'name' => 'PayMate DUITNOWQR',
                'status' => 1,
                'info' => '{}',
                'admin' => 0,
            ]
        );

        return Order::create([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'amount' => '100.00',
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'PM' . uniqid(),
            'status' => OrderStatusEnum::CREATED->value,
            'rate' => 1,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);
    }

    /**
     * 測試正常成功回調，訂單狀態更新為成功
     */
    public function test_success_callback_updates_order_to_success(): void
    {
        $order = $this->createOrder();

        $content = [
            'service' => 'QRH5',
            'merchantNo' => self::MERCHANT_NO,
            'paymentCode' => 'DUITNOWQR',
            'bankCode' => '',
            'status' => 'SUCCESS',
            'amount' => '100.00',
            'orderNo' => $order->order_no,
            'gbpOrderNo' => 'NOCA1000123',
            'createTime' => '20260101120000',
            'iplocation' => 'MY',
            'ipaddress' => '0.0.0.0',
        ];

        $payload = $this->buildNotifyPayload($content, self::MD5_KEY);
        $request = Request::create('/callback', 'POST', $payload);

        $depositCallback = new DepositCallback($this->agentCashFlow);
        $result = $depositCallback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertSame('OK', $result->response);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertSame('NOCA1000123', $order->third_party_order_no);
    }

    /**
     * 測試簽名驗證失敗，回傳錯誤響應
     */
    public function test_invalid_signature_returns_failure(): void
    {
        $content = [
            'merchantNo' => self::MERCHANT_NO,
            'status' => 'SUCCESS',
            'amount' => '100.00',
            'orderNo' => 'NON_EXIST',
        ];

        $payload = $this->buildNotifyPayload($content, self::MD5_KEY);
        $payload['signMsg'] = 'TAMPERED_SIGNATURE';

        $request = Request::create('/callback', 'POST', $payload);

        $depositCallback = new DepositCallback($this->agentCashFlow);
        $result = $depositCallback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertSame('Failure', $result->response);
    }

    /**
     * 測試重複回調（冪等性），已處理過的訂單再收到通知直接回傳成功不重複處理
     */
    public function test_duplicate_callback_is_idempotent(): void
    {
        $order = $this->createOrder();
        $order->update(['status' => OrderStatusEnum::SUCCESS->value]);

        $content = [
            'merchantNo' => self::MERCHANT_NO,
            'status' => 'SUCCESS',
            'amount' => '100.00',
            'orderNo' => $order->order_no,
            'gbpOrderNo' => 'NOCA1000123',
        ];

        $payload = $this->buildNotifyPayload($content, self::MD5_KEY);
        $request = Request::create('/callback', 'POST', $payload);

        $depositCallback = new DepositCallback($this->agentCashFlow);
        $result = $depositCallback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertSame('OK', $result->response);
    }

    /**
     * 測試金額不符，訂單標記為待人工確認
     */
    public function test_amount_mismatch_marks_order_for_manual_review(): void
    {
        $order = $this->createOrder();

        $content = [
            'merchantNo' => self::MERCHANT_NO,
            'status' => 'SUCCESS',
            'amount' => '50.00', // 金額不符
            'orderNo' => $order->order_no,
            'gbpOrderNo' => 'NOCA1000124',
        ];

        $payload = $this->buildNotifyPayload($content, self::MD5_KEY);
        $request = Request::create('/callback', 'POST', $payload);

        $depositCallback = new DepositCallback($this->agentCashFlow);
        $depositCallback->handle($request);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
    }

    /**
     * 空憑證守衛：MERCHANT_NO／MD5_KEY 皆為空時（建 acf 後尚未填憑證的窗口），
     * generateSign() 的 key 段為空 ⇒ 簽名可離線計算，:58 的 merchantNo 比對又會
     * 因為 '' !== '' 為 false 而一併失守。守衛須在驗簽前擋下，訂單不得被結算。
     *
     * 本 Provider 由 SpeedPay 零複製共用，故守衛取的是 $merchantNoField／$md5KeyField
     * 兩個屬性而非寫死鍵名。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->createOrder();

        $emptyInfo = [
            'API_URL' => 'https://api.paymate.test',
            'MERCHANT_NO' => '',
            'MD5_KEY' => '',
        ];

        $agentCashFlow = new AgentCashFlow([
            'currency_id' => 1,
            'cash_flow_id' => 121,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'info' => json_encode($emptyInfo),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $emptyInfo;

        // 攻擊者以空 MD5_KEY 離線算出簽名，並送空 merchantNo 繞過商戶號比對
        $payload = $this->buildNotifyPayload([
            'service' => 'QRH5',
            'merchantNo' => '',
            'paymentCode' => 'DUITNOWQR',
            'bankCode' => '',
            'status' => 'SUCCESS',
            'amount' => '100.00',
            'orderNo' => $order->order_no,
            'gbpOrderNo' => 'FORGED0001',
            'createTime' => '20260101120000',
            'iplocation' => 'MY',
            'ipaddress' => '0.0.0.0',
        ], '');

        $result = (new DepositCallback($agentCashFlow))->handle(
            Request::create('/callback', 'POST', $payload)
        );

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertSame('Failure', $result->response);
        $this->assertSame('Credentials not configured', $result->errorMessage);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->status);
        $this->assertSame('', $order->third_party_order_no);
    }
}
