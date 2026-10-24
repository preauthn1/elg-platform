<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TngPin;

use App\Enums\DepositResponseType;
use App\Enums\General\HttpCode;
use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Providers\TngPin\Deposit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TngPin 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositTest.php
 */
class DepositTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    private array $info;

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

        $this->info = [
            'APIUrl' => 'https://api.tngpin.test',
            'ApiKey' => 'test_api_key',
            'ApiPass' => 'test_api_pass',
            'Merchant' => 'TestMerchant',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'info' => json_encode($this->info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $this->info;

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * 三方 sendRequest timeout（ConnectionException）後：
     * - 不再占位 PIN，third_party_order_no 維持空
     * - 訂單保持 CREATED（模糊失敗，三方可能仍在處理，靠 callback 窄域 Phase 2 + BotNotify 告警兜底）
     * - 原 ApiException 被 rethrow
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositTest.php --filter test_sendRequest_timeout
     */
    public function test_sendRequest_timeout_keeps_order_created_without_reservation(): void
    {
        $pin = '76961923362939';
        $order = $this->createCreatedOrderWithPin($pin);

        Http::fake([
            '*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        try {
            $deposit->handle($this->makeDepositRequest($order));
            $this->fail('Expected ApiException to be thrown after sendRequest timeout');
        } catch (ApiException $e) {
            // 預期：trait sendRequest 把 ConnectionException 轉 ApiException(504)，handle() 判模糊失敗後 rethrow
        }

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status, 'timeout 模糊失敗應保持 CREATED 等回調/人工');
        $this->assertEquals('', $order->third_party_order_no, '不再占位 PIN，third_party_order_no 維持空');
    }

    /**
     * 三方同步回 result=error（無 status、無 transaction_id，如 PIN 格式錯 / Invalid Telco）：
     * 絕對失敗 → 標 FAILED；三方未受理、永不 callback，故不殘留孤兒。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositTest.php --filter test_sync_result_error
     */
    public function test_sync_result_error_marks_order_failed(): void
    {
        $pin = '76961923362939';
        $order = $this->createCreatedOrderWithPin($pin);

        Http::fake([
            '*' => Http::response([
                'result' => 'error',
                'message' => 'Invalid Telco',
            ], 200),
        ]);

        $result = (new Deposit($this->agentCashFlow))->handle($this->makeDepositRequest($order));

        $this->assertEquals(DepositResponseType::ERROR, $result->type, 'result=error 應回 ERROR 類型');
        $this->assertEquals('Invalid Telco', $result->message, '應透傳三方 message 給玩家');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status, 'result=error 為絕對失敗，應標 FAILED');
        $this->assertEquals('', $order->third_party_order_no, '無 transaction_id，third_party_order_no 維持空（不占位）');
    }

    /**
     * 三方同步回 status=2（result=success 但交易失敗，必帶 transaction_id）：
     * 官方文檔證實 status=2 = Failed（終態、Triggers Callback，無翻成 status=1 的路徑）→ 同步即標 FAILED
     * + 寫入 transaction_id。遲到的 status=2 callback 會被 checkDepositOrderStatus 以 200 冪等擋下。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositTest.php --filter test_sync_status_2
     */
    public function test_sync_status_2_marks_order_failed_with_transaction_id(): void
    {
        $pin = '76961923362939';
        $order = $this->createCreatedOrderWithPin($pin);

        $transactionId = 'E2D2C4B0B2A90D18A993';
        Http::fake([
            '*' => Http::response([
                'result' => 'success',
                'status' => '2',
                'transaction_id' => $transactionId,
                'amount' => '0.00',
                'message' => 'Transaction Failed',
                'detail_message' => 'Invalid PIN',
            ], 200),
        ]);

        $result = (new Deposit($this->agentCashFlow))->handle($this->makeDepositRequest($order));

        $this->assertEquals(DepositResponseType::ERROR, $result->type, 'status=2 應回 ERROR 提示');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status, '官方證實 status=2 為終態，同步即標 FAILED');
        $this->assertEquals($transactionId, $order->third_party_order_no, '應寫入 transaction_id 供遲到 callback 冪等命中');
    }

    /**
     * 三方同步回 status=1 但缺 transaction_id（防禦性邊緣案例，實測 success 必帶 txn）：
     * 不可標 SUCCESS；無 txn 可寫，維持 CREATED 等 callback（不再占位 PIN）。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositTest.php --filter test_sync_status_1_missing_transaction_id
     */
    public function test_sync_status_1_missing_transaction_id_keeps_order_created(): void
    {
        $pin = '76961923362939';
        $order = $this->createCreatedOrderWithPin($pin);

        Http::fake([
            '*' => Http::response([
                'result' => 'success',
                'status' => '1',
                'amount' => '5.00',
                'message' => 'Transaction Successful',
                'detail_message' => 'Reload Successful.',
                // 刻意不帶 transaction_id
            ], 200),
        ]);

        $result = (new Deposit($this->agentCashFlow))->handle($this->makeDepositRequest($order));

        $this->assertEquals(DepositResponseType::ERROR, $result->type, '缺 transaction_id 不可回 SUCCESS');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status, 'status=1 待入帳維持 CREATED 等 callback');
        $this->assertEquals('', $order->third_party_order_no, '無 transaction_id 且不再占位，維持空');
        $this->assertEquals('0.0000', $order->point, '未上分，point 維持 0');
    }

    /**
     * 三方同步回 status=1 且帶 transaction_id：
     * 訂單狀態與點數一律由回調決定，processDepositResponse **不可**在同步路徑入帳或標 SUCCESS。
     * 應維持 CREATED、point=0，僅由 recordTransactionInfo 回填真實 transaction_id；最終入帳交給 DepositCallback。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositTest.php --filter test_sync_status_1_with_transaction_id
     */
    public function test_sync_status_1_with_transaction_id_does_not_credit_keeps_created(): void
    {
        $pin = '76961923362939';
        $order = $this->createCreatedOrderWithPin($pin);

        $transactionId = 'SYNC_SUCCESS_TXN';
        Http::fake([
            '*' => Http::response([
                'result' => 'success',
                'status' => '1',
                'transaction_id' => $transactionId,
                'amount' => '5.00',
                'message' => 'Transaction Successful',
                'detail_message' => 'Reload Successful.',
            ], 200),
        ]);

        $result = (new Deposit($this->agentCashFlow))->handle($this->makeDepositRequest($order));

        $this->assertNotEquals(DepositResponseType::SUCCESS, $result->type, '同步 status=1 不可回 SUCCESS');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status, '同步 status=1 不可標 SUCCESS，維持 CREATED 等回調入帳');
        $this->assertEquals('0.0000', $order->point, '同步路徑不入帳，point 維持 0');
        $this->assertEquals('0.0000', $order->amount, '同步路徑不寫入 amount');
        $this->assertEquals($transactionId, $order->third_party_order_no, 'recordTransactionInfo 仍回填真實 transaction_id');
    }

    /**
     * P1-4 / P1-5：發起代收應送 merchant_ref=order_no（每單唯一、非商戶號）與 wait_result=false（boolean）。
     * 對齊舊系統 / 工單 #3865，避免三方 Unique Merchant Reference 重複拒絕與同步超時。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositTest.php --filter test_deposit_sends_order_no
     */
    public function test_deposit_sends_order_no_and_wait_result_false(): void
    {
        $pin = '76961923362939';
        $order = $this->createCreatedOrderWithPin($pin);

        Http::fake([
            '*' => Http::response([
                'result' => 'success',
                'status' => '3',
                'transaction_id' => 'TXN_ASYNC',
                'amount' => '0.00',
                'message' => 'Processing',
                'detail_message' => 'Processing',
            ], 200),
        ]);

        (new Deposit($this->agentCashFlow))->handle($this->makeDepositRequest($order));

        Http::assertSent(function ($request) use ($order) {
            $body = $request->data();

            return $request->url() === 'https://api.tngpin.test/submitPIN'
                && ($body['merchant_ref'] ?? null) === $order->order_no
                && ($body['wait_result'] ?? null) === false; // 必須為 boolean false
        });
    }

    /**
     * P0-2：發起代收寫入 remark 前須遮罩 ApiKey / PIN，避免支付憑證明文留存。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositTest.php --filter test_deposit_masks_credentials
     */
    public function test_deposit_masks_credentials_in_remark(): void
    {
        $pin = '76961923362939';
        $order = $this->createCreatedOrderWithPin($pin);

        Http::fake([
            '*' => Http::response([
                'result' => 'success',
                'status' => '3',
                'transaction_id' => 'TXN_ASYNC',
                'amount' => '0.00',
                'message' => 'Processing',
                'detail_message' => 'Processing',
            ], 200),
        ]);

        (new Deposit($this->agentCashFlow))->handle($this->makeDepositRequest($order));

        $order->refresh();
        $this->assertStringNotContainsString($this->info['ApiKey'], (string) $order->remark, 'remark 不可含明文 ApiKey');
        $this->assertStringNotContainsString($pin, (string) $order->remark, 'remark 不可含明文 PIN');
    }

    /**
     * in-flight 並發鎖：同 agent_cash_flow_id + 同 PIN 的鎖被持有時，handle() 立即拋 ApiException(HTTP_LOCKED)，
     * sendRequest 不被呼叫（防不可逆 PIN 並發雙送）。被擋訂單維持 CREATED，可於鎖釋放後重送。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositTest.php --filter test_inflight_lock
     */
    public function test_inflight_lock_blocks_concurrent_same_pin(): void
    {
        $pin = '76961923362939';
        $order = $this->createCreatedOrderWithPin($pin);

        // 預先佔住同 key 的鎖（模擬另一筆並發訂單正在送三方）
        $lockKey = 'tngpin:inflight:' . $this->agentCashFlow->id . ':' . hash('sha256', $pin);
        $heldLock = Cache::lock($lockKey, 300);
        $this->assertTrue($heldLock->get(), '前置：應成功佔住鎖');

        // sendRequest 不應被呼叫（若被呼叫代表沒擋住）
        Http::fake([
            '*' => fn () => throw new \RuntimeException('sendRequest 不應在鎖被持有時被呼叫'),
        ]);

        try {
            (new Deposit($this->agentCashFlow))->handle($this->makeDepositRequest($order));
            $this->fail('鎖被持有時應拋 ApiException(HTTP_LOCKED)');
        } catch (ApiException $e) {
            $this->assertEquals(HttpCode::HTTP_LOCKED->value, $e->getCode(), '應回 HTTP_LOCKED');
        } finally {
            $heldLock->release();
        }

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status, '被擋的並發訂單維持 CREATED');
    }

    /**
     * 建立一筆 status=CREATED、third_party_order_no 為空的訂單（模擬 handle() 呼叫前的真實狀態）。
     * 新行為不再在送三方前占位 PIN，PIN 僅存於 extra_content。
     */
    private function createCreatedOrderWithPin(string $pin): Order
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'TngPin Umobile',
            'type' => 1,
            'status' => 1,
            'key' => 'umobile',
            'info' => '{}',
            'admin' => 0,
        ]);

        return Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'TNG' . uniqid(),
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => 0,
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
            'extra_content' => json_encode(['pin' => $pin]),
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'ip' => '0.0.0.0',
        ]);
    }

    private function makeDepositRequest(Order $order): DepositRequestData
    {
        return DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);
    }
}
