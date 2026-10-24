<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Agent\AgentStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCustomizedSetting;
use App\Models\AgentMenu;
use App\Models\AgentOperateLog;
use App\Models\AgentPermission;
use App\Models\AgentSettingDefine;
use App\Models\AgentStatusLog;
use App\Services\AgentLoginRestrictionService;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Services/AgentLoginRestrictionServiceTest.php
 */
class AgentLoginRestrictionServiceTest extends TestCase
{
    private AgentLoginRestrictionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AgentLoginRestrictionService::class);
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    /**
     * Service 測試不需 domain；createAgentAndDomain 的固定 domain 一測試只能用一次，改以 factory 直建。
     */
    private function makeAgent(array $data = []): Agent
    {
        $agent = Agent::factory()->create($data);
        $agent->hierarchy_path = "/{$agent->id}";
        $agent->hierarchy = "[-1,{$agent->id},-2]";
        $agent->save();

        return $agent;
    }

    /**
     * 建立帶「登入錯誤次數上限」組別設定的代理；每次用唯一 group id 避開 getSettingValue 的 30 分鐘快取。
     */
    private function makeAgentWithLimit(int $limit): Agent
    {
        AgentSettingDefine::query()->firstOrCreate(
            ['setting_id' => AgentCustomizedSettingKeyEnum::BACKEND_LOGIN_FAIL_COUNT_LIMIT],
            [
                'parent' => 5000,
                'type' => 3,
                'memo' => '代理登入錯誤次數上限 (0:不啟用)',
                'language_text_code' => 'backend_login_fail_count_limit',
                'default_value' => '0',
                'data_type' => 'int',
                'created_by' => 0,
            ],
        );

        $groupId = random_int(1_000_000, 9_999_999);

        AgentCustomizedSetting::create([
            'agent_group_id' => $groupId,
            'setting_key' => AgentCustomizedSettingKeyEnum::BACKEND_LOGIN_FAIL_COUNT_LIMIT,
            'sub_category' => '',
            'value' => (string) $limit,
            'memo' => '',
        ]);

        return $this->makeAgent([
            'customized_setting_group_id' => $groupId,
        ]);
    }

    private function createSysLockMenu(): AgentMenu
    {
        return AgentMenu::query()->firstOrCreate(
            ['code' => AgentLoginRestrictionService::PERMISSION_CODE],
            [
                'name' => '代理帳號管理_限制登入/解除',
                'parent' => 5,
                'url' => '',
                'symbol' => 'tag_invisible',
                'action' => '',
                'sort' => -999,
                'status' => 1,
                'admin' => 0,
            ],
        );
    }

    private function grantSysLockPermission(Agent $operator): void
    {
        $menu = $this->createSysLockMenu();

        AgentPermission::create([
            'agent_id' => $operator->id,
            'agent_menu_id' => $menu->id,
            'action' => '',
            'admin' => 0,
        ]);
    }

    /**
     * 建立 target 為 operator 下線的兩個代理。
     */
    private function makeOperatorWithDownline(): array
    {
        $operator = $this->makeAgent();

        $target = $this->makeAgent();
        $target->hierarchy_path = "{$operator->hierarchy_path}/{$target->id}";
        $target->save();

        return [$operator, $target];
    }

    // -------------------------------------------------------
    // recordLoginFailure
    // -------------------------------------------------------

    #[TestDox('未達上限只累加不鎖定')]
    public function test_increments_count_without_locking_below_limit(): void
    {
        $agent = $this->makeAgentWithLimit(3);

        $this->service->recordLoginFailure($agent->id);
        $this->service->recordLoginFailure($agent->id);

        $agent->refresh();
        $this->assertSame(2, $agent->login_fail_count);
        $this->assertSame(0, (int) $agent->sys_lock);
        $this->assertDatabaseMissing('agent_status_logs', ['target_id' => $agent->id]);
    }

    #[TestDox('達上限自動限制登入並寫狀態紀錄')]
    public function test_locks_and_writes_status_log_when_limit_reached(): void
    {
        $agent = $this->makeAgentWithLimit(3);

        foreach (range(1, 3) as $ignored) {
            $this->service->recordLoginFailure($agent->id);
        }

        $agent->refresh();
        $this->assertSame(3, $agent->login_fail_count);
        $this->assertSame(1, (int) $agent->sys_lock);
        $this->assertDatabaseHas('agent_status_logs', [
            'target_type' => AgentStatusLog::TARGET_TYPE_AGENT,
            'target_id' => $agent->id,
            'before_status' => 1,
            'after_status' => 1,
            'before_sys_lock' => 0,
            'after_sys_lock' => 1,
            'admin' => AgentStatusLog::ADMIN_SYSTEM,
        ]);

        // 已鎖定後再失敗，不重複寫狀態紀錄
        $this->service->recordLoginFailure($agent->id);
        $this->assertSame(1, AgentStatusLog::query()->where('target_id', $agent->id)->count());
    }

    #[TestDox('上限為零表示未啟用，永不鎖定')]
    public function test_never_locks_when_limit_is_zero(): void
    {
        $agent = $this->makeAgentWithLimit(0);

        foreach (range(1, 10) as $ignored) {
            $this->service->recordLoginFailure($agent->id);
        }

        $agent->refresh();
        $this->assertSame(10, $agent->login_fail_count);
        $this->assertSame(0, (int) $agent->sys_lock);
    }

    #[TestDox('停用代理不累加、不鎖定、不寫狀態紀錄')]
    public function test_ignores_disabled_agent(): void
    {
        $agent = $this->makeAgentWithLimit(1);
        $agent->update(['status' => AgentStatusEnum::DISABLED->value]);

        $this->service->recordLoginFailure($agent->id);

        $agent->refresh();
        $this->assertSame(0, $agent->login_fail_count);
        $this->assertSame(0, (int) $agent->sys_lock);
        $this->assertDatabaseMissing('agent_status_logs', ['target_id' => $agent->id]);
    }

    #[TestDox('已限制登入的代理不再累加')]
    public function test_ignores_already_locked_agent(): void
    {
        $agent = $this->makeAgentWithLimit(3);
        $agent->update(['sys_lock' => 1, 'login_fail_count' => 3]);

        $this->service->recordLoginFailure($agent->id);

        $agent->refresh();
        $this->assertSame(3, $agent->login_fail_count);
        $this->assertDatabaseMissing('agent_status_logs', ['target_id' => $agent->id]);
    }

    // -------------------------------------------------------
    // resetLoginFailCount
    // -------------------------------------------------------

    #[TestDox('登入成功歸零計數')]
    public function test_successful_login_resets_fail_count(): void
    {
        $agent = $this->makeAgentWithLimit(5);
        $agent->update(['login_fail_count' => 4]);

        $this->service->resetLoginFailCount($agent->id);

        $this->assertSame(0, $agent->refresh()->login_fail_count);
    }

    // -------------------------------------------------------
    // lockForInactivity
    // -------------------------------------------------------

    #[TestDox('排程鎖定冪等，且非啟用帳號不鎖')]
    public function test_inactivity_lock_is_idempotent_and_skips_disabled_agents(): void
    {
        $agent = $this->makeAgent();

        $this->assertTrue($this->service->lockForInactivity($agent->id, 30));
        $this->assertSame(1, (int) $agent->refresh()->sys_lock);
        $this->assertDatabaseHas('agent_status_logs', [
            'target_id' => $agent->id,
            'after_sys_lock' => 1,
            'admin' => AgentStatusLog::ADMIN_SYSTEM,
        ]);

        // 已鎖定者重跑不重複處理
        $this->assertFalse($this->service->lockForInactivity($agent->id, 30));
        $this->assertSame(1, AgentStatusLog::query()->where('target_id', $agent->id)->count());

        // 停用帳號不鎖
        $disabled = $this->makeAgent(['status' => 0]);
        $this->assertFalse($this->service->lockForInactivity($disabled->id, 30));
    }

    // -------------------------------------------------------
    // manualSetSysLock
    // -------------------------------------------------------

    #[TestDox('人工操作無權限碼即拒絕')]
    public function test_manual_toggle_rejected_without_permission_code(): void
    {
        [$operator, $target] = $this->makeOperatorWithDownline();
        // 權限判定改委派 AgentMenuPermissionService（工單4191），其「查無選單即放行」
        // 為既定取捨——選單須存在，本測試才真正驗到「有選單但未授權 → 拒絕」
        $this->createSysLockMenu();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Permission denied');

        $this->service->manualSetSysLock($operator, $target->id, 1, 'test');
    }

    #[TestDox('人工限制與解除限制')]
    public function test_manual_lock_and_unlock(): void
    {
        [$operator, $target] = $this->makeOperatorWithDownline();
        $this->grantSysLockPermission($operator);

        // 限制登入
        $this->service->manualSetSysLock($operator, $target->id, 1, '違規操作');

        $this->assertSame(1, (int) $target->refresh()->sys_lock);
        $this->assertDatabaseHas('agent_status_logs', [
            'target_id' => $target->id,
            'before_status' => 1,
            'after_status' => 1,
            'before_sys_lock' => 0,
            'after_sys_lock' => 1,
            'remark' => '違規操作',
            'admin' => $operator->id,
        ]);
        $logs = AgentOperateLog::query()
            ->where('agent_id', $operator->id)
            ->where('reference_id', (string) $target->id)
            ->get();
        $this->assertCount(1, $logs);
        $this->assertSame('update_agent', $logs[0]->type);
        $this->assertSame('agent', $logs[0]->reference_type);
        // 改走統一寫入方法後，存進去的字串必須與原本直接 json_encode()（預設選項）的結果逐字相同
        $this->assertSame(
            json_encode(['originalDataJson' => ['sys_lock' => 0], 'sys_lock' => 1, 'remark' => '違規操作']),
            $logs[0]->debug_info,
        );

        // 解除限制：sys_lock 歸零、錯誤計數一併歸零
        $target->update(['login_fail_count' => 5]);
        $this->service->manualSetSysLock($operator, $target->id, 0, '確認為本人');

        $target->refresh();
        $this->assertSame(0, (int) $target->sys_lock);
        $this->assertSame(0, $target->login_fail_count);
        $this->assertDatabaseHas('agent_status_logs', [
            'target_id' => $target->id,
            'before_sys_lock' => 1,
            'after_sys_lock' => 0,
            'remark' => '確認為本人',
            'admin' => $operator->id,
        ]);
    }

    #[TestDox('停用中的限制與解除，狀態欄維持停用且各記各維度')]
    public function test_lock_toggle_on_disabled_agent_keeps_status_dimension(): void
    {
        [$operator, $target] = $this->makeOperatorWithDownline();
        $this->grantSysLockPermission($operator);
        $target->update(['status' => 0]);

        $this->service->manualSetSysLock($operator, $target->id, 1, '違規');
        $this->assertDatabaseHas('agent_status_logs', [
            'target_id' => $target->id,
            'before_status' => 0,
            'after_status' => 0,
            'before_sys_lock' => 0,
            'after_sys_lock' => 1,
            'remark' => '違規',
        ]);

        $this->service->manualSetSysLock($operator, $target->id, 0, '解除');
        $this->assertDatabaseHas('agent_status_logs', [
            'target_id' => $target->id,
            'before_status' => 0,
            'after_status' => 0,
            'before_sys_lock' => 1,
            'after_sys_lock' => 0,
            'remark' => '解除',
        ]);
    }

    #[TestDox('人工操作同值直接拒絕')]
    public function test_manual_toggle_rejected_when_value_unchanged(): void
    {
        [$operator, $target] = $this->makeOperatorWithDownline();
        $this->grantSysLockPermission($operator);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('unchanged');

        $this->service->manualSetSysLock($operator, $target->id, 0, 'test');
    }

    #[TestDox('人工操作非下線即拒絕')]
    public function test_manual_toggle_rejected_for_agent_outside_downline(): void
    {
        [$operator] = $this->makeOperatorWithDownline();
        $this->grantSysLockPermission($operator);

        $outsider = $this->makeAgent();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Permission denied');

        $this->service->manualSetSysLock($operator, $outsider->id, 1, 'test');
    }
}
