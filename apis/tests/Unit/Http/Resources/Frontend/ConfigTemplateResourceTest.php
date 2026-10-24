<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources\Frontend;

use App\Enums\Agent\AgentModeEnum;
use App\Http\Resources\Frontend\ConfigTemplateResource;
use App\Models\Agent;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

/**
 * ConfigTemplateResource 測試（客服聊天會員身分驗證修正）
 *
 * /api/template 免登入也會被呼叫（一般頁面載入的 fetchTemplate()），原本無條件回傳
 * 代理共用的 CS_API_USER／CS_API_KEY，是方案文件點出的外洩管道之一。但同一支端點
 * 也是 QR code 登入流程（帶 Bearer token）取得聊天憑證的唯一管道
 * （WEB_Frontend：src/store/modules/List/index.js 的 QRcodeOpen 分支），拿掉
 * 全部回傳會讓那段流程的 CS_API_USER／CS_API_KEY 變成 JS 的 undefined
 * （sessionStorage 會存成字面字串 "undefined"）。
 *
 * 守備：有登入會員（$csChatCredentials 非 null）才回真實值，匿名呼叫一律回空字串
 * （不回代理共用值，也不讓欄位整個消失造成前端讀到 undefined）。
 *
 * php artisan test tests/Unit/Http/Resources/Frontend/ConfigTemplateResourceTest.php
 */
class ConfigTemplateResourceTest extends TestCase
{
    private function fakeAgentDomain(int $isUnlimitVisible = 0): stdClass
    {
        $domain = new stdClass;
        $domain->template = (object) ['id' => 1, 'name' => 'default'];
        $domain->agent_id = 1;
        $domain->mobile_templates = 'default';
        $domain->pc_templates = 'default';
        $domain->logo_pc = '';
        $domain->logo_mobile = '';
        $domain->icon = '';
        $domain->add_home_image = '';
        $domain->title = 'test';
        $domain->domain = 'test.local';
        $domain->code = 'CODE1';
        $domain->need_login = 0;
        $domain->has_show_media = 0;
        $domain->event_jackpot_title = '';
        $domain->customized_setting = ['is_unlimit_visible' => $isUnlimitVisible];
        $domain->member_field_settings = [];
        $domain->missing_required_fields = [];
        $domain->new4d2026_maintenance = null;
        $domain->haolong_maintenance = null;

        return $domain;
    }

    private function fakeAgent(int $agentMode = 1): Agent
    {
        $agent = new Agent;
        $agent->id = 1;
        $agent->frontend_register = 1;
        $agent->has_chat = 1;
        $agent->has_pc = 1;
        $agent->has_mobile = 1;
        $agent->has_online_payment = 1;
        $agent->has_apk = 0;
        $agent->has_ipa = 0;
        $agent->transaction_all = 0;
        $agent->email_validate = 0;
        $agent->mobile_validate = 0;
        $agent->agent_mode = $agentMode;
        $agent->customized_setting_group_id = 1;

        return $agent;
    }

    public function test_anonymous_call_returns_empty_cs_credentials_not_undefined(): void
    {
        $array = (new ConfigTemplateResource($this->fakeAgentDomain(), $this->fakeAgent(), null))
            ->toArray(Request::create('/'));

        $this->assertArrayHasKey('CS_API_USER', $array, '欄位必須存在，不可整個消失（消失會讓前端讀到 JS undefined）');
        $this->assertArrayHasKey('CS_API_KEY', $array);
        $this->assertSame('', $array['CS_API_USER']);
        $this->assertSame('', $array['CS_API_KEY']);
    }

    public function test_logged_in_call_returns_issued_member_chat_credentials(): void
    {
        $array = (new ConfigTemplateResource(
            $this->fakeAgentDomain(),
            $this->fakeAgent(),
            ['api_user' => 'agent-cs-user', 'api_key' => 'member-only-key']
        ))->toArray(Request::create('/'));

        $this->assertSame('agent-cs-user', $array['CS_API_USER']);
        $this->assertSame('member-only-key', $array['CS_API_KEY']);
    }

    /**
     * op_ticket#3518：全民代理入口＝代理模式是全民代理（agents.agent_mode = 1）
     * 且代理組別設置「是否顯示全民代理」(4052) 也開啟，兩者缺一即關閉。
     *
     * 回歸重點：原寫法 `agent_mode ?? is_unlimit_visible` 讓組別設置永遠失效
     * （agents.agent_mode 是 NOT NULL DEFAULT 0），後台開關等於沒作用。
     */
    public static function agentLayoutCases(): array
    {
        return [
            '兩者皆開才顯示' => [AgentModeEnum::UNLIMIT->value, 1, 1],
            '組別設置關閉即關閉' => [AgentModeEnum::UNLIMIT->value, 0, 0],
            '代理模式非全民代理即關閉' => [AgentModeEnum::NONE->value, 1, 0],
            '三層代理不得被當成開啟' => [AgentModeEnum::THREE_HIERARCHY_MEMBER_ONLY->value, 1, 0],
        ];
    }

    #[DataProvider('agentLayoutCases')]
    public function test_has_agent_layout_requires_both_agent_mode_and_group_setting(
        int $agentMode,
        int $isUnlimitVisible,
        int $expected,
    ): void {
        $array = (new ConfigTemplateResource(
            $this->fakeAgentDomain(isUnlimitVisible: $isUnlimitVisible),
            $this->fakeAgent(agentMode: $agentMode),
            null
        ))->toArray(Request::create('/'));

        $this->assertSame($expected, $array['has_agent_layout']);
    }
}
