<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Deposit;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\Currency;
use App\Models\OrderType;
use App\Models\OrderTypeName;
use App\Services\AgentCashFlowListService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Agent/Deposit/ChannelsTest.php
 */
class ChannelsTest extends TestCase
{
    private string $apiPath = 'agent/api/deposit/channels';

    private Agent $agent;

    private Currency $currency;

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

        $this->currency = $this->createCurrency('CNY');
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    /**
     * currencies.code 自 2026-09-07 起有唯一鍵、且主檔種子已含 USD／CNY（多幣別錢包第二期）；
     * 對這兩個代碼 create() 會在灌過種子的測試庫撞 1062。改為有就覆寫屬性、沒有才建，
     * 夾具屬性因此不依賴測試庫現況（DatabaseTransactions 會在測試結束時回滾）。
     */
    private function createCurrency(string $code = 'CNY'): Currency
    {
        return Currency::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $code,
                'rate' => 1,
                'status' => 1,
                'sort' => 0,
                'admin' => 0,
            ],
        );
    }

    private function createCashFlow(int $type = 1, int $status = 1): CashFlow
    {
        return CashFlow::create([
            'name' => 'Test CashFlow',
            'type' => $type,
            'status' => $status,
            'info' => '',
            'admin' => 0,
        ]);
    }

    private function createAgentCashFlowForGroup(CashFlow $cashFlow, array $overrides = []): AgentCashFlow
    {
        return AgentCashFlow::create(array_merge([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'cash_flow_id' => $cashFlow->id,
            'cash_flow_info_id' => 0,
            'name' => 'Test ACF',
            'rate' => '1.00',
            'tax' => '0.00',
            'tax_percentage' => '0.00',
            'cost' => '0.00',
            'status' => 1,
            'backend_display' => 1,
            'info' => '',
            'admin' => 0,
        ], $overrides));
    }

    private function createOrderType(CashFlow $cashFlow, int $parent = 0, array $overrides = []): OrderType
    {
        return OrderType::create(array_merge([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => $this->currency->id,
            'parent' => $parent,
            'name' => 'Test OrderType',
            'key' => '--',
            'type' => 1,
            'status' => 1,
            'choose_bank' => 0,
            'admin' => 0,
        ], $overrides));
    }

    private function createAgentCashFlowOrderType(AgentCashFlow $agentCashFlow, OrderType $orderType, array $overrides = []): AgentCashFlowOrderType
    {
        return AgentCashFlowOrderType::create(array_merge([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'sorts' => 0,
            'status' => 1,
            'backend_display' => 1,
            'is_recommend' => 0,
            'settings' => '{}',
        ], $overrides));
    }

    private function act(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->apiPath, [], $this->headers);
    }

    // -------------------------------------------------------
    // Tests
    // -------------------------------------------------------

    /**
     * type=1 渠道搭配 AgentCashFlowOrderType 時，應展開為一筆完整輸出行，
     * 且 status / backend_display 來自 AgentCashFlowOrderType 而非 AgentCashFlow。
     */
    public function test_returns_type1_channel_with_order_type(): void
    {
        // Arrange
        $cashFlow = $this->createCashFlow(type: 1);
        $agentCashFlow = $this->createAgentCashFlowForGroup($cashFlow);
        $ot = $this->createOrderType($cashFlow);
        $agentCashFlowOrderType = $this->createAgentCashFlowOrderType($agentCashFlow, $ot, ['sorts' => 5, 'is_recommend' => 1]);

        // Act
        $response = $this->act();

        // Assert
        $response->assertStatus(200)->assertJson(['code' => 200]);

        $items = $response->json('data.agent_cash_flow_list');
        $this->assertCount(1, $items);

        $item = $items[0];
        $this->assertSame($agentCashFlow->id, $item['id']);
        $this->assertSame($cashFlow->id, $item['cash_flow_id']);
        $this->assertSame(1, $item['type']);
        $this->assertSame($ot->id, $item['order_type_id']);
        $this->assertSame($ot->name, $item['order_type_name']);
        $this->assertSame($agentCashFlowOrderType->id, $item['agent_cash_flow_order_type_id']);
        $this->assertSame(1, $item['is_recommend']);
        $this->assertSame(5, $item['sorts']);
        // type=1：status / backend_display 取自 agentCashFlowOrderType
        $this->assertSame($agentCashFlowOrderType->status, $item['status']);
        $this->assertSame($agentCashFlowOrderType->backend_display, $item['backend_display']);
    }

    /**
     * type=3（代付）渠道沒有 AgentCashFlowOrderType 時，仍應輸出一筆資料，
     * 且 status / backend_display 來自 AgentCashFlow 本身，order_type_id 為 0。
     */
    public function test_returns_type3_channel_without_acfot(): void
    {
        // Arrange — 代付渠道，無 AgentCashFlowOrderType
        $cashFlow = $this->createCashFlow(type: 3);
        $agentCashFlow = $this->createAgentCashFlowForGroup($cashFlow, [
            'status' => 1,
            'backend_display' => 1,
        ]);

        // Act
        $response = $this->act();

        // Assert
        $response->assertStatus(200)->assertJson(['code' => 200]);

        $items = $response->json('data.agent_cash_flow_list');
        $this->assertCount(1, $items);

        $item = $items[0];
        $this->assertSame($agentCashFlow->id, $item['id']);
        $this->assertSame(3, $item['type']);
        $this->assertNull($item['agent_cash_flow_order_type_id']);
        $this->assertSame(0, $item['order_type_id']);
        // type!=1：status / backend_display 取自 agentCashFlow 本身
        $this->assertSame($agentCashFlow->status, $item['status']);
        $this->assertSame($agentCashFlow->backend_display, $item['backend_display']);
    }

    /**
     * status=0 的 CashFlow 所綁定的渠道不應出現在列表中。
     */
    public function test_excludes_disabled_cash_flow(): void
    {
        // Arrange
        $this->createAgentCashFlowForGroup($this->createCashFlow(type: 1, status: 0));

        // Act
        $response = $this->act();

        // Assert
        $items = $response->json('data.agent_cash_flow_list');
        $this->assertCount(0, $items);
    }

    /**
     * type=1 渠道下，AgentCashFlowOrderType 若指向 parent != 0 的 OrderType，
     * eager load 回傳 null，該行應被跳過，不出現在清單中。
     */
    public function test_excludes_type1_acfot_whose_order_type_has_nonzero_parent(): void
    {
        // Arrange
        $cashFlow = $this->createCashFlow(type: 1);
        $agentCashFlow = $this->createAgentCashFlowForGroup($cashFlow);
        $parentOt = $this->createOrderType($cashFlow, parent: 0);
        $childOt = $this->createOrderType($cashFlow, parent: $parentOt->id); // parent != 0
        $this->createAgentCashFlowOrderType($agentCashFlow, $childOt);

        // Act
        $response = $this->act();

        // Assert — eager load 對 parent != 0 的 orderType 回傳 null，type=1 → 跳過
        $items = $response->json('data.agent_cash_flow_list');
        $this->assertCount(0, $items);
    }

    /**
     * display_name 多語言回退第一優先：存在當前語言翻譯時應優先使用，
     * 即使英文翻譯同時存在也不應被選取。
     */
    public function test_display_name_uses_current_language_first(): void
    {
        // Arrange — 語言 2（預設 Accept-Language = zh-TW → language_id = 2 依平台設定）
        $cashFlow = $this->createCashFlow(type: 1);
        $agentCashFlow = $this->createAgentCashFlowForGroup($cashFlow);
        $ot = $this->createOrderType($cashFlow);
        $this->createAgentCashFlowOrderType($agentCashFlow, $ot);

        OrderTypeName::create(['type_id' => $ot->id, 'language_id' => 1, 'name' => 'English Name', 'admin' => 0]);
        OrderTypeName::create(['type_id' => $ot->id, 'language_id' => 2, 'name' => '繁體中文名稱', 'admin' => 0]);

        // 直接以 Service 測試 display_name 邏輯
        $service = app(AgentCashFlowListService::class);
        $result = $service->getChannels($this->agent->cash_flow_group_id, languageId: 2);

        // Assert
        $this->assertSame('繁體中文名稱', $result['agent_cash_flow_list'][0]['display_name']);
    }

    /**
     * display_name 多語言回退第二優先：當前語言無翻譯時，應回退至英文（language_id=1）。
     */
    public function test_display_name_falls_back_to_english_when_current_language_missing(): void
    {
        // Arrange — 只有英文名稱（language_id=1），沒有語言 99
        $cashFlow = $this->createCashFlow(type: 1);
        $agentCashFlow = $this->createAgentCashFlowForGroup($cashFlow);
        $ot = $this->createOrderType($cashFlow);
        $this->createAgentCashFlowOrderType($agentCashFlow, $ot);

        OrderTypeName::create(['type_id' => $ot->id, 'language_id' => 1, 'name' => 'English Name', 'admin' => 0]);

        // Act
        $service = app(AgentCashFlowListService::class);
        $result = $service->getChannels($this->agent->cash_flow_group_id, languageId: 99);

        // Assert
        $this->assertSame('English Name', $result['agent_cash_flow_list'][0]['display_name']);
    }

    /**
     * display_name 多語言回退最終兜底：完全沒有 OrderTypeName 記錄時，
     * 應直接使用 OrderType.name 作為顯示名稱。
     */
    public function test_display_name_falls_back_to_order_type_name_when_no_translations(): void
    {
        // Arrange — 完全沒有 OrderTypeName 記錄
        $cashFlow = $this->createCashFlow(type: 1);
        $agentCashFlow = $this->createAgentCashFlowForGroup($cashFlow);
        $ot = $this->createOrderType($cashFlow, overrides: ['name' => 'Original Name']);
        $this->createAgentCashFlowOrderType($agentCashFlow, $ot);

        // Act
        $service = app(AgentCashFlowListService::class);
        $result = $service->getChannels($this->agent->cash_flow_group_id, languageId: 1);

        // Assert
        $this->assertSame('Original Name', $result['agent_cash_flow_list'][0]['display_name']);
    }

    /**
     * 回傳清單應依 sorts 降冪排序，sorts 相同時再依 AgentCashFlow.id 升冪排序。
     */
    public function test_sorts_by_sorts_desc_then_id_asc(): void
    {
        // Arrange — 兩筆渠道，sorts 分別為 10 / 20
        $cashFlow = $this->createCashFlow(type: 1);
        $agentCashFlow1 = $this->createAgentCashFlowForGroup($cashFlow);
        $agentCashFlow2 = $this->createAgentCashFlowForGroup($cashFlow);
        $ot1 = $this->createOrderType($cashFlow);
        $ot2 = $this->createOrderType($cashFlow);
        $this->createAgentCashFlowOrderType($agentCashFlow1, $ot1, ['sorts' => 10]);
        $this->createAgentCashFlowOrderType($agentCashFlow2, $ot2, ['sorts' => 20]);

        // Act
        $response = $this->act();

        // Assert — sorts=20 的應排在前面
        $items = $response->json('data.agent_cash_flow_list');
        $this->assertCount(2, $items);
        $this->assertSame(20, $items[0]['sorts']);
        $this->assertSame(10, $items[1]['sorts']);
    }

    /**
     * 同一個 AgentCashFlow 下有多筆 AgentCashFlowOrderType 時，
     * 每筆應各自展開為獨立一行輸出。
     */
    public function test_multiple_acfots_on_same_acf_each_produce_a_row(): void
    {
        // Arrange
        $cashFlow = $this->createCashFlow(type: 1);
        $agentCashFlow = $this->createAgentCashFlowForGroup($cashFlow);
        $ot1 = $this->createOrderType($cashFlow);
        $ot2 = $this->createOrderType($cashFlow);
        $this->createAgentCashFlowOrderType($agentCashFlow, $ot1);
        $this->createAgentCashFlowOrderType($agentCashFlow, $ot2);

        // Act
        $response = $this->act();

        // Assert — 同一個 acf 下兩筆 acfot → 兩行
        $this->assertCount(2, $response->json('data.agent_cash_flow_list'));
    }

    /**
     * currency 欄位應從 OrderType 關聯的 Currency 取得代碼，
     * 而非從 AgentCashFlow 直接取得。
     */
    public function test_currency_code_is_populated_from_order_type(): void
    {
        // Arrange
        $currency = $this->createCurrency('USD');
        $cashFlow = $this->createCashFlow(type: 1);
        $agentCashFlow = $this->createAgentCashFlowForGroup($cashFlow);
        $ot = $this->createOrderType($cashFlow, overrides: ['currency_id' => $currency->id]);
        $this->createAgentCashFlowOrderType($agentCashFlow, $ot);

        // Act
        $response = $this->act();

        // Assert
        $this->assertSame('USD', $response->json('data.agent_cash_flow_list.0.currency'));
    }

    /**
     * 回應中的 cash_flow_list 應只包含 status=1 的 CashFlow，
     * status=0 的停用記錄不應出現。
     */
    public function test_cash_flow_list_contains_only_enabled_cash_flows(): void
    {
        // Arrange
        $enabled = $this->createCashFlow(type: 1, status: 1);
        $disabled = $this->createCashFlow(type: 1, status: 0);

        // Act
        $response = $this->act();

        // Assert
        $cashFlowIds = collect($response->json('data.cash_flow_list'))->pluck('id')->toArray();
        $this->assertContains($enabled->id, $cashFlowIds);
        $this->assertNotContains($disabled->id, $cashFlowIds);
    }

    /**
     * 屬於其他 agent_group_id 的渠道不應出現，
     * 確保查詢結果嚴格隔離至當前 agent 的 cash_flow_group_id。
     */
    public function test_returns_empty_list_when_no_channels_for_group(): void
    {
        // Arrange — 建立屬於其他 group（id+9999）的渠道，不影響本 agent
        $cashFlow = $this->createCashFlow(type: 1);
        AgentCashFlow::create([
            'agent_group_id' => $this->agent->cash_flow_group_id + 9999,
            'cash_flow_id' => $cashFlow->id,
            'cash_flow_info_id' => 0,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        // Act
        $response = $this->act();

        // Assert — 本 agent 的 group 無資料
        $this->assertCount(0, $response->json('data.agent_cash_flow_list'));
    }

    /**
     * 未帶 Authorization header 時，AuthAgent middleware 應拒絕請求，
     * 回傳自定義 HTTP 319（HTTP_REDIRECT_LOGOUT）。
     */
    public function test_requires_authentication(): void
    {
        // Arrange — 移除 Authorization header
        unset($this->headers['Authorization']);

        // Act
        $response = $this->postJson($this->apiPath, [], $this->headers);

        // Assert — AuthAgent middleware 回傳自定義 319 (HTTP_REDIRECT_LOGOUT)
        $response->assertStatus(319);
    }
}
