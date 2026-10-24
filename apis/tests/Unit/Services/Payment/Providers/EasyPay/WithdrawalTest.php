<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\EasyPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\OrderRequestLog;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Providers\EasyPay\Withdrawal;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * EasyPay 代付請求測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/EasyPay/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    private AgentCashFlow $agentCashFlow;

    private WithdrawalOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $agent = $this->createAgentAndDomain();

        $info = [
            'API_URL' => 'https://api.easypay.test',
            'MERCHANT_NAME' => 'easy_merchant',
            'MERCHANT_PASSWORD' => 'easy_password',
            'available_banks' => [
                ['BankCode_ELG' => '001', 'BankCode_Third' => 'TBANK'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $member = Member::factory()->create(['parent' => $agent->id, 'admin' => 0]);

        $this->order = WithdrawalOrder::create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);
    }

    public function test_success_keeps_record_pending(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 1,
            'data' => ['order_id' => 'EP-1'],
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('EP-1', $record->third_party_transaction_no);
    }

    public function test_sync_reject_marks_third_party_rejected(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 0,
            'code' => 'ERR01',
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    public function test_request_exception_marks_request_failed(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * 本家用 HTTP 400 承載業務錯誤。原本 sendRequest() 掛 ->throw()，這種回應會被當成「連不上」，
     * payout record 記 -1、訂單停在 21 等人工定案——錢根本沒出去（Dev 實測 MERCHANT_INSUFFICIENT_BALANCE）。
     */
    #[TestDox('三方以 HTTP 400 帶錯誤碼拒絕時，判成明確拒絕而不是結果未知')]
    public function test_http_400_with_business_code_is_a_definitive_rejection(): void
    {
        Http::fake(['*' => Http::response(['status' => 0, 'code' => 'MERCHANT_INSUFFICIENT_BALANCE'], 400)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertFalse($response->isIndeterminate, '三方明確說了拒絕，不該讓訂單卡在代付請求中');

        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);

        $log = OrderRequestLog::where('order_no', $record->transaction_no)->latest('id')->first();
        $this->assertSame('failed:MERCHANT_INSUFFICIENT_BALANCE', $log?->result);
    }

    /**
     * 🔴 這條守的是二次出款：502 的 HTML 錯誤頁、網關空 body 這類回應，請求可能已經送達三方。
     * 判成明確拒絕會讓訂單轉 29、營運重新發起 ⇒ 同一筆出兩次。只能維持「結果未知」。
     */
    #[TestDox('解不出業務信封時維持結果未知，不可判成拒絕')]
    public function test_unparseable_response_stays_indeterminate(): void
    {
        Http::fake(['*' => Http::response('<html>502 Bad Gateway</html>', 502)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate, '請求可能已經送達三方，不可判成拒絕讓營運重發');

        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * 解得出 JSON 卻不是本家的信封（閘道自己的 JSON 錯誤頁）：同樣沒有依據斷定三方沒受理。
     */
    #[TestDox('JSON 回應缺 status 欄位時維持結果未知')]
    public function test_json_without_status_envelope_stays_indeterminate(): void
    {
        Http::fake(['*' => Http::response(['error' => 'upstream timeout'], 504)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isIndeterminate);

        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * 代付請求的三方往返紀錄（工單 target/tickets#4449）。
     *
     * 記在共用的 processPayout() 裡，所以 30 家走共用流程的金流商一次都有。
     * 與 withdrawal_payout_records 並存是刻意的：那張表一筆 record 只留得下最後一次的內容，
     * 沒有 HTTP 狀態、也沒有統一的判定值。
     */
    #[TestDox('代付請求成功時寫入往返紀錄，掛在 payout record 底下')]
    public function test_successful_payout_is_recorded(): void
    {
        Http::fake(['*' => Http::response(['status' => 1, 'data' => ['order_id' => 'EP-1']], 200)]);

        (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $log = OrderRequestLog::where('order_no', $record->transaction_no)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('withdrawal_create', $log->action);
        $this->assertSame('ok', $log->result);
        $this->assertSame('payout_record', $log->orderable_type);
        $this->assertSame($record->id, $log->orderable_id);
        $this->assertSame('EP-1', $log->third_party_order_no);
        // 成功路徑過去不帶 HTTP 狀態（只有連不上那條有），往返紀錄看不出三方回了幾
        $this->assertSame(200, $log->http_status);
    }

    #[TestDox('三方拒絕代付時寫入往返紀錄，result 帶三方的錯誤碼')]
    public function test_rejected_payout_is_recorded(): void
    {
        Http::fake(['*' => Http::response(['status' => 0, 'code' => 'ERR01'], 200)]);

        (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $log = OrderRequestLog::where('order_no', $record->transaction_no)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('failed:ERR01', $log->result);
        $this->assertSame(200, $log->http_status, '三方拒絕走的是另一條記錄路徑，同樣要帶狀態碼');
    }

    #[TestDox('連不上三方時寫入往返紀錄，這是判斷「三方收到沒有」的唯一依據')]
    public function test_connection_failure_payout_is_recorded(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $log = OrderRequestLog::where('order_no', $record->transaction_no)->latest('id')->first();

        $this->assertNotNull($log, '連不上時更要留紀錄：三方可能已經收到請求');
        $this->assertSame('failed:exception', $log->result);
        $this->assertNull($log->http_status, '連不上代表沒有狀態碼可留，不可填 0');
    }
}
