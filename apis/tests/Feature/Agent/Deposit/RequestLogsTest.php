<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Deposit;

use App\Enums\OrderStatusEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use App\Models\Order;
use App\Models\OrderRequestLog;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 儲值訂單的三方往返紀錄（target/tickets#4446）。
 *
 * 這組測試守的是「不該外流的東西不會外流」：order_request_logs 存的是未遮罩原文，
 * 含明文商戶金鑰，所以白名單過濾壞掉等於憑證直接送到客服畫面上。
 *
 * php artisan test tests/Feature/Agent/Deposit/RequestLogsTest.php --compact
 */
class RequestLogsTest extends TestCase
{
    private string $apiPath = 'agent/api/deposit/request-logs';

    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    /** 建一個真的會解析到 Ap8Pay\Driver 的渠道（third_party_code 去掉前兩碼＝config/payment.php 的 key） */
    private function createAp8Channel(): AgentCashFlow
    {
        $cashFlow = CashFlow::create([
            'third_party_code' => 'D_AP8',
            'name' => 'Ap8Pay - Deposit',
            'type' => 1,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        return $this->createAgentCashFlow($this->agent, [
            'cash_flow_id' => $cashFlow->id,
            'info' => ['APIUrl' => 'https://example.invalid', 'HeaderApiKey' => 'h', 'MerchantApiKey' => 'm'],
        ]);
    }

    private function createOrder(AgentCashFlow $agentCashFlow, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'agent_id' => $this->agent->id,
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => 1,
            'currency_id' => 1,
            'member_id' => 0,
            'third_party_order_no' => '',
            'order_no' => (string) random_int(100000000, 999999999),
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => 0,
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ], $overrides));
    }

    private function createLog(Order $order, array $overrides = []): OrderRequestLog
    {
        return OrderRequestLog::create(array_merge([
            'orderable_type' => 'deposit_order',
            'orderable_id' => $order->id,
            'order_no' => $order->order_no,
            'third_party_order_no' => '4dU8QTy70',
            'action' => 'deposit_create',
            'url' => 'https://example.invalid/merchants/deposits',
            'http_status' => null,
            'request_headers' => '{"api-key":"REAL-SYSTEM-KEY"}',
            'request_body' => '{"apiKey":"REAL-MERCHANT-KEY","uniqueCode":"' . $order->order_no . '","senderName":"tim01"}',
            'response_body' => '{"data":{"url":"https://pay/x","code":"4dU8QTy70"}}',
            'result' => 'ok',
        ], $overrides));
    }

    #[Group('rules/payment-callback-common-checks#R-23')]
    #[TestDox('往返內容一個欄位都不回，只回時間與判定結果這類純量')]
    public function test_no_exchange_content_is_ever_returned(): void
    {
        $order = $this->createOrder($this->createAp8Channel());
        $this->createLog($order);

        $response = $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)->assertOk();

        // 原文含明文商戶金鑰與系統金鑰，一個字都不該出現
        $response->assertDontSee('REAL-MERCHANT-KEY');
        $response->assertDontSee('REAL-SYSTEM-KEY');
        $response->assertDontSee('senderName');

        // 欄位名單釘死：有人之後想把內容加回來會立刻紅
        $this->assertSame(
            ['action', 'result', 'http_status', 'third_party_order_no', 'source_ip', 'created_at'],
            array_keys($response->json('data.0')),
        );
    }

    #[Group('rules/payment-callback-common-checks#R-21')]
    #[Group('rules/payment-callback-common-checks#R-23')]
    #[TestDox('客服判斷「為什麼沒上分」要的三樣都在：判定結果、三方訂單號、時間')]
    public function test_returns_what_support_needs(): void
    {
        $order = $this->createOrder($this->createAp8Channel());
        $this->createLog($order, ['result' => 'failed:ip_not_whitelisted', 'source_ip' => '0.0.0.0']);

        $row = $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)->assertOk()->json('data.0');

        $this->assertSame('failed:ip_not_whitelisted', $row['result']);
        $this->assertSame('4dU8QTy70', $row['third_party_order_no']);
        $this->assertSame('0.0.0.0', $row['source_ip']);
        $this->assertNotNull($row['created_at']);
    }

    #[Group('rules/payment-callback-common-checks#R-22')]
    #[TestDox('防線 1 擋下的回調 orderable_id 是 NULL，仍要出現在該訂單底下')]
    public function test_logs_without_orderable_id_are_still_listed(): void
    {
        $order = $this->createOrder($this->createAp8Channel());

        // 來源 IP 被擋時，防線 1 在「取訂單」之前就 return，所以這種紀錄沒有 orderable_id
        $this->createLog($order, [
            'orderable_id' => null,
            'action' => 'deposit_callback',
            'result' => 'failed:ip_not_whitelisted',
            'source_ip' => '0.0.0.0',
        ]);

        $rows = $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('failed:ip_not_whitelisted', $rows[0]['result']);
    }

    #[TestDox('uniqueCode 被竄改成別人的訂單號時，靠 taskCode 仍要掛在本單底下')]
    public function test_logs_matched_by_task_code(): void
    {
        $order = $this->createOrder($this->createAp8Channel(), ['third_party_order_no' => '4dU8QTy70']);

        // 需求 2 的情境：回調帶的 uniqueCode 是別人的訂單號，但 taskCode 是本單的
        $this->createLog($order, [
            'orderable_id' => null,
            'order_no' => '999999999999999999',
            'third_party_order_no' => '4dU8QTy70',
            'action' => 'deposit_callback',
            'result' => 'failed:another_channel',
        ]);

        $rows = $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('failed:another_channel', $rows[0]['result']);
    }

    #[TestDox('訂單的識別碼為空時不可拿空值去比，否則會撈到別人的紀錄')]
    public function test_empty_keys_do_not_match_everything(): void
    {
        $order = $this->createOrder($this->createAp8Channel(), ['third_party_order_no' => '']);

        // 別張單的紀錄，order_no 與 taskCode 都是空的
        $otherOrder = $this->createOrder($this->createAp8Channel());
        $this->createLog($otherOrder, [
            'orderable_id' => null,
            'order_no' => null,
            'third_party_order_no' => null,
        ]);

        $rows = $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)
            ->assertOk()->json('data');

        $this->assertSame([], $rows);
    }

    #[Group('rules/payment-callback-common-checks#R-22')]
    #[TestDox('代付的紀錄不可因為 id 相同就出現在代收訂單底下')]
    public function test_withdrawal_logs_do_not_leak_into_deposit_order(): void
    {
        $order = $this->createOrder($this->createAp8Channel());

        // orders 與 withdrawal_payout_records 的 id 空間重疊（實測 TPower：2030 萬 vs 186 萬），
        // 所以「id 剛好一樣的代付紀錄」不是假想情境，是這張表遲早會有的狀態。
        // 連三方單號都刻意設成一樣，確認擋住的是型別而不是碰巧對不上。
        OrderRequestLog::create([
            'orderable_type' => 'payout_record',
            'orderable_id' => $order->id,
            'order_no' => 'PE2026100608421243028',
            'third_party_order_no' => '4dU8QTy70',
            'action' => 'withdrawal_callback',
            'url' => 'https://example.invalid/external/api/withdrawal/notify/1',
            'result' => 'failed:ip_not_whitelisted',
        ]);

        $rows = $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)
            ->assertOk()->json('data');

        $this->assertSame([], $rows);
    }

    #[TestDox('別的代理的訂單回 403')]
    public function test_other_agents_order_is_forbidden(): void
    {
        $agentCashFlow = $this->createAp8Channel();

        // 不在本代理樹下的 agent_id（canSeeAgent 應回 false）
        $order = $this->createOrder($agentCashFlow, ['agent_id' => $this->agent->id + 999999]);
        $this->createLog($order);

        $this->postJson($this->apiPath, ['order_id' => $order->id], $this->headers)->assertStatus(403);
    }

    #[TestDox('訂單不存在回 404')]
    public function test_missing_order_returns_404(): void
    {
        $this->postJson($this->apiPath, ['order_id' => 99999999], $this->headers)->assertStatus(404);
    }
}
