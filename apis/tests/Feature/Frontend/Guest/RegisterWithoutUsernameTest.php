<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Guest;

use App\Enums\MemberFieldPageTypeEnum;
use App\Exceptions\ApiException;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Http\Requests\Agent\Report\Sr12Request;
use App\Http\Requests\External\Bot\GetBalanceByUsernameRequest;
use App\Models\Agent;
use App\Models\AgentMemberFieldSetting;
use App\Models\Member;
use App\Models\ValidateToken;
use App\Services\EventService;
use App\Services\Frontend\EventInvite\EventInviteService;
use App\Services\MemberCodeService;
use App\Services\MemberFieldSettingService;
use App\Services\MemberService;
use App\Services\RegisterService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * 關閉模式（組別的註冊欄位關掉玩家帳號）：會員只用手機號碼註冊與登入，帳號由系統以前綴產生，一個手機號碼只能有一個帳號
 * （target/ticket_tasks#1629，規格見同單 note 153271）。TG 建帳見 tests/Feature/Frontend/TgBot/WebAppLoginTest.php。
 *
 * php artisan test tests/Feature/Frontend/Guest/RegisterWithoutUsernameTest.php
 */
class RegisterWithoutUsernameTest extends TestCase
{
    private const string PREFIX = 'togo';

    private const string PASSWORD = [REDACTED]';

    private int $orgId;

    private int $groupId;

    private Agent $agent;

    private string $mobile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);
        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->mobile = '6011' . str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);

        $this->orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'org-' . bin2hex(random_bytes(4)),
            'currency_id' => 1,
            'memo' => 'register without username test',
        ]);
        $this->groupId = (int) DB::table('agent_groups')->insertGetId([
            'agent_group_type' => 6,
            'organization_id' => $this->orgId,
            'name' => 'register without username test',
            'memo' => '',
            'is_default' => 0,
        ]);

        // 註冊頁：玩家帳號關閉（帶前綴）、手機號碼強制開啟必填、密碼必填
        $this->seedRegisterField('username', status: 0, required: 0, extraConfig: ['username_prefix' => self::PREFIX]);
        $this->seedRegisterField('mobile', status: 1, required: 1, extraConfig: null);
        $this->seedRegisterField('password', status: 1, required: 1, extraConfig: null);

        $this->agent = $this->createAgentAndDomain([
            'organization_id' => $this->orgId,
            'binding_scope_id' => 1,
            'member_field_setting_group_id' => $this->groupId,
        ]);

        $registerServiceMock = Mockery::mock(RegisterService::class, [
            $this->app->make(EventService::class),
            $this->app->make(MemberService::class),
            $this->app->make(MemberCodeService::class),
            $this->app->make(EventInviteService::class),
        ])->makePartial()->shouldAllowMockingProtectedMethods();
        $registerServiceMock->shouldReceive('triggerCsSyncData')->andReturnNull();
        $registerServiceMock->shouldReceive('runRegisterEventStoredProcedures')->andReturnNull();
        $this->app->instance(RegisterService::class, $registerServiceMock);
    }

    private function seedRegisterField(string $key, int $status, int $required, ?array $extraConfig): void
    {
        AgentMemberFieldSetting::create([
            'agent_group_id' => $this->groupId,
            'currency_id' => 1,
            'page_type' => MemberFieldPageTypeEnum::REGISTER->value,
            'field_key' => $key,
            'field_group' => 1,
            'sort' => 1,
            'is_required' => $required,
            'status' => $status,
            'extra_config' => $extraConfig,
            'admin' => 0,
        ]);
    }

    private function mobileToken(): string
    {
        $token = [REDACTED] random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        ValidateToken:[REDACTED]
            'member_id' => 0,
            'type_id' => '121',
            'info' => $this->mobile,
            'info_2' => '',
            'token' => $token,
            'expire_date' => now()->addMinutes(5),
            'status' => 0,
            'times' => 1,
            'admin' => 0,
        ]);

        return $token;
    }

    private function registerV2(array $extra = []): TestResponse
    {
        return $this->requestApi('frontend/api/guest/register-v2', [
            'domain' => $this->domain,
            'mobile' => $this->mobile,
            'mobile_token' => $this->mobileToken(),
            'password' => self::PASSWORD,
        ] + $extra);
    }

    private function createVerifiedMemberWithMobile(): Member
    {
        return $this->createMember($this->agent, [
            'username' => 'p' . bin2hex(random_bytes(5)),
            'mobile' => $this->mobile,
            'mobile_validate' => 1,
        ]);
    }

    public function test_closed_mode_detection_needs_enabled_fields_without_username(): void
    {
        $service = $this->app->make(MemberFieldSettingService::class);
        $this->assertTrue($service->isUsernameAutoGenerated($this->agent));

        // 組別完全沒設定（正常走 v1 的站）不算關閉模式
        $originalDomain = $this->domain;
        $this->domain = 'plain-' . bin2hex(random_bytes(4)) . '.test.local';
        $plainAgent = $this->createAgentAndDomain(['organization_id' => $this->orgId]);
        $this->domain = $originalDomain;
        $this->assertFalse($service->isUsernameAutoGenerated($plainAgent));
        $this->assertNull($service->usernamePrefix($plainAgent));
    }

    public function test_register_v2_generates_prefixed_username_and_ignores_input(): void
    {
        // 純數字帳號在非關閉模式會被帳號規則擋下；關閉模式整個忽略，照常產生帳號
        $response = $this->registerV2(['username' => '60123456789']);

        $response->assertJson(['code' => 200]);
        $member = Member::where('mobile', $this->mobile)->sole();
        $this->assertMatchesRegularExpression('/^togo-[0-9a-f]{13}$/', $member->username);
        $this->assertSame(1, (int) $member->mobile_validate);
    }

    public function test_register_v2_allows_one_account_per_mobile_regardless_of_limit_setting(): void
    {
        // 註冊/綁定限制把手機上限設成 5，關閉模式下仍只能一號一帳
        DB::table('agent_register_limits')->insert([
            'agent_group_id' => $this->groupId,
            'currency_id' => 1,
            'field_key' => 'phone',
            'platform_enabled' => 1,
            'platform_max_limit' => 5,
            'custom_enabled' => 0,
            'admin' => 0,
        ]);
        $this->createVerifiedMemberWithMobile();

        $response = $this->registerV2();

        $this->assertSame(1090, $response->json('code'));
        $this->assertSame(1, Member::where('mobile', $this->mobile)->count());
    }

    public function test_generate_username_retries_on_collision_and_gives_up_after_five(): void
    {
        $taken = self::PREFIX . '-' . str_repeat('a', 13);
        $this->createMember($this->agent, ['username' => $taken]);

        /** @var RegisterService&MockInterface $service */
        $service = $this->app->make(RegisterService::class);
        $service->shouldReceive('usernameCandidate')->times(2)->andReturn($taken, self::PREFIX . '-' . str_repeat('b', 13));
        $this->assertSame(self::PREFIX . '-' . str_repeat('b', 13), $service->generateUsername(self::PREFIX));

        $service->shouldReceive('usernameCandidate')->times(5)->andReturn($taken);
        $this->expectException(ApiException::class);
        $service->generateUsername(self::PREFIX);
    }

    public function test_register_v1_is_rejected_in_closed_mode(): void
    {
        $response = $this->requestApi('frontend/api/guest/register', [
            'domain' => $this->domain,
            'username' => 'manual123',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);

        $this->assertSame(9999, $response->json('code'));
        $this->assertFalse(Member::where('username', 'manual123')->exists());
    }

    public function test_reset_mobile_applies_one_account_per_mobile(): void
    {
        $this->createVerifiedMemberWithMobile();
        $this->createMemberAndToken($this->agent, ['username' => 'r' . bin2hex(random_bytes(5))]);

        $response = $this->requestApi('frontend/api/member/reset-mobile', [
            'token' => '123456',
            'new_mobile' => $this->mobile,
        ]);

        $response->assertJson(['message' => __('error_message.1090')]);
    }

    public function test_login_messages_use_mobile_wording_in_closed_mode(): void
    {
        $wrong = $this->requestApi('frontend/api/guest/login', [
            'username' => $this->mobile,
            'password' => 'wrong-password',
            'domain' => $this->domain,
        ]);
        $wrong->assertJson(['message' => __('error_message.9005_mobile')]);

        $empty = $this->requestApi('frontend/api/guest/login', [
            'username' => $this->mobile,
            'password' => '',
            'domain' => $this->domain,
        ]);
        $empty->assertJson(['message' => __('error_message.9004_mobile')]);
    }

    /**
     * 會員掛在沒有關閉模式的下線代理，密碼錯誤時的預設訊息仍依網域代理，跟帳號不存在時一樣，不透露帳號存在
     */
    public function test_wrong_password_message_follows_domain_agent(): void
    {
        $child = Agent::factory()->create([
            'parent' => $this->agent->id,
            'username' => 'c' . bin2hex(random_bytes(5)),
            'organization_id' => $this->orgId,
            'binding_scope_id' => 1,
            'member_field_setting_group_id' => null,
        ]);
        $child->hierarchy_path = "{$this->agent->hierarchy_path}/{$child->id}";
        $child->hierarchy = '[-1,' . $child->id . ',' . $this->agent->id . ',-2]';
        $child->save();
        $this->createMember($child->refresh(), [
            'username' => 'p' . bin2hex(random_bytes(5)),
            'mobile' => $this->mobile,
            'mobile_validate' => 1,
        ]);

        $response = $this->requestApi('frontend/api/guest/login', [
            'username' => $this->mobile,
            'password' => 'wrong-password',
            'domain' => $this->domain,
        ]);

        $response->assertJson(['message' => __('error_message.9005_mobile')]);
    }

    public function test_bot_balance_and_sr12_accept_generated_username(): void
    {
        $username = self::PREFIX . '-68f1a2b3c4d5e';

        $this->assertTrue(Validator::make(['username' => $username], (new GetBalanceByUsernameRequest)->rules())->passes());
        $this->assertTrue(Validator::make(['username' => '12345'], (new GetBalanceByUsernameRequest)->rules())->fails(), '仍不收全數字');

        $sr12Rules = array_intersect_key((new Sr12Request)->rules(), array_flip(['agent_username', 'member_username']));
        $this->assertTrue(Validator::make(['agent_username' => $username, 'member_username' => $username], $sr12Rules)->passes());
    }
}
