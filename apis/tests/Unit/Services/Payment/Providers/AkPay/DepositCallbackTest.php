<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\AkPay;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\AkPay\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * AkPay 代收回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/AkPay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private OrderType $orderType;

    private const MERCHANT_CODE = 'AKTEST';

    private const MERCHANT_TOKEN = [REDACTED]';

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
            'MERCHANT_TOKEN' => self::MERCHANT_TOKEN,
            'MERCHANT_CODE' => self::MERCHANT_CODE,
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 150,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);

        $this->orderType = OrderType::create([
            'cash_flow_id' => 150,
            'currency_id' => 1,
            'name' => 'AkPay Deposit MYR',
            'type' => 1,
            'status' => 1,
            'key' => 'FPX',
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    /**
     * 根據 source_id、tx_id、amount、currency、token 產生合法的 secret_key
     * 與 AkPayTrait::generateSecretKey() 邏輯一致
     */
    private function generateSecretKey(
        string $sourceId,
        int|string $txId,
        string $amount,
        string $currency,
        string $token
    ): string {
        $encrypt = [
            'source_id' => $sourceId,
            'tx_id' => $txId,
            'amount' => $amount,
            'currency' => $currency,
            'token' => $token,
        ];

        return md5(urldecode(http_build_query($encrypt)));
    }

    /**
     * 建立代收訂單
     */
    private function createOrder(string $orderNo, string $amount = '100.00'): Order
    {
        return Order::create([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $this->orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => $orderNo,
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => $amount,
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);
    }

    /**
     * 建立回調 Request（自動計算合法 secret_key，傳入 secret_key 時強制覆寫）
     */
    private function createRequest(array $overrides = []): Request
    {
        $sourceId = $overrides['source_id'] ?? ('AKPAY_CB_DEFAULT');
        $txId = $overrides['tx_id'] ?? '99000';
        $amount = $overrides['amount'] ?? '100.00';
        $currency = $overrides['currency'] ?? 'MYR';

        $data = array_merge([
            'source_id' => $sourceId,
            'tx_id' => $txId,
            'status' => 'Completed',
            'bank_code' => 'FPX',
            'amount' => $amount,
            'actual_amount' => $amount,
            'currency' => $currency,
        ], $overrides);

        // 若外部未明確帶入 secret_key，則依最終 data 重新計算
        if (!array_key_exists('secret_key', $overrides)) {
            $data['secret_key'] = $this->generateSecretKey(
                $data['source_id'],
                $data['tx_id'],
                $data['amount'],
                $data['currency'],
                self::MERCHANT_TOKEN,
            );
        }

        return Request::create('/callback', 'POST', $data);
    }

    /**
     * 測試 status=Completed 時，訂單更新為成功
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/DepositCallbackTest.php --filter test_completed_status_updates_order_to_success
     */
    public function test_completed_status_updates_order_to_success(): void
    {
        $order = $this->createOrder('AKPAY_CB_001', '100.00');
        $sourceId = 'AKPAY_CB_001';

        $request = $this->createRequest([
            'source_id' => $sourceId,
            'tx_id' => '10001',
            'status' => 'Completed',
            'amount' => '100.00',
        ]);

        $result = (new DepositCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals(['success' => true], $result->response);
        $this->assertNotNull($result->order);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('10001', $order->third_party_order_no);
    }

    /**
     * 測試非 Completed 狀態（Failed）時，訂單更新為失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/DepositCallbackTest.php --filter test_failed_status_updates_order_to_failed
     */
    public function test_failed_status_updates_order_to_failed(): void
    {
        $order = $this->createOrder('AKPAY_CB_002', '100.00');
        $sourceId = 'AKPAY_CB_002';

        $request = $this->createRequest([
            'source_id' => $sourceId,
            'tx_id' => '10002',
            'status' => 'Failed',
            'amount' => '100.00',
        ]);

        $result = (new DepositCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals(['success' => true], $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status);
        $this->assertEquals('10002', $order->third_party_order_no);
    }

    /**
     * 測試 status=Cancelled 時，訂單更新為失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/DepositCallbackTest.php --filter test_cancelled_status_updates_order_to_failed
     */
    public function test_cancelled_status_updates_order_to_failed(): void
    {
        $order = $this->createOrder('AKPAY_CB_003', '100.00');
        $sourceId = 'AKPAY_CB_003';

        $request = $this->createRequest([
            'source_id' => $sourceId,
            'tx_id' => '10003',
            'status' => 'Cancelled',
            'amount' => '100.00',
        ]);

        $result = (new DepositCallback($this->agentCashFlow))->handle($request);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status);
    }

    /**
     * 測試簽名無效時，應拋出 ApiException
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/DepositCallbackTest.php --filter test_invalid_signature_throws_exception
     */
    public function test_invalid_signature_throws_exception(): void
    {
        $this->createOrder('AKPAY_CB_004', '100.00');
        $sourceId = 'AKPAY_CB_004';

        // 強制傳入錯誤的 secret_key，使驗證失敗
        $request = $this->createRequest([
            'source_id' => $sourceId,
            'tx_id' => '10004',
            'status' => 'Completed',
            'amount' => '100.00',
            'secret_key' => 'INVALID_SECRET_KEY',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Signature verification failed');

        (new DepositCallback($this->agentCashFlow))->handle($request);
    }

    /**
     * 測試訂單已成功時重複回調（冪等性），應返回成功響應且訂單狀態不變
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/DepositCallbackTest.php --filter test_duplicate_callback_on_success_order_returns_success
     */
    public function test_duplicate_callback_on_success_order_returns_success(): void
    {
        $order = $this->createOrder('AKPAY_CB_005', '100.00');

        // 模擬訂單已上分成功
        $order->update(['status' => OrderStatusEnum::SUCCESS->value]);

        $sourceId = 'AKPAY_CB_005';
        $request = $this->createRequest([
            'source_id' => $sourceId,
            'tx_id' => '10005',
            'status' => 'Completed',
            'amount' => '100.00',
        ]);

        $result = (new DepositCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals(['success' => true], $result->response);

        // 訂單狀態不應被重新覆寫
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
    }

    /**
     * 空憑證守衛：MERCHANT_TOKEN 為空時 generateSecretKey() 的 token 段為空、簽名可離線計算，
     * 攻擊者能偽造成功回調直接上分。守衛須在驗簽前先擋下（拋 ApiException），且訂單維持 CREATED。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->createOrder('AKPAY_CB_EMPTY', '100.00');

        $emptyInfo = ['MERCHANT_TOKEN' => '', 'MERCHANT_CODE' => self::MERCHANT_CODE];
        $emptyAcf = new AgentCashFlow([
            'cash_flow_id' => 150,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($emptyInfo),
            'status' => 1,
            'admin' => 0,
        ]);
        $emptyAcf->info = $emptyInfo;

        // 攻擊者以空 token 自算 secret_key
        $forgedSecret = [REDACTED]'AKPAY_CB_EMPTY', '10001', '100.00', 'MYR', '');
        $request = Request::create('/callback', 'POST', [
            'source_id' => 'AKPAY_CB_EMPTY',
            'tx_id' => '10001',
            'status' => 'Completed',
            'bank_code' => 'FPX',
            'amount' => '100.00',
            'actual_amount' => '100.00',
            'currency' => 'MYR',
            'secret_key' => $forgedSecret,
        ]);

        try {
            (new DepositCallback($emptyAcf))->handle($request);
            $this->fail('空憑證回調應被守衛拒絕');
        } catch (ApiException $e) {
            $this->assertSame('Signature verification failed', $e->getMessage());
        }

        $order->refresh();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->status);
    }
}
