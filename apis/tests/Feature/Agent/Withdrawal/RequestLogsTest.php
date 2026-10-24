<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Withdrawal;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\Member;
use App\Models\OrderRequestLog;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 提款訂單的三方往返紀錄（target/tickets#4449）。
 *
 * 與代收那組（tests/Feature/Agent/Deposit/RequestLogsTest）守的是同一件事：
 * order_request_logs 存的是未遮罩原文，含明文商戶憑證，所以回傳欄位多一個都不行。
 *
 * 代付多守一條：**每筆代付請求各自一組**。一張提款單可能送出多次代付（重試、換渠道），
 * 併成一張大表就看不出哪一次請求配哪幾筆往返，而「上一次為什麼沒成功」正是要看的東西。
 *
 * php artisan test tests/Feature/Agent/Withdrawal/RequestLogsTest.php --compact
 */
class RequestLogsTest extends TestCase
{
    private string $apiPath = 'agent/api/withdrawal/request-logs';

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();
        // 工廠的 username 是固定值，一個測試建兩個會員會撞唯一鍵，所以逐一指定
        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => 'rl_member',
            'nickname' => 'rl_member',
            'admin' => 0,
        ]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    private function createOrder(array $overrides = []): WithdrawalOrder
    {
        return WithdrawalOrder::create(array_merge([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ], $overrides));
    }

    private function createRecord(WithdrawalOrder $order, array $overrides = []): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create(array_merge([
            'cash_flow_id' => 1,
            'agent_cash_flow_id' => 1,
            'withdrawal_order_id' => $order->id,
            'transaction_no' => 'EASY' . random_int(10000000, 99999999),
            'third_party_transaction_no' => '',
            'payout_order_request_body' => '',
            'payout_order_response' => '',
            'result_amount' => 100,
            'result_code' => '',
            'transaction_time' => now()->toDateTimeString(),
            'sign' => '',
            'callback_request_body' => '',
            'is_success_payout' => 0,
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
            'admin' => 0,
        ], $overrides));
    }

    private function createLog(WithdrawalPayoutRecord $record, array $overrides = []): OrderRequestLog
    {
        return OrderRequestLog::create(array_merge([
            'orderable_type' => 'payout_record',
            'orderable_id' => $record->id,
            'order_no' => $record->transaction_no,
            'third_party_order_no' => 'EP-TP-1',
            'action' => 'withdrawal_create',
            'url' => 'https://example.invalid/withdraw/bank_order',
            'http_status' => null,
            'request_headers' => '{"merchant":"REAL-MERCHANT-NAME"}',
            'request_body' => '{"account_number":"1234567890","tx_id":"' . $record->transaction_no . '"}',
            'response_body' => '{"status":1,"data":{"order_id":"EP-TP-1"}}',
            'result' => 'ok',
        ], $overrides));
    }

    #[Group('rules/payment-callback-common-checks#R-23')]
    #[TestDox('往返內容一個欄位都不回，只回時間與判定結果這類純量')]
    public function test_no_exchange_content_is_ever_returned(): void
    {
        $order = $this->createOrder();
        $this->createLog($this->createRecord($order));

        $response = $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)->assertOk();

        // 原文含商戶識別與玩家銀行帳號，一個字都不該出現
        $response->assertDontSee('REAL-MERCHANT-NAME');
        $response->assertDontSee('account_number');

        // 欄位名單釘死：有人之後想把內容加回來會立刻紅
        $this->assertSame(
            ['payout_record_id', 'transaction_no', 'third_party_transaction_no', 'logs'],
            array_keys($response->json('data.0')),
        );
        $this->assertSame(
            ['action', 'result', 'http_status', 'third_party_order_no', 'source_ip', 'created_at'],
            array_keys($response->json('data.0.logs.0')),
        );
    }

    /**
     * 一張提款單可能有多次代付請求（重試、換渠道）。併成一張大表就看不出哪一次請求配哪幾筆往返，
     * 而前幾次為什麼沒成功正是客服要回答「為什麼還沒出款」的依據。
     */
    #[Group('rules/payment-callback-common-checks#R-24')]
    #[TestDox('每筆代付請求各自一組，不併成一張大表')]
    public function test_logs_are_grouped_per_payout_record(): void
    {
        $order = $this->createOrder();

        $first = $this->createRecord($order);
        $this->createLog($first, [
            'result' => 'failed:MERCHANT_INSUFFICIENT_BALANCE',
            'http_status' => 400,
        ]);

        $second = $this->createRecord($order);
        $this->createLog($second, ['result' => 'ok']);
        $this->createLog($second, ['action' => 'withdrawal_callback', 'result' => 'ok']);

        $groups = $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)
            ->assertOk()->json('data');

        $this->assertCount(2, $groups);

        $this->assertSame($first->id, $groups[0]['payout_record_id']);
        $this->assertSame($first->transaction_no, $groups[0]['transaction_no']);
        $this->assertCount(1, $groups[0]['logs']);
        $this->assertSame('failed:MERCHANT_INSUFFICIENT_BALANCE', $groups[0]['logs'][0]['result']);
        $this->assertSame(400, $groups[0]['logs'][0]['http_status']);

        $this->assertSame($second->id, $groups[1]['payout_record_id']);
        $this->assertCount(2, $groups[1]['logs']);
        $this->assertSame('withdrawal_create', $groups[1]['logs'][0]['action']);
        $this->assertSame('withdrawal_callback', $groups[1]['logs'][1]['action']);
    }

    #[Group('rules/payment-callback-common-checks#R-22')]
    #[TestDox('來源 IP 被擋下的回調沒有 orderable_id，仍要掛在該筆代付請求底下')]
    public function test_logs_without_orderable_id_are_still_listed(): void
    {
        $order = $this->createOrder();
        $record = $this->createRecord($order);

        // 白名單擋在「取代付紀錄」之前就 return，所以這種紀錄沒有 orderable_id，只剩我方單號對得上
        $this->createLog($record, [
            'orderable_id' => null,
            'action' => 'withdrawal_callback',
            'result' => 'failed:ip_not_whitelisted',
            'source_ip' => '0.0.0.0',
        ]);

        $logs = $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)
            ->assertOk()->json('data.0.logs');

        $this->assertCount(1, $logs);
        $this->assertSame('failed:ip_not_whitelisted', $logs[0]['result']);
        $this->assertSame('0.0.0.0', $logs[0]['source_ip']);
    }

    #[TestDox('回調只帶三方單號時，靠三方單號仍要掛在該筆代付請求底下')]
    public function test_logs_matched_by_third_party_no(): void
    {
        $order = $this->createOrder();
        $record = $this->createRecord($order, ['third_party_transaction_no' => 'EP-TP-9']);

        // 各家協定不同，有的回調只帶自己的單號，我方單號是空的
        $this->createLog($record, [
            'orderable_id' => null,
            'order_no' => null,
            'third_party_order_no' => 'EP-TP-9',
            'action' => 'withdrawal_callback',
            'result' => 'failed:another_channel',
        ]);

        $logs = $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)
            ->assertOk()->json('data.0.logs');

        $this->assertCount(1, $logs);
        $this->assertSame('failed:another_channel', $logs[0]['result']);
    }

    #[TestDox('代付紀錄的識別碼為空時不可拿空值去比，否則會撈到別人的紀錄')]
    public function test_empty_keys_do_not_match_everything(): void
    {
        $order = $this->createOrder();
        $this->createRecord($order, ['third_party_transaction_no' => '']);

        // 別張單的紀錄，兩個識別碼都是空的
        $otherRecord = $this->createRecord($this->createOrder());
        $this->createLog($otherRecord, [
            'orderable_id' => null,
            'order_no' => null,
            'third_party_order_no' => null,
        ]);

        $groups = $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)
            ->assertOk()->json('data');

        $this->assertCount(1, $groups);
        $this->assertSame([], $groups[0]['logs']);
    }

    #[Group('rules/payment-callback-common-checks#R-22')]
    #[TestDox('代收的紀錄不可因為 id 相同就出現在代付請求底下')]
    public function test_deposit_logs_do_not_leak_into_payout_record(): void
    {
        $order = $this->createOrder();
        $record = $this->createRecord($order);

        // orders 與 withdrawal_payout_records 的 id 空間重疊，連三方單號都可能撞
        // （同一家金流商的代收代付常共用序號空間），所以擋住的必須是型別
        OrderRequestLog::create([
            'orderable_type' => 'deposit_order',
            'orderable_id' => $record->id,
            'order_no' => $record->transaction_no,
            'third_party_order_no' => 'EP-TP-1',
            'action' => 'deposit_callback',
            'url' => 'https://example.invalid/external/api/deposit/notify/1',
            'result' => 'failed:ip_not_whitelisted',
        ]);

        $groups = $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)
            ->assertOk()->json('data');

        $this->assertSame([], $groups[0]['logs']);
    }

    #[Group('rules/payment-callback-common-checks#R-24')]
    #[TestDox('還沒送出過代付請求的單回空陣列')]
    public function test_order_without_payout_record_returns_empty(): void
    {
        $order = $this->createOrder();

        $groups = $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)
            ->assertOk()->json('data');

        $this->assertSame([], $groups);
    }

    #[TestDox('別的代理的訂單回 403')]
    public function test_other_agents_order_is_forbidden(): void
    {
        $otherMember = Member::factory()->create([
            'parent' => $this->agent->id + 999999,
            'username' => 'rl_other',
            'nickname' => 'rl_other',
            'admin' => 0,
        ]);
        $order = $this->createOrder(['member_id' => $otherMember->id]);
        $this->createLog($this->createRecord($order));

        $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)->assertStatus(403);
    }

    #[TestDox('訂單不存在回 404')]
    public function test_missing_order_returns_404(): void
    {
        $this->postJson($this->apiPath, ['order_id' => 99999999], $this->headers)->assertStatus(404);
    }
}
