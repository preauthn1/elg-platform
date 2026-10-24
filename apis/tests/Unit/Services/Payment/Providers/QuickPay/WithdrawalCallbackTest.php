<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\QuickPay;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\QuickPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * QuickPay 代付回調測試（純二分，無 INDETERMINATE 路徑）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/QuickPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    private string $secretKey = 'test_secret_key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'API_URL' => 'https://api.quickpay.test',
            'SECRET_KEY' => $this->secretKey,
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;
        $this->agentCashFlow->save();

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    private function createPayoutRecord(int $withdrawalOrderId, string $transactionNo, array $overrides = []): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create(array_merge([
            'cash_flow_id' => $this->agentCashFlow->cash_flow_id ?? 1,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $withdrawalOrderId,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => 'TPO_' . $transactionNo,
            'result_amount' => 0,
            'result_code' => '',
            'is_success_payout' => 0,
            'transaction_time' => '',
            'sign' => '',
            'payout_order_request_body' => '',
            'payout_order_response' => '',
            'callback_request_body' => '',
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
            'admin' => 0,
        ], $overrides));
    }

    private function createWithdrawalOrder(string $amount = '100.00'): WithdrawalOrder
    {
        $bank = Bank::firstOrCreate(['code' => 'CIMB'], [
            'name' => 'CIMB Bank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'CIMB',
            'bank_name' => 'CIMB Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => $amount,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }

    /**
     * 產生有效的 sign（md5 大寫）
     */
    private function generateSign(array $params): string
    {
        unset($params['sign']);
        ksort($params);
        $queryString = '';
        foreach ($params as $key => $value) {
            $queryString .= $key . '=' . trim((string) $value) . '&';
        }
        $stringToSign = $queryString . 'key=' . $this->secretKey;

        return strtoupper(md5($stringToSign));
    }

    private function makeCallback(string $orderNo, int $status, string $amount = '100.00'): WithdrawalCallbackResponseData
    {
        $resultPayload = ['orderid' => $orderNo, 'amount' => $amount];
        $resultJson = json_encode($resultPayload);

        $params = [
            'status' => $status,
            'result' => $resultJson,
        ];
        $sign = $this->generateSign($params);

        $request = Request::create('/callback', 'POST', array_merge($params, ['sign' => $sign]));

        return (new WithdrawalCallback($this->agentCashFlow))->handle($request);
    }

    /**
     * 測試成功代付回調（status = 10000）→ 24
     */
    public function test_status_10000_maps_to_24(): void
    {
        $order = $this->createWithdrawalOrder();
        $this->createPayoutRecord($order->id, 'QPAY_REF_001');

        $result = $this->makeCallback(orderNo: 'QPAY_REF_001', status: 10000);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $order->status);
    }

    /**
     * 測試失敗代付回調（status != 10000，如 99999）→ 29
     */
    public function test_non_10000_maps_to_29(): void
    {
        $order = $this->createWithdrawalOrder();
        $this->createPayoutRecord($order->id, 'QPAY_REF_002');

        $result = $this->makeCallback(orderNo: 'QPAY_REF_002', status: 99999);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, $order->status);
    }

    /**
     * 空憑證守衛：SECRET_KEY 為空時 generateSignature() 的 key 段為空、簽名可離線計算，
     * 攻擊者能偽造回調洗訂單。守衛須在驗簽前先擋下（拋 ApiException），且訂單維持 22。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->createWithdrawalOrder();
        $this->createPayoutRecord($order->id, 'QPAY_EMPTY');

        $emptyInfo = ['API_URL' => 'https://api.quickpay.test', 'SECRET_KEY' => ''];
        $emptyAcf = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($emptyInfo),
            'status' => 1,
            'admin' => 0,
        ]);
        $emptyAcf->info = $emptyInfo;

        // 攻擊者以空 secret 自算 sign
        $params = ['status' => 10000, 'result' => json_encode(['orderid' => 'QPAY_EMPTY', 'amount' => '100.00'])];
        ksort($params);
        $qs = '';
        foreach ($params as $k => $v) {
            $qs .= $k . '=' . trim((string) $v) . '&';
        }
        $forgedSign = strtoupper(md5($qs . 'key='));
        $request = Request::create('/callback', 'POST', array_merge($params, ['sign' => $forgedSign]));

        try {
            (new WithdrawalCallback($emptyAcf))->handle($request);
            $this->fail('空憑證回調應被守衛拒絕');
        } catch (ApiException $e) {
            $this->assertSame('Signature verification failed', $e->getMessage());
        }

        $order->refresh();
        $this->assertSame(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, $order->status);
    }
}
