<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TngPin;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\TngPin\DepositCallback;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * TngPin 代收回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private CashFlow $cashFlow;

    private Member $member;

    private array $info;

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

        $this->info = [
            'APIUrl' => 'https://api.tngpin.test',
            'ApiKey' => 'test_api_key',
            'ApiPass' => 'test_api_pass',
            'Merchant' => 'TestMerchant',
        ];

        // 回調來源 IP 白名單（target/tickets#4449 需求 3 起改為 fail-closed：沒配＝一律拒絕）。
        // 測試的 Request::create() 來源是 0.0.0.0，所以這裡必須放行它，
        // 否則每個正向案例都會在防線 1 就被擋掉，測不到它本來要測的東西。
        // 沿用既有的 cash_flow_id = 1（AgentCashFlowOrderTypeObserver 對它有 legacy 豁免，
        // 換成新建的渠道會踩到「acf 與 order_type 的 cash_flow_id 必須一致」那條不變式）
        $this->cashFlow = CashFlow::find(1) ?? CashFlow::forceCreate([
            'id' => 1,
            'third_party_code' => 'D_TNGPIN',
            'name' => 'TNGPIN代收',
            'type' => 1,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);
        $this->cashFlow->update(['whitelist' => '0.0.0.0']);

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => 1,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'info' => json_encode($this->info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $this->info;
        $this->agentCashFlow->setRelation('cashFlow', $this->cashFlow);

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * Phase 2 窄域正向：模擬 Deposit sendRequest timeout 後三方異步 callback。
     *   - 訂單 third_party_order_no 為空（timeout 沒拿到 txn）、PIN 存於 extra_content、status=CREATED
     *   - callback 帶我方不存在的 transaction_id + 同一 PIN + status='1'
     * 預期：Phase 1 (txn) miss → Phase 2 以 extra_content PIN 命中 → 回填真 txn → 上分 SUCCESS。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositCallbackTest.php --filter test_callback_timeout_orphan_reconciled_by_pin
     */
    public function test_callback_timeout_orphan_reconciled_by_pin(): void
    {
        $orderType = $this->createOrderType();
        AgentCashFlowOrderType::forceCreate([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'tax' => 0,
            'tax_percentage' => 0,
            'status' => 1,
            'settings' => '{}',
        ]);

        $pin = '76961923362939';
        // timeout 孤兒：third_party_order_no 為空，PIN 僅存於 extra_content
        $order = $this->createCreatedOrderWithPin($orderType->id, '', $pin);

        $newTransactionId = '4638EE3200C69DC3D0F3';
        $callbackData = [
            'result' => 'success',
            'status' => '1',
            'amount' => '100.00',
            'transaction_id' => $newTransactionId,
            'pin' => $pin,
            'merchant_ref' => 'TestMerchant',
            'signature' => 'will_be_bypassed',
            'message' => 'Transaction successful',
            'detail_message' => 'Reload Successful.',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);
        $result = $this->makeSignBypassedCallback()->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals($newTransactionId, $order->third_party_order_no, 'Phase 2 命中後應回填真 transaction_id');
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status, '訂單應上分為 SUCCESS');
        $this->assertEquals('100.0000', $order->amount, '訂單 amount 應更新為 callback 金額');
    }

    /**
     * Phase 2 對抗式負向：訂單 third_party_order_no 已是「另一個真 txn」、extra_content PIN 等於 callback PIN。
     * 收到未知 txn + 同 PIN 的 callback 時，Phase 2 嚴格限 third_party_order_no 為空，故不得命中此訂單。
     * 此測試對「fallback 仍以 PIN 過寬比對」的實作會 FAIL —— 確保窄域約束真的生效。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositCallbackTest.php --filter test_callback_unknown_txn_does_not_touch_order_with_real_txn
     */
    public function test_callback_unknown_txn_does_not_touch_order_with_real_txn(): void
    {
        $orderType = $this->createOrderType();

        $pin = '76961923362939';
        $existingTxn = 'EXISTING_REAL_TXN_0001';
        // 已歸屬訂單：third_party_order_no 是真 txn，但 extra_content 仍存著同一 PIN
        $order = $this->createCreatedOrderWithPin($orderType->id, $existingTxn, $pin);

        $callbackData = [
            'result' => 'success',
            'status' => '1',
            'amount' => '100.00',
            'transaction_id' => 'UNKNOWN_TXN_9999',
            'pin' => $pin,
            'merchant_ref' => 'TestMerchant',
            'signature' => 'will_be_bypassed',
            'message' => 'Transaction successful',
            'detail_message' => 'Reload Successful.',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);
        $result = $this->makeSignBypassedCallback()->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status, '已有真 txn 的訂單不得被未知 txn callback 命中');
        $this->assertEquals($existingTxn, $order->third_party_order_no, 'third_party_order_no 不應被改動');
    }

    /**
     * Phase 2 多候選 guard：同 cashflow 下兩筆 timeout 孤兒（third_party_order_no 空 + 同 PIN + CREATED）。
     * callback 帶該 PIN 時不應自動歸屬任何一筆（避免回填錯訂單），兩筆皆維持原狀，等人工。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositCallbackTest.php --filter test_callback_pin_fallback_multiple_candidates_no_attribution
     */
    public function test_callback_pin_fallback_multiple_candidates_no_attribution(): void
    {
        $orderType = $this->createOrderType();

        $pin = '76961923362939';
        $orderA = $this->createCreatedOrderWithPin($orderType->id, '', $pin);
        $orderB = $this->createCreatedOrderWithPin($orderType->id, '', $pin);

        $callbackData = [
            'result' => 'success',
            'status' => '1',
            'amount' => '100.00',
            'transaction_id' => 'UNKNOWN_TXN_9999',
            'pin' => $pin,
            'merchant_ref' => 'TestMerchant',
            'signature' => 'will_be_bypassed',
            'message' => 'Transaction successful',
            'detail_message' => 'Reload Successful.',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);
        $result = $this->makeSignBypassedCallback()->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $orderA->refresh();
        $orderB->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $orderA->status, '多候選時不得自動歸屬 A');
        $this->assertEquals(OrderStatusEnum::CREATED->value, $orderB->status, '多候選時不得自動歸屬 B');
        $this->assertEquals('', $orderA->third_party_order_no, 'A third_party_order_no 不應被回填');
        $this->assertEquals('', $orderB->third_party_order_no, 'B third_party_order_no 不應被回填');
    }

    /**
     * P0-1：status=3 (Processing) 回調不可把訂單標成 FAILED，應維持 CREATED 等待下一次 callback。
     * 否則後續真正成功的 callback 會被 checkDepositOrderStatus 以 'Order already processed' 擋下 → 已扣款不上分。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositCallbackTest.php --filter test_callback_status_3
     */
    public function test_callback_status_3_keeps_order_created(): void
    {
        $orderType = $this->createOrderType();
        $transactionId = 'TXN_PROCESSING_001';

        $order = $this->createCreatedOrder($orderType->id, $transactionId);

        $callbackData = [
            'result' => 'success',
            'status' => '3',
            'amount' => '0.00',
            'transaction_id' => $transactionId,
            'merchant_ref' => $order->order_no,
            'signature' => 'will_be_bypassed',
            'message' => 'Processing',
            'detail_message' => 'Processing',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);
        $depositCallback = $this->makeSignBypassedCallback();

        $result = $depositCallback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals(
            OrderStatusEnum::CREATED->value,
            $order->status,
            'status=3 (Processing) 應維持訂單 CREATED，不可標成 FAILED'
        );
    }

    /**
     * P0-2：成功回調的 remark 不可留 PIN 明文。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositCallbackTest.php --filter test_callback_masks_pin
     */
    public function test_callback_masks_pin_in_remark(): void
    {
        $orderType = $this->createOrderType();
        AgentCashFlowOrderType::forceCreate([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'tax' => 0,
            'tax_percentage' => 0,
            'status' => 1,
            'settings' => '{}',
        ]);

        $pin = '76961923362939';
        $transactionId = 'TXN_SUCCESS_002';
        $order = $this->createCreatedOrder($orderType->id, $transactionId);

        $callbackData = [
            'result' => 'success',
            'status' => '1',
            'amount' => '50.00',
            'transaction_id' => $transactionId,
            'pin' => $pin,
            'merchant_ref' => $order->order_no,
            'signature' => 'will_be_bypassed',
            'message' => 'Transaction successful',
            'detail_message' => 'Reload Successful.',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);
        $this->makeSignBypassedCallback()->handle($request);

        $order->refresh();
        $this->assertStringNotContainsString($pin, (string) $order->remark, 'remark 不可含明文 PIN');
        $this->assertStringContainsString('62939', (string) $order->remark, 'remark 應保留遮罩後 PIN 末 5 碼供對帳');
    }

    /**
     * P1-3：來源 IP 不在 cash_flows.whitelist 時應拒絕（簽章以外的第二道防線）。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositCallbackTest.php --filter test_callback_rejects_ip
     */
    public function test_callback_rejects_ip_not_in_whitelist(): void
    {
        // 設定白名單但不含請求來源 IP（Request::create 預設 0.0.0.0）
        $this->cashFlow->update(['whitelist' => '0.0.0.0']);

        $callbackData = [
            'status' => '1',
            'amount' => '50.00',
            'transaction_id' => 'TXN_IP_003',
            'signature' => 'whatever',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $this->expectException(ApiException::class);
        (new DepositCallback($this->agentCashFlow))->handle($request);
    }

    /**
     * 需求 3（target/tickets#4449）：白名單沒設定時一律拒絕，不是「關掉檢查」。
     *
     * 這是本次改動真正翻轉的行為——原本 guardCallbackIp() 在 CallbackAllowIps 為空時直接放行，
     * 等於沒有防線。TngPin 的充值面額由回調決定、無法以訂單預期金額交叉驗證，
     * 放行一筆偽造回調就是直接上分，所以不保留 fail-open 的退路。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositCallbackTest.php --filter test_callback_rejects_when_whitelist
     */
    public function test_callback_rejects_when_whitelist_not_configured(): void
    {
        $this->cashFlow->update(['whitelist' => '']);

        $request = Request::create('/callback', 'POST', [
            'status' => '1',
            'amount' => '50.00',
            'transaction_id' => 'TXN_IP_004',
            'signature' => 'whatever',
        ]);

        $this->expectException(ApiException::class);
        (new DepositCallback($this->agentCashFlow))->handle($request);
    }

    /**
     * Phase 0 主匹配：timeout 孤兒（third_party_order_no 為空、extra_content 無 PIN）由 callback 的
     * merchant_ref = order_no 經 order_no unique 索引直接命中——不需走 PIN 反查，並回填真 transaction_id。
     * 這是修法 B 的核心：孤兒回收改用 order_no 而非無索引的 extra_content->pin。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositCallbackTest.php --filter test_callback_matched_by_merchant_ref_order_no
     */
    public function test_callback_matched_by_merchant_ref_order_no(): void
    {
        $orderType = $this->createOrderType();
        AgentCashFlowOrderType::forceCreate([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'tax' => 0,
            'tax_percentage' => 0,
            'status' => 1,
            'settings' => '{}',
        ]);

        // timeout 孤兒：third_party_order_no 空，extra_content 無 PIN（證明不依賴 PIN 反查）
        $order = $this->createCreatedOrder($orderType->id, '');

        $newTransactionId = '4638EE3200C69DC3D0F3';
        $callbackData = [
            'result' => 'success',
            'status' => '1',
            'amount' => '100.00',
            'transaction_id' => $newTransactionId,
            'merchant_ref' => $order->order_no,
            'signature' => 'will_be_bypassed',
            'message' => 'Transaction successful',
            'detail_message' => 'Reload Successful.',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);
        $result = $this->makeSignBypassedCallback()->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals($newTransactionId, $order->third_party_order_no, 'Phase 0 命中孤兒後應回填真 transaction_id');
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status, '訂單應上分為 SUCCESS');
        $this->assertEquals('100.0000', $order->amount, '訂單 amount 應更新為 callback 金額');
    }

    /**
     * Phase 1 後備：callback 未帶 merchant_ref（模擬在途舊單 / 缺漏），Phase 0 略過，
     * 改以 transaction_id = third_party_order_no 命中已寫入 txn 的訂單。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositCallbackTest.php --filter test_callback_falls_back_to_transaction_id_when_merchant_ref_absent
     */
    public function test_callback_falls_back_to_transaction_id_when_merchant_ref_absent(): void
    {
        $orderType = $this->createOrderType();
        AgentCashFlowOrderType::forceCreate([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'tax' => 0,
            'tax_percentage' => 0,
            'status' => 1,
            'settings' => '{}',
        ]);

        $existingTxn = 'EXISTING_REAL_TXN_0007';
        $order = $this->createCreatedOrder($orderType->id, $existingTxn);

        // 刻意不帶 merchant_ref
        $callbackData = [
            'result' => 'success',
            'status' => '1',
            'amount' => '100.00',
            'transaction_id' => $existingTxn,
            'signature' => 'will_be_bypassed',
            'message' => 'Transaction successful',
            'detail_message' => 'Reload Successful.',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);
        $result = $this->makeSignBypassedCallback()->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status, 'merchant_ref 缺漏時應退回 transaction_id 命中並上分');
        $this->assertEquals($existingTxn, $order->third_party_order_no, 'third_party_order_no 應維持原 txn');
    }

    /**
     * Phase 0 防誤綁：訂單已綁 txnA（CREATED 但同步已寫入 third_party_order_no），
     * callback 以 merchant_ref=order_no 命中、卻帶不同 transaction_id(txnB)。
     * 不可把已記錄的三方交易改綁成 txnB → 應拋例外、rollback，訂單原封不動等人工。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositCallbackTest.php --filter test_callback_rejects_conflicting_transaction_id
     */
    public function test_callback_rejects_conflicting_transaction_id(): void
    {
        $orderType = $this->createOrderType();

        $boundTxn = 'TXN_ALREADY_BOUND_0001';
        $order = $this->createCreatedOrder($orderType->id, $boundTxn);

        $callbackData = [
            'result' => 'success',
            'status' => '1',
            'amount' => '100.00',
            'transaction_id' => 'TXN_DIFFERENT_9999',
            'merchant_ref' => $order->order_no,
            'signature' => 'will_be_bypassed',
            'message' => 'Transaction successful',
            'detail_message' => 'Reload Successful.',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        try {
            $this->makeSignBypassedCallback()->handle($request);
            $this->fail('已綁不同 txn 時應拋出 ApiException');
        } catch (ApiException $e) {
            // expected
        }

        $order->refresh();
        $this->assertEquals($boundTxn, $order->third_party_order_no, 'third_party_order_no 不可被改綁');
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status, '訂單狀態應維持 CREATED（已 rollback）');
    }

    /**
     * Phase 1 相容在途舊單：callback 帶 merchant_ref，但其值為固定商戶號（≠ order_no），Phase 0 不命中，
     * 改以 transaction_id = third_party_order_no 命中並上分。確保改主鍵後舊單仍接得到。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositCallbackTest.php --filter test_callback_legacy_merchant_ref_falls_back_to_transaction_id
     */
    public function test_callback_legacy_merchant_ref_falls_back_to_transaction_id(): void
    {
        $orderType = $this->createOrderType();
        AgentCashFlowOrderType::forceCreate([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'tax' => 0,
            'tax_percentage' => 0,
            'status' => 1,
            'settings' => '{}',
        ]);

        $existingTxn = 'OLD_INTRANSIT_TXN_0009';
        $order = $this->createCreatedOrder($orderType->id, $existingTxn);

        $callbackData = [
            'result' => 'success',
            'status' => '1',
            'amount' => '100.00',
            'transaction_id' => $existingTxn,
            // 舊單 merchant_ref 為固定商戶號，非 order_no
            'merchant_ref' => 'LEGACY_MERCHANT_NO',
            'signature' => 'will_be_bypassed',
            'message' => 'Transaction successful',
            'detail_message' => 'Reload Successful.',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);
        $result = $this->makeSignBypassedCallback()->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status, '舊單應由 transaction_id 命中並上分');
        $this->assertEquals($existingTxn, $order->third_party_order_no, 'third_party_order_no 應維持原 txn');
    }

    /**
     * Phase 2 競態防護：兩段式鎖定下，「無鎖撈到單筆候選」與「主鍵鎖定」之間，
     * 該訂單已被另一併發 callback 先處理（這裡預先改成 SUCCESS 模擬）。
     * 以 stale id 強制進入 Phase 2 命中分支，鎖定後重新驗證窄域條件應落空 →
     * 不自動歸屬、不回填，已處理訂單不被覆寫。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TngPin/DepositCallbackTest.php --filter test_callback_pin_fallback_revalidation_rejects_stale_candidate
     */
    public function test_callback_pin_fallback_revalidation_rejects_stale_candidate(): void
    {
        $orderType = $this->createOrderType();

        $pin = '76961923362939';
        // 候選原為合格 timeout 孤兒，但在鎖定前已被併發交易處理掉
        $order = $this->createCreatedOrderWithPin($orderType->id, '', $pin);
        $order->update(['status' => OrderStatusEnum::SUCCESS->value]);

        $callbackData = [
            'result' => 'success',
            'status' => '1',
            'amount' => '100.00',
            'transaction_id' => 'UNKNOWN_TXN_9999',
            'pin' => $pin,
            'merchant_ref' => 'TestMerchant',
            'signature' => 'will_be_bypassed',
            'message' => 'Transaction successful',
            'detail_message' => 'Reload Successful.',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);
        $result = $this->makeCallbackReturningCandidate($order->id)->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status, '重新驗證落空時不得覆寫已處理訂單');
        $this->assertEquals('', $order->third_party_order_no, 'third_party_order_no 不應被回填');
    }

    private function createOrderType(): OrderType
    {
        return OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'TngPin Umobile',
            'type' => 1,
            'status' => 1,
            'key' => 'umobile',
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    private function createCreatedOrder(int $orderTypeId, string $thirdPartyOrderNo): Order
    {
        return Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderTypeId,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => $thirdPartyOrderNo,
            'order_no' => 'TNG' . uniqid(),
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => 0,
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
            'extra_content' => '{}',
            'agent_cash_flow_id' => $this->agentCashFlow->id,
        ]);
    }

    /**
     * 建立 CREATED 訂單並可控 third_party_order_no、把 PIN 寫入 extra_content（窄域 Phase 2 反查用）。
     */
    private function createCreatedOrderWithPin(int $orderTypeId, string $thirdPartyOrderNo, string $pin): Order
    {
        return Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderTypeId,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => $thirdPartyOrderNo,
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
        ]);
    }

    /**
     * 以匿名類別繞過簽名驗證（簽名邏輯由 TngPinTraitTest 負責，這裡專注狀態流轉）。
     */
    private function makeSignBypassedCallback(): DepositCallback
    {
        return new class($this->agentCashFlow) extends DepositCallback
        {
            protected function checkSign(array $params, array $info): bool
            {
                return true;
            }
        };
    }

    /**
     * 繞過簽名，並讓 Phase 2 候選查詢固定回傳指定 id，
     * 用以模擬「無鎖撈到候選 → 主鍵鎖定」之間訂單已被併發處理的競態。
     */
    private function makeCallbackReturningCandidate(int $candidateId): DepositCallback
    {
        return new class($this->agentCashFlow, $candidateId) extends DepositCallback
        {
            public function __construct(AgentCashFlow $agentCashFlow, private int $candidateId)
            {
                parent::__construct($agentCashFlow);
            }

            protected function checkSign(array $params, array $info): bool
            {
                return true;
            }

            protected function findOrphanCandidateIds(string $pin): Collection
            {
                return collect([$this->candidateId]);
            }
        };
    }

    /**
     * 空憑證守衛：ApiKey 為空時 checkSign() 退化為 md5(transaction_id + merchant_ref)、可離線計算，
     * 攻擊者能偽造成功回調直接上分。守衛須在驗簽前先擋下（拋 ApiException），且訂單維持 CREATED。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $orderType = $this->createOrderType();
        $order = $this->createCreatedOrder($orderType->id, 'TNG_EXISTING_TXN');

        $emptyInfo = [
            'APIUrl' => 'https://api.tngpin.test',
            'ApiKey' => '',
            'ApiPass' => 'test_api_pass',
            'Merchant' => 'TestMerchant',
        ];
        $emptyAcf = AgentCashFlow::create([
            'currency_id' => 1,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'info' => json_encode($emptyInfo),
            'status' => 1,
            'admin' => 0,
        ]);
        $emptyAcf->info = $emptyInfo;

        // 攻擊者以空 ApiKey 自算 signature：md5('' . transaction_id . merchant_ref)
        $transactionId = 'FORGED_TXN_0001';
        $merchantRef = 'TestMerchant';
        $forgedSign = md5('' . $transactionId . $merchantRef);
        $callbackData = [
            'result' => 'success',
            'status' => '1',
            'amount' => '100.00',
            'transaction_id' => $transactionId,
            'merchant_ref' => $merchantRef,
            'signature' => $forgedSign,
            'message' => 'Transaction successful',
            'detail_message' => 'Reload Successful.',
        ];
        $request = Request::create('/callback', 'POST', $callbackData);

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
