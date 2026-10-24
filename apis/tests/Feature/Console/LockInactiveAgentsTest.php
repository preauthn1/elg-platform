<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Models\Agent;
use App\Models\AgentCustomizedSetting;
use App\Models\AgentSettingDefine;
use App\Models\AgentStatusLog;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/LockInactiveAgentsTest.php
 *
 * agent:lock-inactive（tickets#1988）整合測試：
 * 組別設定（5003）載入、--dry-run / 實際執行行為、排除條件與冪等性。
 */
class LockInactiveAgentsTest extends TestCase
{
    private const string SENTINEL_LAST_LOGIN = '1970-01-01 00:00:00';

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    /**
     * 5003 定義列（testing 庫無 seeder 資料，測試自建）；用 updateOrCreate
     * 確保 default_value 為測試要的值——庫裡若有殘留列也會被覆寫，前置條件不靜默失效。
     */
    private function ensureDefine(string $defaultValue = '0'): void
    {
        AgentSettingDefine::query()->updateOrCreate(
            ['setting_id' => AgentCustomizedSettingKeyEnum::BACKEND_INACTIVE_LOCK_DAYS],
            [
                'parent' => 5000,
                'type' => 3,
                'memo' => '代理長期未登入限制天數 (0:不啟用)',
                'language_text_code' => 'backend_inactive_lock_days',
                'default_value' => $defaultValue,
                'data_type' => 'int',
                'created_by' => 0,
            ],
        );
    }

    /**
     * 建立帶未登入天數設定的組別；每次用唯一 group id，避免測試間互相干擾。
     *
     * @param  int|null  $days  null 表不建立組別值（走 define 預設值 fallback）
     */
    private function makeGroup(?int $days): int
    {
        $groupId = random_int(1_000_000, 9_999_999);

        if ($days !== null) {
            AgentCustomizedSetting::create([
                'agent_group_id' => $groupId,
                'setting_key' => AgentCustomizedSettingKeyEnum::BACKEND_INACTIVE_LOCK_DAYS,
                'sub_category' => '',
                'value' => (string) $days,
                'memo' => '',
            ]);
        }

        return $groupId;
    }

    private function makeAgentInGroup(int $groupId, ?string $lastLogin, array $data = []): Agent
    {
        return Agent::factory()->create(array_merge([
            'customized_setting_group_id' => $groupId,
            'last_login' => $lastLogin,
        ], $data));
    }

    // -------------------------------------------------------
    // dry-run
    // -------------------------------------------------------

    #[TestDox('dry-run 只列名單，不實際鎖定')]
    public function test_dry_run_lists_candidates_without_locking(): void
    {
        $this->ensureDefine();
        $groupId = $this->makeGroup(30);
        $agent = $this->makeAgentInGroup($groupId, now()->subDays(40)->toDateTimeString());

        $this->artisan('agent:lock-inactive', ['--dry-run' => true])
            ->expectsOutputToContain('dry-run：共 1 筆將被限制登入')
            ->assertSuccessful();

        $this->assertSame(0, (int) $agent->refresh()->sys_lock);
        $this->assertDatabaseMissing('agent_status_logs', ['target_id' => $agent->id]);
    }

    // -------------------------------------------------------
    // 實際執行（預設）
    // -------------------------------------------------------

    #[TestDox('鎖定超期代理並寫入系統狀態紀錄（含剛好滿天數的邊界值）')]
    public function test_locks_overdue_agents_and_writes_system_status_log(): void
    {
        $this->ensureDefine();
        $groupId = $this->makeGroup(30);
        $overdue = $this->makeAgentInGroup($groupId, now()->subDays(31)->toDateTimeString());
        // 邊界：剛好滿 30 天也要鎖（條件為「早於或等於門檻」）
        $boundary = $this->makeAgentInGroup($groupId, now()->subDays(30)->toDateTimeString());

        $this->artisan('agent:lock-inactive')
            ->expectsOutputToContain('已限制登入 2 筆')
            ->assertSuccessful();

        foreach ([$overdue, $boundary] as $agent) {
            $this->assertSame(1, (int) $agent->refresh()->sys_lock);
            $this->assertDatabaseHas('agent_status_logs', [
                'target_type' => AgentStatusLog::TARGET_TYPE_AGENT,
                'target_id' => $agent->id,
                'before_status' => 1,
                'after_status' => 1,
                'before_sys_lock' => 0,
                'after_sys_lock' => 1,
                'remark' => __('param.agent_sys_lock.auto_lock_inactive', ['days' => 30]),
                'admin' => AgentStatusLog::ADMIN_SYSTEM,
            ]);
        }
    }

    #[TestDox('重跑不重複鎖定、不重複寫紀錄')]
    public function test_rerun_is_idempotent(): void
    {
        $this->ensureDefine();
        $groupId = $this->makeGroup(30);
        $agent = $this->makeAgentInGroup($groupId, now()->subDays(60)->toDateTimeString());

        $this->artisan('agent:lock-inactive')
            ->expectsOutputToContain('已限制登入 1 筆')
            ->assertSuccessful();

        $this->artisan('agent:lock-inactive')
            ->expectsOutputToContain('已限制登入 0 筆')
            ->assertSuccessful();

        $this->assertSame(1, (int) $agent->refresh()->sys_lock);
        $this->assertSame(1, AgentStatusLog::query()->where('target_id', $agent->id)->count());
    }

    // -------------------------------------------------------
    // 排除條件
    // -------------------------------------------------------

    #[TestDox('未超期、sentinel、已鎖定、停用與從未登入者皆不處理')]
    public function test_skips_recent_sentinel_locked_disabled_and_never_logged_in_agents(): void
    {
        $this->ensureDefine();
        $groupId = $this->makeGroup(30);

        $recent = $this->makeAgentInGroup($groupId, now()->subDays(10)->toDateTimeString());
        $sentinel = $this->makeAgentInGroup($groupId, self::SENTINEL_LAST_LOGIN);
        $alreadyLocked = $this->makeAgentInGroup($groupId, now()->subDays(90)->toDateTimeString(), ['sys_lock' => 1]);
        $disabled = $this->makeAgentInGroup($groupId, now()->subDays(90)->toDateTimeString(), ['status' => 0]);
        $neverLoggedIn = $this->makeAgentInGroup($groupId, null);

        $this->artisan('agent:lock-inactive')
            ->expectsOutputToContain('已限制登入 0 筆')
            ->assertSuccessful();

        $this->assertSame(0, (int) $recent->refresh()->sys_lock);
        $this->assertSame(0, (int) $sentinel->refresh()->sys_lock);
        $this->assertSame(0, (int) $disabled->refresh()->sys_lock);
        $this->assertSame(0, (int) $neverLoggedIn->refresh()->sys_lock);

        // 已鎖定者不重複處理、不重複寫紀錄
        $this->assertSame(1, (int) $alreadyLocked->refresh()->sys_lock);
        $this->assertDatabaseMissing('agent_status_logs', ['target_id' => $alreadyLocked->id]);
    }

    #[TestDox('所有組別皆未啟用（0）時不處理')]
    public function test_does_nothing_when_all_groups_disabled(): void
    {
        $this->ensureDefine();
        $groupId = $this->makeGroup(0);
        $agent = $this->makeAgentInGroup($groupId, now()->subDays(400)->toDateTimeString());

        $this->artisan('agent:lock-inactive')
            ->expectsOutputToContain('未啟用')
            ->assertSuccessful();

        $this->assertSame(0, (int) $agent->refresh()->sys_lock);
    }

    // -------------------------------------------------------
    // define 預設值 fallback
    // -------------------------------------------------------

    #[TestDox('define 預設值對未設定組別生效；明確設 0 的組別維持關閉')]
    public function test_define_default_applies_while_explicit_zero_stays_disabled(): void
    {
        $this->ensureDefine('14');

        // 無組別值 → 吃 define 預設 14 天
        $groupWithoutValue = $this->makeGroup(null);
        $lockedByDefault = $this->makeAgentInGroup($groupWithoutValue, now()->subDays(20)->toDateTimeString());
        $withinDefault = $this->makeAgentInGroup($groupWithoutValue, now()->subDays(7)->toDateTimeString());

        // 明確設 0 → 該組別關閉，不吃預設
        $groupExplicitZero = $this->makeGroup(0);
        $notLocked = $this->makeAgentInGroup($groupExplicitZero, now()->subDays(400)->toDateTimeString());

        $this->artisan('agent:lock-inactive')->assertSuccessful();

        $this->assertSame(1, (int) $lockedByDefault->refresh()->sys_lock);
        $this->assertDatabaseHas('agent_status_logs', [
            'target_id' => $lockedByDefault->id,
            'remark' => __('param.agent_sys_lock.auto_lock_inactive', ['days' => 14]),
            'admin' => AgentStatusLog::ADMIN_SYSTEM,
        ]);

        $this->assertSame(0, (int) $withinDefault->refresh()->sys_lock);
        $this->assertSame(0, (int) $notLocked->refresh()->sys_lock);
    }
}
