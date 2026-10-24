<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Guest;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Services\EventService;
use App\Services\Frontend\EventInvite\EventInviteService;
use App\Services\MemberCodeService;
use App\Services\MemberFieldSettingService;
use App\Services\MemberService;
use App\Services\RegisterService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * register-v2 玩家帳號字元規則還原（target/tickets#4313，現場案例 target/op_tickets#3113）。
 *
 * 各站 9/2 切到 v2 後純數字帳號放行，與他人手機號撞號會讓手機登入撈錯人。
 * 規則回到與舊版 register 相同的 App\Rules\Username（只能英數、至少一個英文字母）；
 * 「手機號碼作為用戶名」「小寫用戶名」兩個後台選項一併移除，config 殘留的舊 key 不得再影響驗證。
 *
 * php artisan test tests/Feature/Frontend/Guest/RegisterV2UsernameRuleTest.php
 */
class RegisterV2UsernameRuleTest extends TestCase
{
    private int $groupId;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);
        $this->seed(LanguageSeeder::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'org-' . bin2hex(random_bytes(4)),
            'currency_id' => 1,
            'memo' => 'username rule test',
        ]);

        $this->groupId = (int) DB::table('agent_groups')->insertGetId([
            'agent_group_type' => 6,
            'organization_id' => $orgId,
            'name' => 'username rule test',
            'memo' => '',
            'is_default' => 0,
        ]);

        // 模擬升級前殘留的舊 key：兩個選項都開，驗證端必須視而不見
        DB::table('agent_member_field_settings')->insert([
            'agent_group_id' => $this->groupId,
            'currency_id' => 1,
            'page_type' => 1,
            'field_key' => 'username',
            'field_group' => 1,
            'sort' => 1,
            'is_required' => 1,
            'status' => 1,
            'extra_config' => json_encode([
                'min_length' => 4,
                'max_length' => 10,
                'mobile_as_username' => true,
                'lowercase_username' => true,
            ]),
            'admin' => 0,
        ]);

        $this->agent = $this->createAgentAndDomain([
            'organization_id' => $orgId,
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

    private function register(string $username): TestResponse
    {
        return $this->requestApi('frontend/api/guest/register-v2', [
            'domain' => $this->domain,
            'username' => $username,
            'password' => 'Aa112233',
            'confirm_password' => 'Aa112233',
            'last_name' => 'USERNAME RULE TEST',
        ]);
    }

    public function test_numeric_only_username_is_rejected(): void
    {
        // 3113 現場：TPower 會員 955912 以手機號樣式的純數字帳號通過 v2 建號
        $response = $this->register('0116402700');

        $response->assertStatus(400);
        $this->assertSame(
            __('error_message.username rule', ['attribute' => __('validation.attributes.player_account')]),
            $response->json('message'),
        );
        $this->assertDatabaseMissing('members', ['username' => '0116402700']);
    }

    public function test_username_with_letter_and_digits_is_accepted(): void
    {
        $username = 'r' . substr(bin2hex(random_bytes(6)), 0, 9);

        $response = $this->register($username);

        $response->assertJson(['code' => 200]);
        $this->assertDatabaseHas('members', ['username' => $username]);
    }

    public function test_uppercase_username_is_no_longer_blocked_by_stale_lowercase_config(): void
    {
        // 舊「小寫用戶名」開啟時含大寫回 2057；選項移除後 config 殘留 true 也不得再擋
        $username = 'ABC' . substr(bin2hex(random_bytes(6)), 0, 6);

        $response = $this->register($username);

        $response->assertJson(['code' => 200]);
        $this->assertDatabaseHas('members', ['username' => $username]);
    }

    public function test_presentation_strips_stale_username_option_keys(): void
    {
        // 前台 registerFields.js 仍依這兩個 key 鎖欄位／自動填手機號，apis 回給前台的設定必須剔除
        $register = $this->app->make(MemberFieldSettingService::class)->presentation($this->agent)['register'];
        $username = collect($register)->firstWhere('field_key', 'username');

        // MySQL JSON 欄位不保證 key 順序，只比內容
        $this->assertEquals(['min_length' => 4, 'max_length' => 10], $username['extra_config']);
    }

    public function test_stale_mobile_as_username_config_no_longer_skips_length_rule(): void
    {
        // 舊「手機號碼作為用戶名」開啟時 username 跳過 4-10 字數檢查；選項移除後字數規則一律生效
        $response = $this->register('abcdefghijklmnop');

        $this->assertSame(2054, $response->json('code'), '超過 max_length 10 → 字數規則 2054');
    }
}
