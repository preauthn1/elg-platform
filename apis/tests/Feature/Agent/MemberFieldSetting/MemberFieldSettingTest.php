<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\MemberFieldSetting;

use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\MemberFieldPageTypeEnum;
use App\Enums\MemberRegisterLimitFieldEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentGroupPermission;
use App\Models\AgentMemberFieldSetting;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\AgentRegisterLimit;
use App\Services\MemberFieldSettingService;
use App\Services\MemberRegisterLimitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 代理後台 › 會員欄位設置接口（target/tickets#4459，自 API_Agent 搬入）
 *
 * php artisan test tests/Feature/Agent/MemberFieldSetting/MemberFieldSettingTest.php
 */
class MemberFieldSettingTest extends TestCase
{
    /** 組織幣別刻意跟設定列的幣別（MYR）不同，驗證讀寫都只看組別、不比對幣別 */
    private const int ORG_CURRENCY_ID = 3;

    private const int MYR = 1;

    private Agent $agent;

    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);

        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'org-' . bin2hex(random_bytes(4)),
            'currency_id' => self::ORG_CURRENCY_ID,
            'memo' => 'member field setting test',
        ]);
        $this->groupId = (int) DB::table('agent_groups')->insertGetId([
            'agent_group_type' => AgentGroupTypeEnum::MEMBER_FIELD_SETTING->value,
            'organization_id' => $orgId,
            'name' => 'member field setting test',
            'memo' => '',
            'is_default' => 0,
        ]);

        $this->agent = $this->createAgentAndDomain([
            'organization_id' => $orgId,
            'member_field_setting_group_id' => $this->groupId,
            'sms_gateway_group_id' => 99,
        ]);
        $this->grantAgentGroupMenu($this->agent);
        AgentGroupPermission::create(['agent_id' => $this->agent->id, 'agent_group_id' => $this->groupId, 'status' => 1, 'admin' => 0]);

        $this->seedFields();

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

    private function act(string $action, array $payload = []): TestResponse
    {
        return $this->postJson('agent/api/member-field-setting/' . $action, $payload, $this->headers);
    }

    private function grantAgentGroupMenu(Agent $agent): void
    {
        $menu = AgentMenu::query()->where('code', 'agent_group')->first() ?? AgentMenu::create([
            'code' => 'agent_group', 'name' => '代理組別管理', 'parent' => 0, 'url' => 'agent/group_list',
            'action' => '', 'sort' => 0, 'status' => 1, 'admin' => 0,
        ]);
        AgentPermission::create(['agent_id' => $agent->id, 'agent_menu_id' => $menu->id, 'action' => '', 'admin' => 0]);
    }

    /** 註冊頁玩家帳號、手機號碼與登入頁兩列（玩家帳號開、手機號碼開） */
    private function seedFields(): void
    {
        $rows = [
            [MemberFieldPageTypeEnum::REGISTER, 'username', 1, 1, 1, ['min_length' => 4, 'max_length' => 10]],
            [MemberFieldPageTypeEnum::REGISTER, 'mobile', 6, 0, 0, ['min_length' => 7, 'max_length' => 15]],
            [MemberFieldPageTypeEnum::LOGIN, 'username', 1, 1, 1, null],
            [MemberFieldPageTypeEnum::LOGIN, 'mobile', 2, 0, 1, null],
        ];
        foreach ($rows as [$page, $key, $sort, $required, $status, $cfg]) {
            AgentMemberFieldSetting::create([
                'agent_group_id' => $this->groupId,
                'currency_id' => self::MYR,
                'page_type' => $page->value,
                'field_key' => $key,
                'field_group' => 1,
                'sort' => $sort,
                'is_required' => $required,
                'status' => $status,
                'extra_config' => $cfg,
                'admin' => 0,
            ]);
        }
    }

    /** 註冊頁「關閉玩家帳號」的合法 payload */
    private function usernameOffFields(string $prefix = 'ToGo'): array
    {
        return [
            ['field_key' => 'username', 'field_group' => 1, 'sort' => 1, 'is_required' => 0, 'status' => 0, 'extra_config' => ['username_prefix' => $prefix]],
            ['field_key' => 'mobile', 'field_group' => 1, 'sort' => 6, 'is_required' => 1, 'status' => 1, 'extra_config' => ['min_length' => 7, 'max_length' => 15]],
        ];
    }

    private function loginStatus(string $fieldKey): int
    {
        return (int) AgentMemberFieldSetting::query()
            ->where('agent_group_id', $this->groupId)
            ->where('page_type', MemberFieldPageTypeEnum::LOGIN->value)
            ->where('field_key', $fieldKey)
            ->value('status');
    }

    // -------------------------------------------------------
    // view
    // -------------------------------------------------------

    /**
     * 只看組別：組織幣別跟設定列的幣別不同，照樣回組別的列
     */
    public function test_get_returns_group_rows_regardless_of_currency(): void
    {
        // Act
        $response = $this->act('view', ['group_id' => $this->groupId]);

        // Assert
        $response->assertStatus(200)->assertJsonPath('code', 200);
        $this->assertCount(2, $response->json('data.register'));
        $this->assertCount(2, $response->json('data.login'));
    }

    /**
     * 非平台端、沒有該組別的組別管理權 → 拒絕
     */
    public function test_rejects_agent_without_group_permission(): void
    {
        AgentGroupPermission::query()->where('agent_id', $this->agent->id)->update(['status' => 0]);

        $this->act('view', ['group_id' => $this->groupId])
            ->assertStatus(400)
            ->assertJsonPath('message', __('error_message.9003'));
    }

    // -------------------------------------------------------
    // fields/save
    // -------------------------------------------------------

    /**
     * 關閉玩家帳號：前綴轉小寫存入、登入改成只用手機號碼、清快取後註冊端立刻讀到新設定
     */
    public function test_username_off_saves_prefix_syncs_login_and_clears_cache(): void
    {
        // Arrange：先讓註冊端把舊設定讀進快取
        $service = $this->app->make(MemberFieldSettingService::class);
        $before = collect($service->getEnabledFields($this->agent, MemberFieldPageTypeEnum::REGISTER))->pluck('field_key')->all();
        $this->assertContains('username', $before);

        // Act
        $this->act('fields/save', ['group_id' => $this->groupId, 'page_type' => 1, 'fields' => $this->usernameOffFields()])
            ->assertStatus(200)->assertJsonPath('code', 200);

        // Assert
        $username = AgentMemberFieldSetting::query()
            ->where('agent_group_id', $this->groupId)
            ->where('page_type', 1)->where('field_key', 'username')->first();
        $this->assertSame(0, (int) $username->status);
        $this->assertSame('togo', $username->extra_config['username_prefix']);
        $this->assertSame($this->agent->id, (int) $username->admin);

        $this->assertSame(0, $this->loginStatus('username'));
        $this->assertSame(1, $this->loginStatus('mobile'));

        $after = collect($service->getEnabledFields($this->agent, MemberFieldPageTypeEnum::REGISTER))->pluck('field_key')->all();
        $this->assertNotContains('username', $after);
        $this->assertContains('mobile', $after);
    }

    /**
     * 關閉玩家帳號的擋板：前綴不合格、組別底下有代理沒設簡訊組別，都不寫入
     */
    public function test_username_off_rejects_invalid_prefix_and_missing_sms_group(): void
    {
        // 前綴沒有英文字母
        $this->act('fields/save', ['group_id' => $this->groupId, 'page_type' => 1, 'fields' => $this->usernameOffFields('1234')])
            ->assertStatus(400)
            ->assertJsonPath('message', __('error_message.member_field_setting.username_prefix_invalid'));

        // 組別底下另一個代理沒設簡訊組別
        $this->domain = Str::random(12) . '.test.local';
        $this->createAgentAndDomain([
            'organization_id' => $this->agent->organization_id,
            'member_field_setting_group_id' => $this->groupId,
            'sms_gateway_group_id' => 0,
        ]);
        $this->act('fields/save', ['group_id' => $this->groupId, 'page_type' => 1, 'fields' => $this->usernameOffFields()])
            ->assertStatus(400)
            ->assertJsonPath('message', __('error_message.member_field_setting.sms_gateway_group_required'));

        $this->assertSame(1, $this->loginStatus('username'));
    }

    /**
     * 註冊的玩家帳號關閉後，登入頁只接受「只用手機號碼」
     */
    public function test_login_page_only_accepts_mobile_when_username_off(): void
    {
        // Arrange
        $this->act('fields/save', ['group_id' => $this->groupId, 'page_type' => 1, 'fields' => $this->usernameOffFields()])
            ->assertStatus(200);

        // Act：試圖把登入改回「玩家帳號＋手機號碼」
        $response = $this->act('fields/save', ['group_id' => $this->groupId, 'page_type' => 2, 'fields' => [
            ['field_key' => 'username', 'sort' => 1, 'is_required' => 1, 'status' => 1],
            ['field_key' => 'mobile', 'sort' => 2, 'is_required' => 0, 'status' => 1],
        ]]);

        // Assert
        $response->assertStatus(400)->assertJsonPath('message', __('error_message.member_field_setting.login_mobile_only'));
        $this->assertSame(0, $this->loginStatus('username'));
    }

    // -------------------------------------------------------
    // has-members
    // -------------------------------------------------------

    /**
     * 組別底下代理有會員才回 true
     */
    public function test_has_members_reflects_members_under_group_agents(): void
    {
        $this->act('has-members', ['group_id' => $this->groupId])
            ->assertStatus(200)->assertJsonPath('data.has_members', false);

        $this->createMember($this->agent);

        $this->act('has-members', ['group_id' => $this->groupId])
            ->assertStatus(200)->assertJsonPath('data.has_members', true);
    }

    // -------------------------------------------------------
    // register-limits/save
    // -------------------------------------------------------

    /**
     * 註冊限制新建列寫 MYR，組織幣別不同也讀得到；清快取後註冊端立刻讀到
     */
    public function test_limits_update_creates_myr_row_and_clears_cache(): void
    {
        // Arrange：先讓註冊端把「沒有設定」讀進快取
        $limitService = $this->app->make(MemberRegisterLimitService::class);
        $this->assertNull($limitService->bindingLimit($this->agent, MemberRegisterLimitFieldEnum::PHONE, '60123'));

        // Act
        $this->act('register-limits/save', ['group_id' => $this->groupId, 'limits' => [
            ['field_key' => 'phone', 'platform_enabled' => 1, 'platform_max_limit' => 2, 'custom_enabled' => 1, 'custom_values' => [['value' => '60123', 'max_limit' => 3]]],
        ]])->assertStatus(200)->assertJsonPath('code', 200);

        // Assert
        $limit = AgentRegisterLimit::query()->where('agent_group_id', $this->groupId)->where('field_key', 'phone')->first();
        $this->assertSame(self::MYR, (int) $limit->currency_id);
        $this->assertSame(1, $limit->values()->count());
        $this->assertSame(3, $limitService->bindingLimit($this->agent, MemberRegisterLimitFieldEnum::PHONE, '60123'));
    }
}
