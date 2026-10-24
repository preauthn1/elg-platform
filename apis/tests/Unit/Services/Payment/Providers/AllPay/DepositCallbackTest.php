<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\AllPay;

use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\AllPay\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * AllPay 代收回調的空憑證守衛。
 *
 * 回歸背景：makeSignature() 以 md5(...&key:{MerchantKey}) 驗簽，MerchantKey 為空字串時
 * 簽名字串只由回調自身內容組成、可離線計算；緊接著的商戶號比對又會因為
 * '' !== '' 為 false 而一併失守 ⇒ 空憑證期間回調可被完整偽造並上分。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/AllPay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private const NOTIFY_TIME = '20260101120000';

    private const VERSION = '1.0';

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
            'admin' => 0,
        ]);
    }

    private function makeAgentCashFlow(array $info): AgentCashFlow
    {
        $agentCashFlow = new AgentCashFlow([
            'currency_id' => 1,
            'cash_flow_id' => 3,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $info;

        return $agentCashFlow;
    }

    private function createOrder(): Order
    {
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 3, 'currency_id' => 1, 'key' => 'ALLPAYQR'],
            [
                'name' => 'AllPay Callback Test',
                'type' => 1,
                'status' => 1,
                'info' => '{}',
                'admin' => 0,
            ]
        );

        return Order::create([
            // 回調檢查比對訂單 ACF 的 cash_flow_id，訂單須掛在同一家金流商的 ACF 下
            'agent_cash_flow_id' => $this->createAgentCashFlow($this->agent, ['cash_flow_id' => 3])->id,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'amount' => '100.00',
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'AP' . uniqid(),
            'status' => OrderStatusEnum::CREATED->value,
            'rate' => 1,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);
    }

    /**
     * 以指定的 key 組出 AllPay 合法回調 payload（與 AllPayTrait::makeSignature() 同規則）
     */
    private function buildNotifyPayload(array $content, string $merchantKey): array
    {
        $fields = [
            'content' => base64_encode(json_encode($content)),
            'notifyTime' => self::NOTIFY_TIME,
            'version' => self::VERSION,
        ];

        $signStr = '';
        foreach ($fields as $key => $value) {
            $signStr .= $key . ':' . $value . '&';
        }
        $signStr .= 'key:' . $merchantKey;

        $fields['signMsg'] = strtoupper(md5($signStr));

        return $fields;
    }

    /**
     * 憑證齊備時，正常成功回調仍應被受理（確認守衛沒有誤擋）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AllPay/DepositCallbackTest.php --filter test_configured_credentials_still_accept_valid_callback
     */
    public function test_configured_credentials_still_accept_valid_callback(): void
    {
        $order = $this->createOrder();

        $agentCashFlow = $this->makeAgentCashFlow([
            'APIUrl' => 'https://public.allpay.test/qrh5',
            'MerchantNo' => 'AP888888',
            'MerchantKey' => 'unit-test-merchant-key',
        ]);

        $payload = $this->buildNotifyPayload([
            'service' => 'QRH5',
            'merchantNo' => 'AP888888',
            'paymentCode' => 'ALLPAYQR',
            'status' => 'SUCCESS',
            'amount' => '100.00',
            'orderNo' => $order->order_no,
            'gbpOrderNo' => 'AP1000123',
        ], 'unit-test-merchant-key');

        $result = (new DepositCallback($agentCashFlow))->handle(
            Request::create('/callback', 'POST', $payload)
        );

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertSame('OK', $result->response);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
    }

    /**
     * 空憑證守衛：MerchantNo/MerchantKey 皆為空時，攻擊者可用空 key 離線算出
     * 「合法」簽名並送空 merchantNo 繞過商戶號比對。守衛須在驗簽前擋下，
     * 訂單不得被結算。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AllPay/DepositCallbackTest.php --filter test_empty_credentials_reject_forged_callback
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->createOrder();

        $agentCashFlow = $this->makeAgentCashFlow([
            'APIUrl' => 'https://public.allpay.test/qrh5',
            'MerchantNo' => '',
            'MerchantKey' => '',
        ]);

        // 攻擊者持有的資訊只有訂單號；簽名以空 key 離線算出，merchantNo 送空字串
        $payload = $this->buildNotifyPayload([
            'service' => 'QRH5',
            'merchantNo' => '',
            'paymentCode' => 'ALLPAYQR',
            'status' => 'SUCCESS',
            'amount' => '100.00',
            'orderNo' => $order->order_no,
            'gbpOrderNo' => 'FORGED0001',
        ], '');

        $result = (new DepositCallback($agentCashFlow))->handle(
            Request::create('/callback', 'POST', $payload)
        );

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertSame('Failure', $result->response);
        $this->assertSame('Credentials not configured', $result->errorMessage);

        // 守衛在訂單查找之前就中止，訂單維持原狀
        $order->refresh();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->status);
        $this->assertSame('', $order->third_party_order_no);
    }

    /**
     * 憑證只缺一半（MerchantKey 空、MerchantNo 有值）同樣要擋——
     * 簽名仍可離線計算，只是商戶號比對這道還在。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AllPay/DepositCallbackTest.php --filter test_partially_empty_credentials_are_also_rejected
     */
    public function test_partially_empty_credentials_are_also_rejected(): void
    {
        $order = $this->createOrder();

        $agentCashFlow = $this->makeAgentCashFlow([
            'APIUrl' => 'https://public.allpay.test/qrh5',
            'MerchantNo' => 'AP888888',
            'MerchantKey' => '',
        ]);

        $payload = $this->buildNotifyPayload([
            'service' => 'QRH5',
            'merchantNo' => 'AP888888',
            'paymentCode' => 'ALLPAYQR',
            'status' => 'SUCCESS',
            'amount' => '100.00',
            'orderNo' => $order->order_no,
            'gbpOrderNo' => 'FORGED0002',
        ], '');

        $result = (new DepositCallback($agentCashFlow))->handle(
            Request::create('/callback', 'POST', $payload)
        );

        $this->assertSame('Failure', $result->response);
        $this->assertSame('Credentials not configured', $result->errorMessage);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->status);
    }
}
