<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\Ap8Pay;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use App\Models\Order;
use App\Models\OrderRequestLog;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Data\QueryOrderResponseData;
use App\Services\Payment\Enums\ProviderOrderStatusEnum;
use App\Services\Payment\Providers\Ap8Pay\DepositCallback;
use App\Services\Payment\Providers\Ap8Pay\QueryOrder;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Ap8Pay 代收回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/Ap8Pay/DepositCallbackTest.php
 *
 * 回調無驗簽，三道防線（來源 IP／訂單歸屬／反查為準）各自有測試守著，見 target/tickets#4446。
 * QueryOrder 一律以匿名子類覆寫 fetch() 注入，**本檔不發出任何真實 HTTP 請求**。
 */
class DepositCallbackTest extends TestCase
{
    /** 廠商提供的回調來源 IP，寫進 cash_flows.whitelist */
    private const VENDOR_IPS = '0.0.0.0,0.0.0.0';

    /** 白名單內的 IP */
    private const WHITELISTED_IP = '0.0.0.0';

    private Agent $agent;

    private CashFlow $cashFlow;

    private AgentCashFlow $agentCashFlow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        // 白名單讀 cash_flows.whitelist，ACF 與 CF 都必須真的落庫
        $this->cashFlow = $this->createCashFlow('D_AP8', self::VENDOR_IPS);
        $this->agentCashFlow = $this->ap8CashFlow($this->cashFlow->id);

        // 拒絕分支會發 TG 告警，測試不實際送出
        BotNotify::shouldReceive('channel')->andReturnSelf();
        BotNotify::shouldReceive('send');
    }

    /**
     * 测试成功回调 - SUCCESS 状态
     * php artisan test tests/Unit/Services/Payment/Providers/Ap8Pay/DepositCallbackTest.php --filter test_success
     */
    public function test_success()
    {
        // Arrange
        $order = $this->createOrder('479724394');

        $request = $this->callbackRequest([
            'code' => 'abc123',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724394',
            'amount' => '10.00',
            'details' => '{"key1":"value1", "key2":"value2"}',
        ]);

        // Act
        $result = $this->depositCallback($this->queried('479724394', ProviderOrderStatusEnum::SUCCESS, '10.00'))
            ->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);
        $this->assertNotNull($result->order);
        $this->assertNull($result->errorMessage);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('abc123', $order->third_party_order_no);
        $this->assertEquals(Common::numCompare('10.00', $order->amount), 0);
        $this->assertEquals(Common::numCompare('1.5', $order->rate), 0);
        $this->assertEquals(Common::numCompare('15.00', $order->point), 0);
    }

    /**
     * 真實 payload 回歸測試：欄位形狀照 VPlus33 訂單 2709 與 Dev 訂單 6599 的實際回調內容
     * （slip 檔名已改掉，那是玩家的轉帳憑證截圖，不入版控）。
     *
     * 與自編 fixture 的差異：order_no 是 18 碼、code 是 9 碼 taskCode、
     * details 是「JSON 字串包 JSON」而不是一般物件——remark 存的就是這一串。
     */
    public function test_real_world_success_callback_payload()
    {
        // Arrange
        $order = $this->createOrder('202605085775975046', ['third_party_order_no' => '7xQW5QsBH']);

        $payload = [
            'code' => '7xQW5QsBH',
            'status' => 'SUCCESS',
            'uniqueCode' => '202605085775975046',
            'amount' => '15.00',
            'details' => '{"slip":"https:\/\/example.invalid\/media\/0000000000000000000.jpg"}',
        ];

        $request = $this->callbackRequest($payload);

        // Act
        $result = $this->depositCallback(
            $this->queried('202605085775975046', ProviderOrderStatusEnum::SUCCESS, '15.00')
        )->handle($request);

        // Assert
        $this->assertEquals('OK', $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('7xQW5QsBH', $order->third_party_order_no);
        $this->assertEquals(Common::numCompare('15.00', $order->amount), 0);
        $this->assertEquals(Common::numCompare('22.50', $order->point), 0); // 15.00 * 1.5

        // details 原樣進 remark，不做任何解析
        $remark = json_decode((string) $order->remark, true);
        $this->assertSame($payload['details'], $remark['details']);
    }

    /**
     * 金額以反查結果為準：回調 body 謊報金額不影響上分金額
     */
    public function test_success_uses_queried_amount_not_callback_amount()
    {
        // Arrange
        $order = $this->createOrder('479724402');

        $request = $this->callbackRequest([
            'code' => 'abc131',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724402',
            'amount' => '999999.00',
        ]);

        // Act
        $result = $this->depositCallback($this->queried('479724402', ProviderOrderStatusEnum::SUCCESS, '20.00'))
            ->handle($request);

        // Assert
        $this->assertEquals('OK', $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals(Common::numCompare('20.00', $order->amount), 0);
        $this->assertEquals(Common::numCompare('30.00', $order->point), 0); // 20.00 * 1.5
    }

    /**
     * 建單時落庫的 taskCode 優先於回調 body 的 code
     */
    public function test_stored_task_code_wins_over_callback_code()
    {
        // Arrange
        $order = $this->createOrder('479724403', ['third_party_order_no' => 'stored-task']);

        $request = $this->callbackRequest([
            'code' => 'attacker-code',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724403',
            'amount' => '1.00',
        ]);

        $usedTaskCode = null;
        $callback = $this->depositCallback(
            $this->queried('479724403', ProviderOrderStatusEnum::SUCCESS, '1.00'),
            function (string $taskCode) use (&$usedTaskCode): void {
                $usedTaskCode = $taskCode;
            }
        );

        // Act
        $callback->handle($request);

        // Assert
        $this->assertSame('stored-task', $usedTaskCode);

        $order->refresh();
        $this->assertEquals('stored-task', $order->third_party_order_no);
    }

    /**
     * 测试失败回调 - FAILED 状态
     */
    public function test_failed()
    {
        // Arrange
        $order = $this->createOrder('479724395');

        $request = $this->callbackRequest([
            'code' => 'abc124',
            'status' => 'FAILED',
            'uniqueCode' => '479724395',
            'amount' => '5.00',
        ]);

        // Act
        $result = $this->depositCallback($this->queried('479724395', ProviderOrderStatusEnum::FAIL, '5.00'))
            ->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);
        $this->assertNotNull($result->order);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status);
        $this->assertEquals('abc124', $order->third_party_order_no);
        $this->assertEquals(Common::numCompare('5.00', $order->amount), 0);
        $this->assertEquals(Common::numCompare('1.5', $order->rate), 0);
        $this->assertEquals(Common::numCompare('7.50', $order->point), 0); // 5.00 * 1.5 = 7.50
    }

    /**
     * 防線 1：白名單外的來源 IP 一律拒絕，訂單不動
     */
    public function test_callback_from_unlisted_ip_is_rejected()
    {
        // Arrange
        $order = $this->createOrder('479724404');

        $request = $this->callbackRequest([
            'code' => 'abc132',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724404',
            'amount' => '10.00',
        ], ip: '0.0.0.0');

        // Act
        $result = $this->depositCallback($this->queried('479724404', ProviderOrderStatusEnum::SUCCESS, '10.00'))
            ->handle($request);

        // Assert
        $this->assertEquals('FAIL', $result->response);
        $this->assertEquals('Callback rejected', $result->errorMessage);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
        $this->assertEquals(Common::numCompare('0', $order->amount), 0);
        $this->assertEquals(Common::numCompare('0', $order->point), 0);
    }

    /**
     * 被擋下的回調要留下往返紀錄，而且 result 要說得出是哪一道擋的
     * ——我方回給三方的 body 一律是 FAIL，光看 response 分不出來
     */
    public function test_rejected_callback_is_recorded_with_reason()
    {
        // Arrange
        $this->createOrder('479724420');

        $request = $this->callbackRequest([
            'code' => 'abc140',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724420',
            'amount' => '10.00',
            // 三方實際會送、但 validate 沒宣告的欄位：日誌要存得到
            'type' => 'QR',
        ], ip: '0.0.0.0');

        // Act
        $this->depositCallback($this->queried('479724420', ProviderOrderStatusEnum::SUCCESS, '10.00'))
            ->handle($request);

        // Assert
        $log = OrderRequestLog::where('order_no', '479724420')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('deposit_callback', $log->action);
        $this->assertSame('failed:ip_not_whitelisted', $log->result);
        $this->assertSame('0.0.0.0', $log->source_ip);
        $this->assertSame('abc140', $log->third_party_order_no);
        $this->assertSame('FAIL', $log->response_body);
        // request_body 存 $request->all() 而非 validate 後的 $input，所以含 type
        $this->assertStringContainsString('"type":"QR"', (string) $log->request_body);
        // 原始 header 要留著，才能複驗 Common::getIp() 的解析
        $this->assertStringContainsString('host', (string) $log->request_headers);
    }

    /**
     * 放行的回調同樣留紀錄，result 為 ok
     */
    public function test_accepted_callback_is_recorded_as_ok()
    {
        // Arrange
        $this->createOrder('479724421', ['third_party_order_no' => 'task-ok']);

        $request = $this->callbackRequest([
            'code' => 'task-ok',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724421',
            'amount' => '10.00',
        ]);

        // Act
        $this->depositCallback($this->queried('479724421', ProviderOrderStatusEnum::SUCCESS, '10.00'))
            ->handle($request);

        // Assert
        $log = OrderRequestLog::where('order_no', '479724421')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('ok', $log->result);
        $this->assertSame('task-ok', $log->third_party_order_no);
        $this->assertSame('OK', $log->response_body);
    }

    /**
     * 防線 1：白名單為空時 fail-closed，連廠商 IP 都拒絕
     */
    public function test_empty_whitelist_rejects_everything()
    {
        // Arrange
        $this->cashFlow->update(['whitelist' => '']);
        $this->agentCashFlow->refresh();

        $order = $this->createOrder('479724405');

        $request = $this->callbackRequest([
            'code' => 'abc133',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724405',
            'amount' => '10.00',
        ]);

        // Act
        $result = $this->depositCallback($this->queried('479724405', ProviderOrderStatusEnum::SUCCESS, '10.00'))
            ->handle($request);

        // Assert
        $this->assertEquals('FAIL', $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }

    /**
     * 防線 2：Ap8Pay 的回調不能結掉別家金流商的訂單
     */
    public function test_callback_cannot_settle_another_providers_order()
    {
        // Arrange
        $otherProvider = $this->ap8CashFlow($this->createCashFlow('D_OTHER', self::VENDOR_IPS)->id);
        $order = $this->createOrder('479724406', ['agent_cash_flow_id' => $otherProvider->id]);

        $request = $this->callbackRequest([
            'code' => 'abc134',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724406',
            'amount' => '10.00',
        ]);

        // Act
        $result = $this->depositCallback($this->queried('479724406', ProviderOrderStatusEnum::SUCCESS, '10.00'))
            ->handle($request);

        // Assert
        $this->assertEquals('FAIL', $result->response);
        $this->assertEquals('Callback rejected', $result->errorMessage);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
        $this->assertEquals(Common::numCompare('0', $order->amount), 0);
    }

    /**
     * 防線 2 綁的是支付商不是商戶：同一支付商的另一筆 ACF 送來的回調要放行。
     *
     * 回調網址常由支付商固定寫在商戶後台，我方重建 ACF（換費率／換商戶號）後後台沒跟著改，
     * 回調就會打到舊 ACF；比商戶的話這些真回調會全數被擋，需要人工補單。
     */
    public function test_callback_allows_another_merchant_of_the_same_provider()
    {
        // Arrange
        $anotherMerchant = $this->ap8CashFlow($this->cashFlow->id);
        $order = $this->createOrder('479724411', ['agent_cash_flow_id' => $anotherMerchant->id]);

        $request = $this->callbackRequest([
            'code' => 'abc139',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724411',
            'amount' => '10.00',
        ]);

        // Act
        $result = $this->depositCallback($this->queried('479724411', ProviderOrderStatusEnum::SUCCESS, '10.00'))
            ->handle($request);

        // Assert
        $this->assertEquals('OK', $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
    }

    /**
     * 防線 3：三方說還沒付款，假造的成功回調不上分
     */
    public function test_forged_success_callback_is_rejected_when_provider_still_pending()
    {
        // Arrange
        $order = $this->createOrder('479724407');

        $request = $this->callbackRequest([
            'code' => 'abc135',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724407',
            'amount' => '10.00',
        ]);

        // Act
        $result = $this->depositCallback($this->queried('479724407', ProviderOrderStatusEnum::PENDING, null))
            ->handle($request);

        // Assert
        $this->assertEquals('FAIL', $result->response);
        $this->assertEquals('Deposit not finalized', $result->errorMessage);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
        $this->assertEquals(Common::numCompare('0', $order->amount), 0);
        $this->assertEquals(Common::numCompare('0', $order->point), 0);
    }

    /**
     * 防線 3：反查回來的商戶訂單號不是這一筆就拒絕（擋「借別單 taskCode」）
     */
    public function test_query_returning_another_order_is_rejected()
    {
        // Arrange
        $order = $this->createOrder('479724408');

        $request = $this->callbackRequest([
            'code' => 'abc136',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724408',
            'amount' => '10.00',
        ]);

        // Act
        $result = $this->depositCallback($this->queried('other-order-no', ProviderOrderStatusEnum::SUCCESS, '10.00'))
            ->handle($request);

        // Assert
        $this->assertEquals('FAIL', $result->response);
        $this->assertEquals('Callback rejected', $result->errorMessage);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }

    /**
     * 防線 3：查單失敗不當成失敗訂單，訂單維持原狀等三方重送
     */
    public function test_query_failure_leaves_order_untouched()
    {
        // Arrange
        $order = $this->createOrder('479724409');

        $request = $this->callbackRequest([
            'code' => 'abc137',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724409',
            'amount' => '10.00',
        ]);

        // Act
        $result = $this->depositCallback(new ApiException('Ap8Pay query error: invalid response'))
            ->handle($request);

        // Assert
        $this->assertEquals('FAIL', $result->response);
        $this->assertEquals('Deposit query failed', $result->errorMessage);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }

    /**
     * 防線 3：反查成功卻沒帶金額時不上分
     */
    public function test_success_without_queried_amount_is_rejected()
    {
        // Arrange
        $order = $this->createOrder('479724410');

        $request = $this->callbackRequest([
            'code' => 'abc138',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724410',
            'amount' => '10.00',
        ]);

        // Act
        $result = $this->depositCallback($this->queried('479724410', ProviderOrderStatusEnum::SUCCESS, null))
            ->handle($request);

        // Assert
        $this->assertEquals('FAIL', $result->response);
        $this->assertEquals('Deposit query returned no amount', $result->errorMessage);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }

    /**
     * 测试订单不存在
     */
    public function test_order_not_found()
    {
        // Arrange
        $request = $this->callbackRequest([
            'code' => 'abc123',
            'status' => 'SUCCESS',
            'uniqueCode' => '999999999',
            'amount' => '1.00',
        ]);

        // Act
        $result = $this->depositCallback($this->queried('999999999', ProviderOrderStatusEnum::SUCCESS, '1.00'))
            ->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('FAIL', $result->response);
        $this->assertEquals('Order not found', $result->errorMessage);
        $this->assertNull($result->order);
    }

    /**
     * 测试重复回调 - 订单已上分
     */
    public function test_duplicate_callback_already_finished()
    {
        // Arrange
        $order = $this->createOrder('479724396', [
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => '100.00',
        ]);

        $request = $this->callbackRequest([
            'code' => 'abc125',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724396',
            'amount' => '1.00',
        ]);

        // Act
        $result = $this->depositCallback($this->queried('479724396', ProviderOrderStatusEnum::SUCCESS, '1.00'))
            ->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('Order already deposit finished', $result->response);
        $this->assertEquals('Order already processed', $result->errorMessage);
        $this->assertNotNull($result->order);

        // 订单状态不应改变
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::DEPOSIT_FINISHED->value, $order->status);
    }

    /**
     * 测试验证失败 - 缺少必需参数
     */
    public function test_validation_failed()
    {
        // Arrange
        $request = $this->callbackRequest([
            'code' => 'abc123',
            // 缺少 status, uniqueCode, amount
        ]);

        // Assert & Act
        $this->expectException(ValidationException::class);
        $this->depositCallback($this->queried('x', ProviderOrderStatusEnum::SUCCESS, '1.00'))->handle($request);
    }

    /**
     * 测试验证失败 - code 为空
     */
    public function test_validation_failed_empty_code()
    {
        // Arrange
        $request = $this->callbackRequest([
            'code' => '',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724394',
            'amount' => '1.00',
        ]);

        // Assert & Act
        $this->expectException(ValidationException::class);
        $this->depositCallback($this->queried('479724394', ProviderOrderStatusEnum::SUCCESS, '1.00'))->handle($request);
    }

    /**
     * 测试 details 为可选参数
     */
    public function test_without_details()
    {
        // Arrange
        $order = $this->createOrder('479724397');

        $request = $this->callbackRequest([
            'code' => 'abc126',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724397',
            'amount' => '3.00',
            // details 不传
        ]);

        // Act
        $result = $this->depositCallback($this->queried('479724397', ProviderOrderStatusEnum::SUCCESS, '3.00'))
            ->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);
        $this->assertNotNull($result->order);

        $order->refresh();
        $this->assertEquals(Common::numCompare('3.00', $order->amount), 0);
        $this->assertEquals(Common::numCompare('4.50', $order->point), 0); // 3.00 * 1.5 = 4.50
    }

    /**
     * 测试 remark 字段包含完整的 input 数据
     */
    public function test_remark_contains_full_input()
    {
        // Arrange
        $order = $this->createOrder('479724401');

        $inputData = [
            'code' => 'abc130',
            'status' => 'SUCCESS',
            'uniqueCode' => '479724401',
            'amount' => '8.00',
            'details' => '{"key1":"value1"}',
        ];

        $request = $this->callbackRequest($inputData);

        // Act
        $this->depositCallback($this->queried('479724401', ProviderOrderStatusEnum::SUCCESS, '8.00'))
            ->handle($request);

        // Assert
        $order->refresh();
        $remark = json_decode($order->remark, true);
        $this->assertEquals($inputData['code'], $remark['code']);
        $this->assertEquals($inputData['status'], $remark['status']);
        $this->assertEquals($inputData['uniqueCode'], $remark['uniqueCode']);
        $this->assertEquals($inputData['amount'], $remark['amount']);
        $this->assertEquals($inputData['details'], $remark['details']);
        $this->assertEquals(Common::numCompare('8.00', $order->amount), 0);
        $this->assertEquals(Common::numCompare('12.00', $order->point), 0); // 8.00 * 1.5 = 12.00
    }

    /** 建一筆支付商（cash_flows），白名單就掛在這一層 */
    private function createCashFlow(string $thirdPartyCode, string $whitelist): CashFlow
    {
        return CashFlow::create([
            'third_party_code' => $thirdPartyCode,
            'name' => $thirdPartyCode . ' - Deposit',
            'type' => 1,
            'status' => 1,
            'info' => '',
            'whitelist' => $whitelist,
            'admin' => 0,
        ]);
    }

    /** 建一筆帶 Ap8Pay 憑證的 ACF（真的落庫，防線 2 經它取 cash_flow_id 比對） */
    private function ap8CashFlow(int $cashFlowId): AgentCashFlow
    {
        return $this->createAgentCashFlow($this->agent, [
            'cash_flow_id' => $cashFlowId,
            'rate' => 1.5,
            'info' => [
                'MerchantKey' => 'test_key',
                'HeaderApiKey' => 'test_header_api_key',
                'APIUrl' => 'https://example.invalid',
            ],
        ]);
    }

    private function createOrder(string $orderNo, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'agent_id' => $this->agent->id,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'order_type_id' => 1,
            'currency_id' => 1,
            'member_id' => 1,
            'third_party_order_no' => '',
            'order_no' => $orderNo,
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => 0,
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ], $overrides));
    }

    /**
     * 建立回調請求，並把它綁進容器——Common::getIp() 讀的是容器裡的 request，不是傳進去的那個
     */
    private function callbackRequest(array $payload, string $ip = self::WHITELISTED_IP): Request
    {
        $request = Request::create('/callback', 'POST', $payload, server: ['REMOTE_ADDR' => $ip]);
        $this->app->instance('request', $request);

        return $request;
    }

    private function queried(string $orderNo, ProviderOrderStatusEnum $status, ?string $amount): QueryOrderResponseData
    {
        return QueryOrderResponseData::from([
            'type' => 'deposit',
            'orderNo' => $orderNo,
            'orderStatus' => $status,
            'amount' => $amount,
        ]);
    }

    /**
     * 以覆寫 fetch() 的 QueryOrder 建 DepositCallback，確保測試不發出真實 HTTP 請求
     */
    private function depositCallback(QueryOrderResponseData|Exception $queryResult, ?callable $spy = null): DepositCallback
    {
        $queryOrder = new class($this->agentCashFlow, $queryResult, $spy) extends QueryOrder
        {
            public function __construct(
                AgentCashFlow $agentCashFlow,
                private QueryOrderResponseData|Exception $queryResult,
                private $spy,
            ) {
                parent::__construct($agentCashFlow);
            }

            public function fetch(string $taskCode, ?Order $order = null): QueryOrderResponseData
            {
                if ($this->spy !== null) {
                    ($this->spy)($taskCode);
                }

                if ($this->queryResult instanceof Exception) {
                    throw $this->queryResult;
                }

                return $this->queryResult;
            }
        };

        return new DepositCallback($this->agentCashFlow, $queryOrder);
    }
}
