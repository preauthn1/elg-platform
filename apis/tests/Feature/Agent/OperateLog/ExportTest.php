<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\OperateLog;

use App\Enums\Agent\AgentOperateReferenceTypeEnum;
use App\Enums\Agent\AgentOperateTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentMenu;
use App\Models\AgentOperateLog;
use App\Services\Agent\AgentOperateLogService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Agent/OperateLog/ExportTest.php
 */
class ExportTest extends TestCase
{
    private string $apiPath = 'agent/api/operate-logs/export';

    private Agent $agent;

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

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function act(array $overrides = []): TestResponse
    {
        return $this->postJson($this->apiPath, array_merge([
            'action' => 'excel_event_v2_join',
            'menu_name' => '活動V2 參加紀錄',
            'record_count' => 128,
            'conditions' => ['start_time' => '2026-07-01 00:00', 'status' => 'success'],
        ], $overrides), $this->headers);
    }

    private function latestLog(): ?AgentOperateLog
    {
        return AgentOperateLog::where('agent_id', $this->agent->id)->latest('id')->first();
    }

    private function latestDebugInfo(): array
    {
        return json_decode($this->latestLog()->debug_info, true);
    }

    private function createMenu(string $url, string $code, int $status = 1): AgentMenu
    {
        return AgentMenu::create([
            'parent' => 0,
            'code' => $code,
            'name' => '匯出測試選單',
            'url' => $url,
            'symbol' => '',
            'action' => '',
            'sort' => 0,
            'status' => $status,
            'admin' => 0,
        ]);
    }

    // -------------------------------------------------------
    // Tests
    // -------------------------------------------------------

    /**
     * 正常寫入：欄位對應與 debug_info 結構
     */
    public function test_writes_export_log_with_expected_columns(): void
    {
        // Act
        $response = $this->act();

        // Assert
        $response->assertStatus(200)->assertJson(['code' => 200]);

        $log = $this->latestLog();
        $this->assertNotNull($log);
        $this->assertSame(AgentOperateTypeEnum::EXPORT_DATA->value, $log->type);
        // 沒帶 menu_url 就對不到選單，reference_id 固定 '0'，避免撞到 agents.id／members.id
        $this->assertSame('0', $log->reference_id);

        // 沒有指向任何實體，reference_type 不寫入、維持資料表預設的空字串（UNKNOWN）
        $this->assertSame(
            AgentOperateReferenceTypeEnum::UNKNOWN->value,
            $log->reference_type,
        );
        $this->assertSame($this->agent->id, $log->agent_id);
        $this->assertNotSame('', $log->ip);

        $debug = $this->latestDebugInfo();
        $this->assertSame('excel_event_v2_join', $debug['action']);
        $this->assertSame('活動V2 參加紀錄', $debug['menu_name']);
        $this->assertSame(128, $debug['record_count']);
        $this->assertSame('success', $debug['conditions']['status']);
    }

    /**
     * 帶 menu_url 時，reference_id 指向 agent_menus.id、reference_type 標成 agent_menu
     */
    public function test_resolves_menu_url_to_agent_menu_reference(): void
    {
        // Arrange
        $menu = $this->createMenu('zz_export_audit/join', 'test_export_menu');

        // Act
        $response = $this->act(['menu_url' => 'zz_export_audit/join']);

        // Assert
        $response->assertStatus(200)->assertJson(['code' => 200]);

        $log = $this->latestLog();
        $this->assertSame((string) $menu->id, $log->reference_id);
        $this->assertSame(
            AgentOperateReferenceTypeEnum::AGENT_MENU->value,
            $log->reference_type,
        );

        // 選單靠 reference_id 指出，menu_url 本身不該落進查詢條件
        $this->assertArrayNotHasKey('menu_url', $this->latestDebugInfo()['conditions']);
    }

    /**
     * url 沒有 unique 約束：同一頁面有多筆選單時只取 status=1 那筆
     * （左側選單只渲染 status=1，使用者點進去的就是它），不能只看 id 大小
     */
    public function test_prefers_enabled_menu_when_url_is_duplicated(): void
    {
        // Arrange —— 故意讓停用的那筆 id 較小，證明篩選不是靠 id
        $disabled = $this->createMenu('zz_export_audit/dup', 'zz_dup_disabled', 0);
        $enabled = $this->createMenu('zz_export_audit/dup', 'zz_dup_enabled', 1);
        $this->assertLessThan($enabled->id, $disabled->id);

        // Act
        $response = $this->act(['menu_url' => 'zz_export_audit/dup']);

        // Assert
        $response->assertStatus(200)->assertJson(['code' => 200]);
        $this->assertSame((string) $enabled->id, $this->latestLog()->reference_id);
    }

    /**
     * 停用的選單不算對得到：紀錄只指向啟用中的選單，
     * 此時退回 reference_id '0'，選單名稱由 debug_info.menu_name 顯示
     */
    public function test_ignores_disabled_menu(): void
    {
        // Arrange
        $this->createMenu('zz_export_audit/off', 'zz_off_only', 0);

        // Act
        $response = $this->act(['menu_url' => 'zz_export_audit/off']);

        // Assert
        $response->assertStatus(200)->assertJson(['code' => 200]);

        $log = $this->latestLog();
        $this->assertSame('0', $log->reference_id);
        $this->assertSame(
            AgentOperateReferenceTypeEnum::UNKNOWN->value,
            $log->reference_type,
        );
        $this->assertSame('活動V2 參加紀錄', $this->latestDebugInfo()['menu_name']);
    }

    /**
     * menu_url 對不到任何選單時不得中斷匯出：referer 缺失、頁面不在選單裡都屬預期情況
     */
    public function test_falls_back_when_menu_url_matches_no_menu(): void
    {
        // Act
        $response = $this->act(['menu_url' => 'no_such/page']);

        // Assert
        $response->assertStatus(200)->assertJson(['code' => 200]);

        $log = $this->latestLog();
        $this->assertSame('0', $log->reference_id);
        $this->assertSame(
            AgentOperateReferenceTypeEnum::UNKNOWN->value,
            $log->reference_type,
        );
        // 讀取端要靠 menu_name 退回顯示，這裡必須還在
        $this->assertSame('活動V2 參加紀錄', $this->latestDebugInfo()['menu_name']);
    }

    /**
     * menu_url 格式非法應被擋下（後端只做等值查詢，但不接受非路徑字元）
     */
    public function test_rejects_invalid_menu_url_format(): void
    {
        $this->act(['menu_url' => 'member/index.php?a=1'])->assertStatus(400);
        $this->act(['menu_url' => str_repeat('a', 101)])->assertStatus(400);

        $this->assertNull($this->latestLog());
    }

    /**
     * 查無資料仍須留下稽核紀錄，record_count = 0 必須被接受
     */
    public function test_allows_zero_record_count(): void
    {
        // Act
        $response = $this->act(['record_count' => 0]);

        // Assert
        $response->assertStatus(200)->assertJson(['code' => 200]);
        $this->assertSame(0, $this->latestDebugInfo()['record_count']);
    }

    /**
     * 缺 record_count 應被擋下，且不得留下半筆紀錄
     */
    public function test_rejects_missing_record_count(): void
    {
        // Act
        $response = $this->postJson($this->apiPath, [
            'action' => 'excel_orders',
        ], $this->headers);

        // Assert
        $response->assertStatus(400);
        $this->assertNull($this->latestLog());
    }

    /**
     * action 只收 [A-Za-z0-9_]，且長度在 varchar(50) 內
     */
    public function test_rejects_invalid_action_format(): void
    {
        $this->act(['action' => 'Excel Orders'])->assertStatus(400);
        $this->act(['action' => str_repeat('a', 51)])->assertStatus(400);

        $this->assertNull($this->latestLog());
    }

    /**
     * 駝峰式 action 必須放行。
     *
     * WEB_AgentBackend 的 transactionList／transactionListNew（report/sr02、sr02t、
     * sr02y、ssr02 四頁）是駝峰式。呼叫端 fail-closed，這裡擋下去不是少一筆稽核，
     * 而是那四頁的匯出整個不能用 —— dev 站實測踩過一次。
     */
    public function test_accepts_camel_case_action(): void
    {
        // Act
        $response = $this->act(['action' => 'transactionListNew']);

        // Assert
        $response->assertStatus(200);
        $this->assertSame('transactionListNew', $this->latestDebugInfo()['action']);
    }

    /**
     * 後端不信任前端過濾：分頁與敏感欄位必須在伺服器端剔除，
     * 但 sign_up_time 這類含敏感字根的正常欄位不可被誤殺
     */
    public function test_strips_sensitive_and_paging_conditions(): void
    {
        // Act
        $response = $this->act(['conditions' => [
            'start_time' => '2026-07-01 00:00',
            'sign_up_time' => '2026-01-01',
            'page' => '1',
            'per_page' => '5000',
            'payment_password' => 'p@ssw0rd',
            'token' => 'abc',
            'bank_account_no' => '1234567890',
            'empty_field' => '',
        ]]);

        // Assert
        $response->assertStatus(200);

        $conditions = $this->latestDebugInfo()['conditions'];

        $this->assertArrayHasKey('start_time', $conditions);
        $this->assertArrayHasKey('sign_up_time', $conditions);

        foreach (['page', 'per_page', 'payment_password', 'token', 'bank_account_no', 'empty_field'] as $key) {
            $this->assertArrayNotHasKey($key, $conditions);
        }
    }

    /**
     * 超大 conditions 截斷後，debug_info 仍必須是可解析的合法 JSON
     */
    public function test_truncates_oversized_conditions(): void
    {
        // Arrange
        $service = app(AgentOperateLogService::class);
        $huge = [];
        for ($i = 0; $i < 60; $i++) {
            $huge['field_' . $i] = str_repeat('值', 200);
        }

        // Act
        $json = $service->buildDebugInfo('excel_orders', '支付訂單管理', $huge, 5000);

        // Assert
        $this->assertLessThanOrEqual(8000, strlen($json));

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['conditions_truncated']);
        $this->assertSame(5000, $decoded['record_count']);
        $this->assertSame('支付訂單管理', $decoded['menu_name']);
    }

    /**
     * 未帶 token 時 AuthAgent 回 319（HTTP_REDIRECT_LOGOUT）
     */
    public function test_requires_authentication(): void
    {
        // Arrange
        unset($this->headers['Authorization']);

        // Act
        $response = $this->postJson($this->apiPath, [], $this->headers);

        // Assert
        $response->assertStatus(319);
    }
}
