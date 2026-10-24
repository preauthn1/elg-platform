<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThirdParty;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Agent\AgentPersonalSettingKeyEnum;
use App\Enums\Agent\AgentStatusEnum;
use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\ThirdParty\ThirdPartyDecryptRequest;
use App\Models\Agent;
use App\Models\AgentPersonalSetting;
use App\Models\AgentStatusLog;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 客服後台登入失敗／成功回報 API（target/tickets#4535）：共用代理後台的登入錯誤計數與限制登入。
 *
 * php artisan test tests/Feature/Frontend/ThirdParty/ThirdPartyAgentLoginReportTest.php
 */
class ThirdPartyAgentLoginReportTest extends TestCase
{
    private Agent $agent;

    const API_AGENT_LOGIN_FAILED = '/frontend/api/third-party/agent-login-failed';

    const API_AGENT_LOGIN_SUCCEEDED = '/frontend/api/third-party/agent-login-succeeded';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain([
            'login_fail_count' => 0,
            'sys_lock' => 0,
        ]);
        $this->withoutMiddleware(ThirdPartyDecryptRequest::class);
    }

    #[TestDox('登入失敗：未達上限只累加錯誤次數、不鎖定')]
    public function test_agent_login_failed_increments_count(): void
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::BACKEND_LOGIN_FAIL_COUNT_LIMIT, 3);

        $response = $this->requestApi(self::API_AGENT_LOGIN_FAILED, [
            'from' => $this->agent->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson(['code' => 1000]);

        $this->agent->refresh();
        $this->assertSame(1, $this->agent->login_fail_count);
        $this->assertSame(0, (int) $this->agent->sys_lock);
    }

    #[TestDox('登入失敗：上限 3 時第 3 次限制登入並寫系統 status log')]
    public function test_agent_login_failed_locks_when_reaching_limit(): void
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::BACKEND_LOGIN_FAIL_COUNT_LIMIT, 3);

        for ($i = 1; $i <= 2; $i++) {
            $this->requestApi(self::API_AGENT_LOGIN_FAILED, ['from' => $this->agent->username])
                ->assertStatus(HttpCode::HTTP_OK->value);
        }

        $this->agent->refresh();
        $this->assertSame(2, $this->agent->login_fail_count);
        $this->assertSame(0, (int) $this->agent->sys_lock);
        $this->assertSame(0, AgentStatusLog::where('target_id', $this->agent->id)->count());

        $this->requestApi(self::API_AGENT_LOGIN_FAILED, ['from' => $this->agent->username])
            ->assertStatus(HttpCode::HTTP_OK->value);

        $this->agent->refresh();
        $this->assertSame(3, $this->agent->login_fail_count);
        $this->assertSame(1, (int) $this->agent->sys_lock);

        $logs = AgentStatusLog::where('target_type', AgentStatusLog::TARGET_TYPE_AGENT)
            ->where('target_id', $this->agent->id)
            ->get();
        $this->assertCount(1, $logs);
        $this->assertSame(1, (int) $logs->first()->after_sys_lock);
        $this->assertSame(AgentStatusLog::ADMIN_SYSTEM, (int) $logs->first()->admin);
    }

    #[TestDox('登入失敗：上限 0（未啟用）只累加不鎖定')]
    public function test_agent_login_failed_does_not_lock_when_limit_disabled(): void
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::BACKEND_LOGIN_FAIL_COUNT_LIMIT, 0);

        for ($i = 1; $i <= 5; $i++) {
            $this->requestApi(self::API_AGENT_LOGIN_FAILED, ['from' => $this->agent->username])
                ->assertStatus(HttpCode::HTTP_OK->value);
        }

        $this->agent->refresh();
        $this->assertSame(5, $this->agent->login_fail_count);
        $this->assertSame(0, (int) $this->agent->sys_lock);
        $this->assertSame(0, AgentStatusLog::where('target_id', $this->agent->id)->count());
    }

    #[TestDox('登入成功：錯誤次數歸零')]
    public function test_agent_login_succeeded_resets_count(): void
    {
        $this->agent->update(['login_fail_count' => 2]);

        $response = $this->requestApi(self::API_AGENT_LOGIN_SUCCEEDED, [
            'from' => $this->agent->username,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson(['code' => 1000]);

        $this->agent->refresh();
        $this->assertSame(0, $this->agent->login_fail_count);
    }

    #[TestDox('帳號不存在：middleware（ThirdPartyAuthMember）擋下回 1005，不建立任何資料')]
    public function test_agent_login_report_failed_agent_not_found(): void
    {
        $statusLogCount = AgentStatusLog::count();

        foreach ([self::API_AGENT_LOGIN_FAILED, self::API_AGENT_LOGIN_SUCCEEDED] as $uri) {
            $this->requestApi($uri, ['from' => 'no-such-agent'])
                ->assertStatus(HttpCode::HTTP_NOT_ACCEPTABLE->value)
                ->assertJson(['message' => __('error_message.1005')]);
        }

        $this->assertSame($statusLogCount, AgentStatusLog::count());
        $this->agent->refresh();
        $this->assertSame(0, $this->agent->login_fail_count);
    }

    #[TestDox('登入失敗：停用代理不計數、不寫 status log')]
    public function test_agent_login_failed_ignores_disabled_agent(): void
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::BACKEND_LOGIN_FAIL_COUNT_LIMIT, 1);
        $this->agent->update(['status' => AgentStatusEnum::DISABLED->value]);

        $this->requestApi(self::API_AGENT_LOGIN_FAILED, ['from' => $this->agent->username])
            ->assertStatus(HttpCode::HTTP_OK->value);

        $this->agent->refresh();
        $this->assertSame(0, $this->agent->login_fail_count);
        $this->assertSame(0, (int) $this->agent->sys_lock);
        $this->assertSame(0, AgentStatusLog::where('target_id', $this->agent->id)->count());
    }

    #[TestDox('登入失敗：已限制登入的代理不再累加、不重複寫 status log')]
    public function test_agent_login_failed_ignores_locked_agent(): void
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::BACKEND_LOGIN_FAIL_COUNT_LIMIT, 3);

        for ($i = 1; $i <= 3; $i++) {
            $this->requestApi(self::API_AGENT_LOGIN_FAILED, ['from' => $this->agent->username])
                ->assertStatus(HttpCode::HTTP_OK->value);
        }

        $this->agent->refresh();
        $this->assertSame(1, (int) $this->agent->sys_lock);
        $this->assertSame(3, $this->agent->login_fail_count);

        $this->requestApi(self::API_AGENT_LOGIN_FAILED, ['from' => $this->agent->username])
            ->assertStatus(HttpCode::HTTP_OK->value);

        $this->agent->refresh();
        $this->assertSame(3, $this->agent->login_fail_count);
        $this->assertSame(1, AgentStatusLog::where('target_id', $this->agent->id)->count());
    }

    #[TestDox('代理前台維護中：回報不受 CheckAgentMaintain 攔截，仍會計數與歸零')]
    public function test_agent_login_report_not_blocked_by_agent_maintain(): void
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::BACKEND_LOGIN_FAIL_COUNT_LIMIT, 3);
        $this->setAgentMaintain();

        // 前提：同群組其他路由確實被維護攔下（317），證明維護狀態有生效
        $this->requestApi('/frontend/api/third-party/login-setting', ['from' => $this->agent->username])
            ->assertStatus(HttpCode::HTTP_AGENT_MAINTAIN->value);

        $this->requestApi(self::API_AGENT_LOGIN_FAILED, ['from' => $this->agent->username])
            ->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson(['code' => 1000]);

        $this->assertSame(1, $this->agent->refresh()->login_fail_count);

        $this->requestApi(self::API_AGENT_LOGIN_SUCCEEDED, ['from' => $this->agent->username])
            ->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson(['code' => 1000]);

        $this->assertSame(0, $this->agent->refresh()->login_fail_count);
    }

    #[TestDox('缺 from 參數：middleware（ThirdPartyAuthMember）擋下回 1005')]
    public function test_agent_login_report_failed_missing_from(): void
    {
        foreach ([self::API_AGENT_LOGIN_FAILED, self::API_AGENT_LOGIN_SUCCEEDED] as $uri) {
            $this->requestApi($uri)
                ->assertStatus(HttpCode::HTTP_NOT_ACCEPTABLE->value)
                ->assertJson(['message' => __('error_message.1005')]);
        }
    }

    /**
     * 讓代理進入前台維護（寫法同 Frontend\Agent\TemplateTest）。
     *
     * 維護名單快取（CacheKeys::maintainAgents）在測試走 redis、跨進程持久，寫入前後各清一次。
     */
    private function setAgentMaintain(): void
    {
        Cache::forget(CacheKeys::maintainAgents());
        $this->beforeApplicationDestroyed(static fn () => Cache::forget(CacheKeys::maintainAgents()));

        foreach ([
            AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_FLAG => 1,
            AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_START_TIME => Carbon::now()->subHour(),
            AgentPersonalSettingKeyEnum::AGENT_MAINTAIN_END_TIME => Carbon::now()->addHour(),
        ] as $settingId => $value) {
            AgentPersonalSetting::create([
                'agent_id' => $this->agent->id,
                'setting_id' => $settingId,
                'value' => $value,
                'created_by' => 0,
            ]);
        }
    }
}
