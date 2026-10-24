<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\MajesticPay;

use App\Enums\General\HttpCode;
use App\Enums\OrderStatusEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\MajesticPay\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * MajesticPay 代收回調 characterization 測試。
 *
 * 目的：在「GMPay/MajesticPay 全面拆分（完全複製、零繼承）」重構前，
 * 把 MajesticPay/DepositCallback 現有 handle() 行為釘成基準，證明拆分前後行為一致。
 * 特別鎖定 MajesticPay 與 GMPay 相反的關鍵點：applyCreditAmountAdjustment 為 no-op，
 * 不以回調 amount 重算 orders.amount / orders.point（GMPay 才重算）。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/MajesticPay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private OrderType $orderType;

    private string $secretKey = 'majesticpay_test_secret';

    /** MajesticPay MYR 代收匯率 */
    private string $rate = '1.5';

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

        $this->orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'MajesticPay Callback Test',
            'type' => 1,
            'status' => 1,
            'key' => 'OnlineBanking',
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    private function makeAgentCashFlow(): AgentCashFlow
    {
        $info = [
            'API_URL' => 'https://api.majesticpay.test',
            'SECRET_KEY' => $this->secretKey,
        ];

        $cashFlow = new AgentCashFlow([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => $this->rate,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $cashFlow->info = $info;

        return $cashFlow;
    }

    /**
     * 建立待回調的代收訂單（status = CREATED，point = amount × rate）
     */
    private function makeOrder(string $amount, string $thirdPartyNo, int $status = OrderStatusEnum::CREATED->value): Order
    {
        return Order::create([
            // 回調檢查比對訂單 ACF 的 cash_flow_id，訂單須掛在同一家金流商的 ACF 下
            'agent_cash_flow_id' => $this->createAgentCashFlow($this->agent, ['cash_flow_id' => 1])->id,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $this->orderType->id,
            'currency_id' => 1,
            'amount' => $amount,
            'rate' => $this->rate,
            'point' => Common::numMul($amount, $this->rate, 4),
            'balance' => 0,
            'remark' => '',
            'order_no' => 'MAJTEST' . uniqid(),
            'status' => $status,
            'third_party_order_no' => $thirdPartyNo,
            'admin' => 0,
        ]);
    }

    /**
     * MajesticPay 回調驗簽：SHA256(ref_no + amount + currency)
     */
    private function generateHash(string $refNo, string $amount, string $currency): string
    {
        return hash_hmac('sha256', $refNo . $amount . $currency, $this->secretKey);
    }

    /**
     * 呼叫 MajesticPay 代收回調
     *
     * @param  array<string, mixed>  $extra  額外/覆寫欄位（如 is_repush、is_sandbox）
     */
    private function callCallback(
        Order $order,
        string $amount,
        string $status = 'completed',
        string $currency = 'MYR',
        ?string $transactionReference = null,
        ?string $hash = null,
        array $extra = [],
    ): DepositCallbackResponseData {
        $refNo = $order->order_no;
        $transactionReference ??= $order->third_party_order_no;

        $payload = array_merge([
            'status' => $status,
            'currency' => $currency,
            'amount' => $amount,
            'hash' => $hash ?? $this->generateHash($refNo, $amount, $currency),
            'ref_no' => $refNo,
            'platform_charge' => '0.00',
            'transaction_reference' => $transactionReference,
            'updated_at' => now()->toDateTimeString(),
            'is_sandbox' => 0,
        ], $extra);

        $request = Request::create('/callback', 'POST', $payload);

        return (new DepositCallback($this->makeAgentCashFlow()))->handle($request);
    }

    /**
     * 基準：completed 正常回調 → SUCCESS，且 orders.amount / orders.point **維持原值**
     * （no-op hook，不以回調 amount 重算——即使回調 amount 與下單金額不同）。
     */
    public function test_completed_callback_marks_success_without_recomputing_amount(): void
    {
        $order = $this->makeOrder(amount: '10.00', thirdPartyNo: 'MAJ_TXN_001');

        // 回調 amount 故意與下單金額不同，驗證 MajesticPay 不因此重算
        $result = $this->callCallback($order, amount: '10.10');

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertSame('OK', $result->response);
        $this->assertSame(HttpCode::HTTP_OK->value, $result->httpStatusCode);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
        // 關鍵：amount / point 維持下單原值（10.00 / 15.00），不因回調 amount(10.10) 改變
        $this->assertEqualsWithDelta(10.00, (float) $order->amount, 0.0001);
        $this->assertEqualsWithDelta(15.00, (float) $order->point, 0.0001);
    }

    /**
     * is_repush = 1（DuitNow 金額不符）→ WAITING_MANUAL_DEPOSIT。
     */
    public function test_is_repush_routes_to_manual(): void
    {
        $order = $this->makeOrder(amount: '10.00', thirdPartyNo: 'MAJ_TXN_002');

        $result = $this->callCallback($order, amount: '10.00', extra: [
            'is_repush' => 1,
            'repush_amount' => '9.50',
        ]);

        $this->assertSame('OK', $result->response);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
    }

    /**
     * is_sandbox = 1 強制成功——即使 status = failed，仍標記 SUCCESS（繞過 status 判斷）。
     */
    public function test_sandbox_forces_success_even_when_status_failed(): void
    {
        $order = $this->makeOrder(amount: '10.00', thirdPartyNo: 'MAJ_TXN_003');

        $result = $this->callCallback($order, amount: '10.00', status: 'failed', extra: [
            'is_sandbox' => 1,
        ]);

        $this->assertSame('OK', $result->response);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
    }

    /**
     * status = failed（非 sandbox、非 repush）→ FAILED。
     */
    public function test_failed_status_marks_failed(): void
    {
        $order = $this->makeOrder(amount: '10.00', thirdPartyNo: 'MAJ_TXN_004');

        $result = $this->callCallback($order, amount: '10.00', status: 'failed');

        $order->refresh();
        $this->assertSame(OrderStatusEnum::FAILED->value, $order->status);
    }

    /**
     * transaction_reference 與 orders.third_party_order_no 不符 → 拒絕（Failed / 400），訂單不變。
     */
    public function test_transaction_reference_mismatch_rejected(): void
    {
        $order = $this->makeOrder(amount: '10.00', thirdPartyNo: 'MAJ_TXN_005');

        $result = $this->callCallback(
            $order,
            amount: '10.00',
            transactionReference: 'MAJ_TXN_WRONG',
        );

        $this->assertSame('Failed', $result->response);
        $this->assertSame(HttpCode::HTTP_BAD_REQUEST->value, $result->httpStatusCode);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->status);
    }

    /**
     * 驗簽失敗 → 拒絕（Failed / 400），訂單不變。
     */
    public function test_invalid_signature_rejected(): void
    {
        $order = $this->makeOrder(amount: '10.00', thirdPartyNo: 'MAJ_TXN_006');

        $result = $this->callCallback($order, amount: '10.00', hash: 'deadbeef_invalid_hash');

        $this->assertSame('Failed', $result->response);
        $this->assertSame(HttpCode::HTTP_BAD_REQUEST->value, $result->httpStatusCode);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->status);
    }

    /**
     * 空憑證守衛：SECRET_KEY 為空時 checkHash() 的 HMAC key 段為空、簽名可離線計算，
     * 攻擊者能偽造成功回調直接上分。守衛須在驗簽前先擋下（回 Failed），且訂單維持 CREATED。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->makeOrder('100.00', 'MJPAY_TP_EMPTY');

        $emptyInfo = ['API_URL' => 'https://api.majesticpay.test', 'SECRET_KEY' => ''];
        $emptyAcf = new AgentCashFlow([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => $this->rate,
            'info' => json_encode($emptyInfo),
            'status' => 1,
            'admin' => 0,
        ]);
        $emptyAcf->info = $emptyInfo;

        // 攻擊者以空 secret 自算 hash（MajesticPay 以 ref_no 拼簽）
        $forgedHash = hash_hmac('sha256', $order->order_no . '100.00' . 'MYR', '');
        $request = Request::create('/callback', 'POST', [
            'status' => 'completed',
            'currency' => 'MYR',
            'amount' => '100.00',
            'hash' => $forgedHash,
            'ref_no' => $order->order_no,
            'platform_charge' => '0.00',
            'transaction_reference' => $order->third_party_order_no,
            'updated_at' => now()->toDateTimeString(),
            'is_sandbox' => 0,
        ]);

        $result = (new DepositCallback($emptyAcf))->handle($request);

        $this->assertSame('Failed', $result->response);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->status);
    }
}
