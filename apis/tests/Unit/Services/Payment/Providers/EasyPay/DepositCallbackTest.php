<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\EasyPay;

use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderRequestLog;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\EasyPay\DepositCallback;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * EasyPay 代收回調測試（三態：COMPLETE=成功、REJECT=失敗、PENDING/OVERTIME/未知=維持當前狀態）
 *
 * 聚焦驗證本次新增行為：非終態 / 未知碼不再被誤判為失敗，訂單維持 CREATED 等後續終態回調。
 * （COMPLETE 入帳成功路徑涉及完整入帳鏈 fixture，不在本測試範圍。）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/EasyPay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private CashFlow $cashFlow;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $info = [
            'API_URL' => 'https://api.easypay.test',
        ];

        // 回調來源 IP 白名單（target/tickets#4449 起 fail-closed：沒配＝一律拒絕）。

        // 不放行 0.0.0.0 的話這支測試會變成假通過：被防線 1 擋下時訂單同樣停在 CREATED，

        // 斷言照樣綠，但測到的已經不是 status 判斷了。

        $this->cashFlow = CashFlow::find(1) ?? CashFlow::forceCreate([

            'id' => 1,

            'third_party_code' => 'TEST',

            'name' => 'Test Cash Flow',

            'type' => 1,

            'status' => 1,

            'info' => '',

            'admin' => 0,

        ]);

        $this->cashFlow->update(['whitelist' => '0.0.0.0']);

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $this->agentCashFlow->setRelation('cashFlow', $this->cashFlow);

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * 需求 1（target/tickets#4449）：只接受白名單 IP 送來的代收回調。
     *
     * EasyPay 的回調沒有驗簽（廠商的 mdfive_val 預設關閉、我方商戶未啟用，見 target/tickets#4470），
     * 來源 IP 是目前唯一擋得住偽造回調的防線。被擋時訂單狀態、金額、點數一律不動——
     * 不可信的輸入不該動得了狀態，否則任何人都能從任意 IP 連發偽造回調把待付訂單打成失敗。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/EasyPay/DepositCallbackTest.php --filter test_callback_rejected
     */
    public function test_callback_rejected_when_source_ip_not_in_whitelist(): void
    {
        $this->cashFlow->update(['whitelist' => '0.0.0.0']);

        $orderNo = 'EP_DEP_BLOCKED';
        $order = $this->createCreatedOrder($orderNo);

        // 送一筆「成功」回調：擋住的話訂單不會上分，沒擋住就會
        $request = $this->createRequest(['tx_id' => $orderNo, 'status' => 'COMPLETE']);

        $result = (new DepositCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertSame('fail', $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }

    /**
     * 需求 1：白名單沒設定時也一律拒絕（fail-closed，不是「關掉檢查」）。
     */
    public function test_callback_rejected_when_whitelist_not_configured(): void
    {
        $this->cashFlow->update(['whitelist' => '']);

        $orderNo = 'EP_DEP_NO_WL';
        $order = $this->createCreatedOrder($orderNo);

        $result = (new DepositCallback($this->agentCashFlow))->handle($request = $this->createRequest([
            'tx_id' => $orderNo,
            'status' => 'COMPLETE',
        ]));

        $this->assertSame('fail', $result->response);
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }

    /**
     * 往返紀錄：被白名單擋下的回調也要留下證據。
     *
     * 這是整張表存在的理由——被擋時訂單完全不動，跟「玩家根本沒付」長得一樣，
     * 沒有這筆紀錄客服就查不出為什麼沒上分。
     */
    #[TestDox('回調被擋時仍寫入往返紀錄，含來源 IP 與判定結果')]
    public function test_blocked_callback_is_recorded(): void
    {
        $this->cashFlow->update(['whitelist' => '0.0.0.0']);

        $orderNo = 'EP_DEP_LOGGED';
        $this->createCreatedOrder($orderNo);

        (new DepositCallback($this->agentCashFlow))->handle(
            $this->createRequest(['tx_id' => $orderNo, 'status' => 'COMPLETE'])
        );

        $log = OrderRequestLog::where('order_no', $orderNo)->latest('id')->first();
        $this->assertNotNull($log, '被擋的回調必須留下紀錄');
        $this->assertSame('deposit_callback', $log->action);
        $this->assertSame('failed:ip_not_whitelisted', $log->result);
        $this->assertSame('deposit_order', $log->orderable_type);
        $this->assertSame('0.0.0.0', $log->source_ip);
        // 原文要留：validate() 之前就被擋下，仍要看得到三方送了什麼
        $this->assertStringContainsString('COMPLETE', (string) $log->request_body);
    }

    #[TestDox('正常處理的回調也要寫入往返紀錄，result 為 ok')]
    public function test_successful_callback_is_recorded(): void
    {
        $this->cashFlow->update(['whitelist' => '0.0.0.0']);

        $orderNo = 'EP_DEP_OK_LOG';
        $this->createCreatedOrder($orderNo);

        // REJECT 是終態，只標記訂單狀態、不動餘額，足以驗證走完整條路徑
        (new DepositCallback($this->agentCashFlow))->handle(
            $this->createRequest(['tx_id' => $orderNo, 'status' => 'REJECT'])
        );

        $log = OrderRequestLog::where('order_no', $orderNo)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('ok', $log->result);
        $this->assertNotNull($log->orderable_id, '正常處理時要掛得到訂單');
    }

    private function createCreatedOrder(string $orderNo): Order
    {
        return Order::factory()->create([
            'order_no' => $orderNo,
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_type_id' => 0,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 100,
            'status' => OrderStatusEnum::CREATED->value,
            'admin' => 0,
        ]);
    }

    private function createRequest(array $overrides = []): Request
    {
        $data = array_merge([
            'tx_id' => 'EP_DEP_DEFAULT',
            'transferror_name' => 'player001',
            'status' => 'PENDING',
            'order_id' => 'EP_TP_DEFAULT',
            'final_amount' => '100.00',
            'order_amount' => '100.00',
            'service_change' => '0.00',
        ], $overrides);

        return Request::create('/callback', 'POST', $data);
    }

    /**
     * status=PENDING（非終態）→ 訂單維持 CREATED(1)，不被誤判為失敗。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/EasyPay/DepositCallbackTest.php --filter test_pending_status_keeps_order_created
     */
    public function test_pending_status_keeps_order_created(): void
    {
        $orderNo = 'EP_DEP_PENDING';
        $order = $this->createCreatedOrder($orderNo);

        $request = $this->createRequest([
            'tx_id' => $orderNo,
            'status' => 'PENDING',
        ]);

        $result = (new DepositCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        // PENDING → 非終態，訂單維持 CREATED(1)，不入帳也不標失敗
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }

    /**
     * 未知 status（非官方枚舉碼，如 OVERTIME）→ 保守維持 CREATED，不武斷判失敗。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/EasyPay/DepositCallbackTest.php --filter test_unknown_status_keeps_order_created
     */
    public function test_unknown_status_keeps_order_created(): void
    {
        $orderNo = 'EP_DEP_OVERTIME';
        $order = $this->createCreatedOrder($orderNo);

        $request = $this->createRequest([
            'tx_id' => $orderNo,
            'status' => 'OVERTIME',
        ]);

        $result = (new DepositCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        // 未知碼 → 維持 CREATED(1)
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }
}
