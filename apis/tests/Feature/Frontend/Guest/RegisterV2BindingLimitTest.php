<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Guest;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Services\EventService;
use App\Services\Frontend\EventInvite\EventInviteService;
use App\Services\MemberCodeService;
use App\Services\MemberService;
use App\Services\RegisterService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * register-v2 手機／信箱綁定上限的計數範疇（target/tickets#4309，現場案例 target/op_tickets#3092）。
 *
 * 同一組織下以 binding_scope_id 分站的品牌（togo／77win／WINT 皆 org 1），手機／信箱名額必須是
 * 「同組織＋同 scope、只計已驗證會員」：跨 scope 不互擋、未驗證帳號不佔名額、同 scope 達上限回 1090／1088。
 * 回歸背景：phone/email 原本也交給註冊限制 check()（跨 scope、含未驗證）先攔成 2055，綁定上限形同死碼；
 * email 的綁定上限原本也只看組織、未分 scope。
 *
 * php artisan test tests/Feature/Frontend/Guest/RegisterV2BindingLimitTest.php
 */
class RegisterV2BindingLimitTest extends TestCase
{
    private const DOMAIN_SCOPE = 3;

    /** 測試自建的組織（測試庫無業務主檔；Agent::currency_id 經 organization 關聯取得） */
    private int $orgId;

    private int $groupId;

    private string $mobile;

    private string $email;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);
        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->mobile = '6011' . str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
        $this->email = 'bl' . bin2hex(random_bytes(5)) . '@limit.test';

        $this->orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'org-' . bin2hex(random_bytes(4)),
            'currency_id' => 1,
            'memo' => 'register limit test',
        ]);

        // type=6 組別：phone / email 上限皆 1（與 3092 現場 WINT 所掛的 804 設定相同）
        $this->groupId = (int) DB::table('agent_groups')->insertGetId([
            'agent_group_type' => 6,
            'organization_id' => $this->orgId,
            'name' => 'register limit test',
            'memo' => '',
            'is_default' => 0,
        ]);
        foreach (['phone', 'email'] as $field) {
            DB::table('agent_register_limits')->insert([
                'agent_group_id' => $this->groupId,
                'currency_id' => 1,
                'field_key' => $field,
                'platform_enabled' => 1,
                'platform_max_limit' => 1,
                'custom_enabled' => 0,
                'admin' => 0,
            ]);
        }

        // 註冊入口站（域名代理）：scope 3
        $this->createAgentAndDomain([
            'organization_id' => $this->orgId,
            'binding_scope_id' => self::DOMAIN_SCOPE,
            'member_field_setting_group_id' => $this->groupId,
        ]);

        // 與 RegisterTest 同款：帶建構子依賴的 partial mock（partialMock() 不走建構子，typed 屬性會未初始化）
        $registerServiceMock = Mockery::mock(RegisterService::class, [
            $this->app->make(EventService::class),
            $this->app->make(MemberService::class),
            $this->app->make(MemberCodeService::class),
            $this->app->make(EventInviteService::class),
        ])->makePartial();
        $registerServiceMock->shouldReceive('triggerCsSyncData')->andReturnNull();
        $registerServiceMock->shouldReceive('runRegisterEventStoredProcedures')->andReturnNull();
        $this->app->instance(RegisterService::class, $registerServiceMock);
    }

    /** 同組織、指定 scope 的另一個代理底下，建一個會員（$attributes 帶手機或信箱與其驗證狀態） */
    private function createMemberInScope(int $bindingScope, array $attributes): void
    {
        // createAgentAndDomain 共用 $this->domain，第二個代理需換域名避免撞唯一鍵
        $originalDomain = $this->domain;
        $this->domain = Str::random(12) . '.test.local';
        $agent = $this->createAgentAndDomain([
            'organization_id' => $this->orgId,
            'binding_scope_id' => $bindingScope,
            'member_field_setting_group_id' => $this->groupId,
        ]);
        $this->domain = $originalDomain;

        $this->createMember($agent, ['username' => 'p' . bin2hex(random_bytes(5))] + $attributes);
    }

    private function createMemberWithMobile(int $bindingScope, int $mobileValidate): void
    {
        $this->createMemberInScope($bindingScope, ['mobile' => $this->mobile, 'mobile_validate' => $mobileValidate]);
    }

    private function createMemberWithEmail(int $bindingScope, int $emailValidate): void
    {
        $this->createMemberInScope($bindingScope, ['email' => $this->email, 'email_validate' => $emailValidate]);
    }

    /** @param  array<string, string>  $contact  手機或信箱（組別未啟用註冊欄位 → 不需驗證碼，以未驗證狀態存入） */
    private function register(array $contact): TestResponse
    {
        // domain 為 HasDomain middleware 解析「域名代理」的來源（與前端送法一致）
        return $this->requestApi('frontend/api/guest/register-v2', [
            'domain' => $this->domain,
            'username' => 'r' . substr(bin2hex(random_bytes(6)), 0, 9),
            'password' => 'Aa112233',
            'confirm_password' => 'Aa112233',
            'last_name' => 'BINDING LIMIT TEST',
        ] + $contact);
    }

    // ── 手機 ──

    public function test_verified_mobile_in_other_binding_scope_does_not_block(): void
    {
        // 3092 現場：同號已在 togo（scope 2）驗證，本人改到 WINT（scope 3）註冊
        $this->createMemberWithMobile(bindingScope: 2, mobileValidate: 1);

        $response = $this->register(['mobile' => $this->mobile]);

        $response->assertJson(['code' => 200]);
    }

    public function test_unverified_mobile_in_same_scope_does_not_occupy_quota(): void
    {
        $this->createMemberWithMobile(bindingScope: self::DOMAIN_SCOPE, mobileValidate: 0);

        $response = $this->register(['mobile' => $this->mobile]);

        $response->assertJson(['code' => 200]);
    }

    public function test_verified_mobile_in_same_scope_hits_binding_limit(): void
    {
        $this->createMemberWithMobile(bindingScope: self::DOMAIN_SCOPE, mobileValidate: 1);

        $response = $this->register(['mobile' => $this->mobile]);

        $this->assertSame(1090, $response->json('code'), '同 scope 已驗證達上限 → 綁定上限 1090，而非註冊限制 2055');
    }

    // ── 信箱（口徑與手機一致） ──

    public function test_verified_email_in_other_binding_scope_does_not_block(): void
    {
        $this->createMemberWithEmail(bindingScope: 2, emailValidate: 1);

        $response = $this->register(['email' => $this->email]);

        $response->assertJson(['code' => 200]);
    }

    public function test_verified_email_in_same_scope_hits_binding_limit(): void
    {
        $this->createMemberWithEmail(bindingScope: self::DOMAIN_SCOPE, emailValidate: 1);

        $response = $this->register(['email' => $this->email]);

        $this->assertSame(1088, $response->json('code'), '同 scope 已驗證達上限 → 綁定上限 1088，而非註冊限制 2055');
    }
}
