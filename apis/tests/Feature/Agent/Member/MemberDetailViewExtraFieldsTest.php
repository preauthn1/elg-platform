<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Member;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentCustomizedSetting;
use App\Models\Member;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 會員詳情抽屜補的四個欄位（target/tickets#4438，帳號管理「功能」下拉搬進抽屜要用）。
 *
 * 必須成立：
 * 1. last_login_ip：整數轉回點分字串，沒登入過回空字串。
 * 2. agent_id：上級代理 id（「網域」按鈕用）。
 * 3. type／shareholder_visible：會員類型與上級代理的分桶開關（「帳號類型」彈窗用），開關沒設回 0。
 *
 * php artisan test tests/Feature/Agent/Member/MemberDetailViewExtraFieldsTest.php
 */
class MemberDetailViewExtraFieldsTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, [
            'username' => 'mdx' . bin2hex(random_bytes(5)),
            'type' => 0,
            'last_login_ip' => ip2long('0.0.0.0'),
        ]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]'agent_id' => $this->agent->id, 'token' => $token, 'expire_date' => '2099-01-01 00:00:00', 'admin' => 1]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_returns_last_login_ip_agent_id_type_and_shareholder_visible(): void
    {
        $data = $this->detailView()->assertOk()->assertJsonPath('code', 200)->json('data');

        $this->assertSame('0.0.0.0', $data['last_login_ip']);
        $this->assertSame($this->agent->id, $data['agent_id']);
        $this->assertSame(0, $data['type']);
        $this->assertSame(0, $data['shareholder_visible'], '上級代理沒設分桶開關回 0');
    }

    public function test_shareholder_visible_follows_parent_agent_setting(): void
    {
        AgentCustomizedSetting::query()->create([
            'agent_group_id' => $this->agent->customized_setting_group_id,
            'setting_key' => AgentCustomizedSettingKeyEnum::POINT_BARREL_SETTING,
            'sub_category' => '',
            'value' => '1',
            'memo' => '',
        ]);

        $this->assertSame(1, $this->detailView()->assertOk()->json('data.shareholder_visible'));
    }

    public function test_member_never_logged_in_returns_empty_last_login_ip(): void
    {
        $this->member->forceFill(['last_login_ip' => 0])->save();

        $this->assertSame('', $this->detailView()->assertOk()->json('data.last_login_ip'));
    }

    private function detailView(): TestResponse
    {
        return $this->postJson('agent/api/member/detail/view', ['member_id' => $this->member->id], $this->headers);
    }
}
