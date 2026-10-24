<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayMate;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\PayMate\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * PayMate 代付回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayMate/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    use PayMateSignatureHelper;

    protected Agent $agent;

    protected Member $member;

    protected AgentCashFlow $agentCashFlow;

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

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);

        $info = [
            'API_URL' => 'https://api.paymate.test',
            'MERCHANT_NO' => self::MERCHANT_NO,
            'MD5_KEY' => self::MD5_KEY,
            'available_banks' => [
                ['BankCode_ELG' => 'MAYB', 'BankCode_Third' => 'MAYB', 'BankName' => 'Maybank'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => 1,
            'cash_flow_id' => 122,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'admin' => 0,
            'info' => json_encode($info),
        ]);
        $this->agentCashFlow->info = $info;
    }

    private function createWithdrawalOrder(int $status = 22): WithdrawalOrder
    {
        $bank = Bank::firstOrCreate(['code' => 'MAYB'], [
            'name' => '(MYR) - Maybank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        return WithdrawalOrder::create([
            'agent_id' => 0,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYB',
            'bank_name' => '(MYR) - Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100,
            'status' => $status,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }

    private function createPayoutRecord(int $withdrawalOrderId, string $transactionNo): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create([
            'cash_flow_id' => 122,
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

    /**
     * 測試正常成功回調，訂單更新為三方成功
     */
    public function test_success_callback_updates_order(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $transactionNo = 'PM' . $withdrawalOrder->id;
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $content = [
            'merchantNo' => self::MERCHANT_NO,
            'status' => 'SUCCESS',
            'amount' => '100.00',
            'orderNo' => $transactionNo,
            'gbpOrderNo' => 'NOCA2000123',
        ];

        $payload = $this->buildNotifyPayload($content, self::MD5_KEY);
        $request = Request::create('/callback', 'POST', $payload);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);
        $result = $withdrawalCallback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertSame('OK', $result->response);

        $withdrawalOrder->refresh();
        $this->assertSame(24, $withdrawalOrder->status); // THIRD_PARTY_SUCCESS

        $payoutRecord->refresh();
        // refactor 後 is_success_payout 由 model cast 為 enum；回調成功寫入 SUCCESS(1)
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
    }

    /**
     * 測試重複回調，冪等性防護：已是三方成功的記錄再收到通知直接回應成功不重複處理
     */
    public function test_duplicate_callback_is_idempotent(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(status: 24); // THIRD_PARTY_SUCCESS
        $transactionNo = 'PM' . $withdrawalOrder->id;
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);
        $payoutRecord->update(['is_success_payout' => 1]);

        $content = [
            'merchantNo' => self::MERCHANT_NO,
            'status' => 'SUCCESS',
            'amount' => '100.00',
            'orderNo' => $transactionNo,
            'gbpOrderNo' => 'NOCA2000123',
        ];

        $payload = $this->buildNotifyPayload($content, self::MD5_KEY);
        $request = Request::create('/callback', 'POST', $payload);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);
        $result = $withdrawalCallback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertSame('OK', $result->response);
    }

    /**
     * 空憑證守衛：MD5_KEY 為空時 generateSign() 的 key 段為空、簽名可離線計算，
     * 攻擊者能自算出「有效」signMsg 偽造成功回調把在途訂單洗成 24。
     * 守衛須在 verifyNotifySign() 與 merchantNo 比對之前先擋下：回 'Failure'，
     * 且訂單維持 22。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $transactionNo = 'PM' . $withdrawalOrder->id;
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        // 線上憑證未配置的常見形態：MD5_KEY 為空字串（MERCHANT_NO 仍在）
        $emptyKeyInfo = [
            'API_URL' => 'https://api.paymate.test',
            'MERCHANT_NO' => self::MERCHANT_NO,
            'MD5_KEY' => '',
            'available_banks' => [
                ['BankCode_ELG' => 'MAYB', 'BankCode_Third' => 'MAYB', 'BankName' => 'Maybank'],
            ],
        ];
        $emptyKeyAcf = AgentCashFlow::create([
            'currency_id' => 1,
            'cash_flow_id' => 122,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'admin' => 0,
            'info' => json_encode($emptyKeyInfo),
        ]);
        $emptyKeyAcf->info = $emptyKeyInfo;

        $content = [
            'merchantNo' => self::MERCHANT_NO,
            'status' => 'SUCCESS',
            'amount' => '100.00',
            'orderNo' => $transactionNo,
            'gbpOrderNo' => 'NOCA2000123',
        ];

        // 攻擊者以「空 md5Key」自算 signMsg，其餘欄位皆合法
        $payload = $this->buildNotifyPayload($content, '');
        $request = Request::create('/callback', 'POST', $payload);

        $result = (new WithdrawalCallback($emptyKeyAcf))->handle($request);

        // 守衛硬拒
        $this->assertSame('Failure', $result->response);

        // 訂單維持 22：偽造的成功回調沒能把它結算成 24
        $withdrawalOrder->refresh();
        $this->assertSame(22, $withdrawalOrder->status);
    }
}
