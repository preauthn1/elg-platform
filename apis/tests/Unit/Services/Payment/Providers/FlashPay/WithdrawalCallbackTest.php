<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\FlashPay;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\FlashPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Services/Payment/Providers/FlashPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    protected Agent $agent;

    protected Currency $currency;

    protected Member $member;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::factory()->create();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'SGD',
            'name' => 'Singapore Dollar',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
        ]);

        $this->info = [
            'API_URL' => 'https://example.invalid/v3',
            'CLIENT_ID' => '8e363c65-6dec-11f1-8a6d-0200009ee8a2',
            'SECRET_KEY' => '5c378d3e9e1fcacf83e4ad94df944a538102905a116af5ae6ed6f1bdff13ca97',
            'CURRENCY' => 'SGD',
            'available_banks' => [],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'admin' => 0,
            'info' => json_encode($this->info),
        ]);
        $this->agentCashFlow->info = $this->info;
    }

    private function createPayoutRecord(int $withdrawalOrderId, string $transactionNo): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create([
            'cash_flow_id' => $this->agentCashFlow->cash_flow_id ?? 1,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $withdrawalOrderId,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => '',
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
        ]);
    }

    private function createWithdrawalOrder(): WithdrawalOrder
    {
        $bank = Bank::firstOrCreate(['code' => 'DBS'], [
            'name' => 'DBS Bank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'DBS',
            'bank_name' => 'DBS Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 500,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }

    private function buildCallbackData(string $transactionNo, int $amount = 500, string $status = 'completed'): array
    {
        $params = [
            'status_code' => 0,
            'status_msg' => 'NO_ERROR',
            'tran_token' => 'payout-token-' . time(),
            'tran_customer' => $this->member->username,
            'bank_code' => 'DBS',
            'bank_account_name' => 'Test User',
            'bank_account_number' => '1234567890',
            'tran_amount' => $amount,
            'tran_unique' => $transactionNo,
            'tran_fee' => 2,
            'tran_status' => $status,
            'tran_createtime' => '2026-06-29 12:00:00',
            'tran_lastupdated' => '2026-06-29 12:00:01',
        ];

        $filtered = array_filter($params, fn ($v) => $v !== null && $v !== '');
        ksort($filtered);
        $signStr = urldecode(http_build_query($filtered));
        $params['tran_signature'] = hash('sha256', $signStr . $this->info['SECRET_KEY']);

        return $params;
    }

    public function test_success(): void
    {
        $order = $this->createWithdrawalOrder();
        $payoutRecord = $this->createPayoutRecord($order->id, 'FLASH_CB_001');
        $callbackData = $this->buildCallbackData('FLASH_CB_001');

        $request = Request::create('/callback', 'POST', $callbackData);
        $callback = new WithdrawalCallback($this->agentCashFlow);
        $result = $callback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        $order->refresh();
        $this->assertEquals(24, $order->status);
    }

    public function test_rejected(): void
    {
        $order = $this->createWithdrawalOrder();
        $payoutRecord = $this->createPayoutRecord($order->id, 'FLASH_CB_002');
        $callbackData = $this->buildCallbackData('FLASH_CB_002', 500, 'rejected');

        $request = Request::create('/callback', 'POST', $callbackData);
        $callback = new WithdrawalCallback($this->agentCashFlow);
        $result = $callback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals(29, $order->status);
    }

    public function test_invalid_signature(): void
    {
        $callbackData = $this->buildCallbackData('FLASH_CB_003');
        $callbackData['tran_signature'] = 'invalid';

        $request = Request::create('/callback', 'POST', $callbackData);
        $callback = new WithdrawalCallback($this->agentCashFlow);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Signature verification failed');
        $callback->handle($request);
    }

    public function test_missing_required_fields(): void
    {
        $request = Request::create('/callback', 'POST', ['tran_amount' => 500]);
        $callback = new WithdrawalCallback($this->agentCashFlow);

        $this->expectException(ValidationException::class);
        $callback->handle($request);
    }

    /**
     * 空憑證守衛：SECRET_KEY 為空時 checkSign() 的 secret 段為空、簽名可離線計算，
     * 攻擊者能偽造回調洗訂單。守衛須在驗簽前先擋下（拋 ApiException），且訂單維持 22。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->createWithdrawalOrder();
        $transactionNo = 'FLASH_EMPTY_KEY';
        $this->createPayoutRecord($order->id, $transactionNo);

        $emptyInfo = $this->info;
        $emptyInfo['SECRET_KEY'] = '';
        $emptyAcf = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'admin' => 0,
            'info' => json_encode($emptyInfo),
        ]);
        $emptyAcf->info = $emptyInfo;

        // 攻擊者以空 secret 自算簽名
        $params = [
            'status_code' => 0,
            'status_msg' => 'NO_ERROR',
            'tran_token' => 'payout-token-forged',
            'tran_customer' => $this->member->username,
            'bank_code' => 'DBS',
            'bank_account_name' => 'Test User',
            'bank_account_number' => '1234567890',
            'tran_amount' => 500,
            'tran_unique' => $transactionNo,
            'tran_fee' => 2,
            'tran_status' => 'completed',
            'tran_createtime' => '2026-06-29 12:00:00',
            'tran_lastupdated' => '2026-06-29 12:00:01',
        ];
        $filtered = array_filter($params, fn ($v) => $v !== null && $v !== '');
        ksort($filtered);
        $signStr = urldecode(http_build_query($filtered));
        $params['tran_signature'] = hash('sha256', $signStr . '');

        $request = Request::create('/callback', 'POST', $params);

        try {
            (new WithdrawalCallback($emptyAcf))->handle($request);
            $this->fail('空憑證回調應被守衛拒絕');
        } catch (ApiException $e) {
        }

        $order->refresh();
        $this->assertEquals(22, $order->status);
    }
}
