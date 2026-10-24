<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\MaxPay;

use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\MaxPay\DepositCallback;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * MaxPay 代收回調的來源 IP 防線（工單 target/tickets#4449 需求 2）。
 *
 * MaxPay 的代收回調沒有驗簽（`MaxPayTrait::generateHash` 只用在建單與代付請求），金額也直接
 * 取回調自帶的 `amount`，所以來源 IP 是唯一擋得住偽造回調的防線。
 *
 * 這支只測防線本身。兩個負向案例被擋在 `$request->validate()` 之前，連訂單都還沒查；
 * 正向案例改送「失敗」回調，只會標記訂單狀態、不動餘額，足以證明防線放行了。
 * 上分那條路徑由既有的整合測試覆蓋。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/MaxPay/DepositCallbackTest.php
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

        $this->cashFlow = CashFlow::find(180) ?? CashFlow::forceCreate([
            'id' => 180,
            'third_party_code' => 'D_MAXPAY',
            'name' => 'MaxPay - Deposit',
            'type' => 1,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        $info = [
            'API_URL' => 'https://example.invalid',
            'API_KEY' => 'test_api_key',
            'SECRET_KEY' => 'test_secret_key',
            'CURRENCY' => 'SGD',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 180,
            'cash_flow_info_id' => 1,
            'currency_id' => 1,
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

    /** Request::create() 的來源是 0.0.0.0 */
    private function sendCallback(int $statusId = 1): DepositCallbackResponseData
    {
        $request = Request::create('/callback', 'POST', [
            'status_id' => $statusId,
            'amount' => '100.00',
            'ref_no' => 'MP_DEP_BLOCKED',
            'transaction_reference' => 'MP_TP_BLOCKED',
        ]);

        return (new DepositCallback($this->agentCashFlow))->handle($request);
    }

    #[TestDox('來源 IP 不在白名單時拒絕')]
    public function test_rejects_source_ip_not_in_whitelist(): void
    {
        $this->cashFlow->update(['whitelist' => '0.0.0.0']);

        $this->assertSame('FAIL', $this->sendCallback()->response);
    }

    #[TestDox('白名單沒設定時也一律拒絕，不是關掉檢查')]
    public function test_rejects_when_whitelist_not_configured(): void
    {
        $this->cashFlow->update(['whitelist' => '']);

        $this->assertSame('FAIL', $this->sendCallback()->response);
    }

    /**
     * 需求 4（target/tickets#4449）：回調只能處理自己金流商的訂單。
     *
     * 這是共用的 DepositCallbackTrait::orderBelongsToCallbackChannel() 在守的規則，33 家代收
     * 回調都走它。沒有這道的話，攻擊者只要有任何一家無驗簽金流商的回調網址，就能拿它去結算
     * 其他金流商建立的訂單——工單需求 4 的測試步驟講的正是這個情境。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/MaxPay/DepositCallbackTest.php --filter test_rejects_order_from
     */
    #[TestDox('訂單屬於別家金流商時拒絕，訂單狀態不動')]
    public function test_rejects_order_from_another_provider(): void
    {
        $this->cashFlow->update(['whitelist' => '0.0.0.0']);

        // 同一個訂單號，但訂單掛在別家金流商的渠道底下（模擬「拿本渠道的回調網址去結算別人的單」）
        $otherChannel = AgentCashFlow::create([
            'cash_flow_id' => $this->createOtherProviderCashFlow()->id,
            'cash_flow_info_id' => 1,
            'currency_id' => 1,
            'rate' => 1.0,
            'info' => json_encode([]),
            'status' => 1,
            'admin' => 0,
        ]);

        $order = Order::factory()->create([
            'order_no' => 'MP_DEP_BLOCKED',
            'order_type_id' => 0,
            'agent_cash_flow_id' => $otherChannel->id,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 100,
            'status' => OrderStatusEnum::CREATED->value,
            'admin' => 0,
        ]);

        // 送「失敗」回調：擋住的話訂單不會被標記，沒擋住就會變成 FAILED
        $this->sendCallback(statusId: 2);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }

    /**
     * 同一家金流商底下的另一筆 ACF 送來的回調要放行。
     *
     * 回調網址常由金流商固定寫在商戶後台，我方重建 ACF 後後台沒跟著改，回調就打到舊 ACF
     * （2026-10-12 TPower FPay：訂單在 271、回調打 263）。比 ACF 會把這些真回調全數擋掉。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/MaxPay/DepositCallbackTest.php --filter test_allows_order_from_another_merchant
     */
    #[TestDox('訂單屬於同金流商的另一筆 ACF 時放行')]
    public function test_allows_order_from_another_merchant_of_the_same_provider(): void
    {
        $this->cashFlow->update(['whitelist' => '0.0.0.0']);

        $sameProviderChannel = AgentCashFlow::create([
            'cash_flow_id' => $this->cashFlow->id,
            'cash_flow_info_id' => 1,
            'currency_id' => 1,
            'rate' => 1.0,
            'info' => json_encode([]),
            'status' => 1,
            'admin' => 0,
        ]);

        $order = Order::factory()->create([
            'order_no' => 'MP_DEP_BLOCKED',
            'order_type_id' => 0,
            'agent_cash_flow_id' => $sameProviderChannel->id,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 100,
            'status' => OrderStatusEnum::CREATED->value,
            'admin' => 0,
        ]);

        // 送「失敗」回調：放行的話訂單會被標記 FAILED，被擋則停在 CREATED
        $this->assertSame('OK', $this->sendCallback(statusId: 2)->response);
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status);
    }

    #[TestDox('來源 IP 在白名單內時放行，回調照常處理')]
    public function test_allows_whitelisted_source_ip(): void
    {
        $this->cashFlow->update(['whitelist' => '0.0.0.0']);

        $order = Order::factory()->create([
            'order_no' => 'MP_DEP_BLOCKED',
            'order_type_id' => 0,
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 100,
            'status' => OrderStatusEnum::CREATED->value,
            'admin' => 0,
        ]);

        // 送「失敗」回調：這條路徑只會標記訂單狀態、不動餘額，用來證明防線 1 放行了就夠。
        // 被擋的話訂單會停在 CREATED，兩者分得開。
        $response = $this->sendCallback(statusId: 2);

        $this->assertSame('OK', $response->response);
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status);
    }

    /** 另一家金流商（cash_flows），用來模擬跨金流商的回調 */
    private function createOtherProviderCashFlow(): CashFlow
    {
        return CashFlow::create([
            'third_party_code' => 'D_OTHER',
            'name' => 'Other - Deposit',
            'type' => 1,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);
    }
}
