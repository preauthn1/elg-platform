<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\AgentOperateLog\PlainDebugInfo;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Agent\AgentOperateReferenceTypeEnum;
use App\Enums\Agent\AgentOperateTypeEnum;
use App\Enums\Agent\AgentStatusEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentStatusLog;
use App\Services\Agent\AgentMenuPermissionService;
use App\Services\Agent\AgentOperateLogService;
use Illuminate\Support\Facades\DB;

/**
 * 代理限制登入（agents.sys_lock）的集中處理。
 *
 * 兩種系統自動鎖定來源：
 * 1. 登入密碼連續錯誤達組別設定上限（recordLoginFailure）
 * 2. 長期未登入排程（lockForInactivity，由 agent:lock-inactive 呼叫）
 *
 * 所有 sys_lock 異動一律寫 agent_status_logs（admin = 0 表系統），
 * 備註必須寫明鎖定原因，供後台「操作紀錄」彈窗檢視。
 */
class AgentLoginRestrictionService
{
    /**
     * 人工限制/解除限制的權限碼（agent_menus.code）。
     */
    public const string PERMISSION_CODE = 'agent_sys_lock';

    /**
     * 登入密碼錯誤：累加連續錯誤次數，達組別設定上限即限制登入。
     *
     * 上限設定 0 表示功能未啟用，僅累加不鎖定。
     *
     * 停用或已限制登入的代理不計數：代理後台登入本來就先擋掉這兩種帳號，
     * 客服後台回報（third-party/agent-login-failed，target/tickets#4535）不經那道過濾，在這裡統一把關。
     */
    public function recordLoginFailure(int $agentId): void
    {
        DB::transaction(function () use ($agentId) {
            $agent = Agent::query()->whereKey($agentId)->lockForUpdate()->first();

            if (!$agent || $agent->status != AgentStatusEnum::ENABLED->value || $agent->sys_lock) {
                return;
            }

            $agent->login_fail_count++;

            $limit = (int) $agent->getSettingValue(AgentCustomizedSettingKeyEnum::BACKEND_LOGIN_FAIL_COUNT_LIMIT);

            if ($limit > 0 && $agent->login_fail_count >= $limit && !$agent->sys_lock) {
                $agent->sys_lock = 1;

                $this->writeSystemStatusLog(
                    agentId: $agent->id,
                    remark: __('param.agent_sys_lock.auto_lock_password', ['limit' => $limit]),
                );
            }

            $agent->save();
        });
    }

    /**
     * 登入成功：歸零連續錯誤次數。
     */
    public function resetLoginFailCount(int $agentId): void
    {
        Agent::query()
            ->whereKey($agentId)
            ->where('login_fail_count', '>', 0)
            ->update(['login_fail_count' => 0]);
    }

    /**
     * 長期未登入鎖定：由排程呼叫，重驗狀態後鎖定並留紀錄。
     *
     * @return bool 是否實際執行鎖定（已鎖或帳號非啟用時回 false）
     */
    public function lockForInactivity(int $agentId, int $days): bool
    {
        return DB::transaction(function () use ($agentId, $days) {
            $agent = Agent::query()->whereKey($agentId)->lockForUpdate()->first();

            if (!$agent || $agent->sys_lock || $agent->status != 1) {
                return false;
            }

            $agent->update(['sys_lock' => 1]);

            $this->writeSystemStatusLog(
                agentId: $agent->id,
                remark: __('param.agent_sys_lock.auto_lock_inactive', ['days' => $days]),
            );

            return true;
        });
    }

    /**
     * 人工限制登入 / 解除限制。
     *
     * 操作者須持有 agent_sys_lock 權限碼，且目標須在其下線範圍
     * （admin：id = 1 或其子帳號不受限）；解除時同步歸零連續錯誤計數。
     */
    public function manualSetSysLock(Agent $operator, int $agentId, int $sysLock, string $remark): void
    {
        if (!$this->hasPermissionCode($operator, self::PERMISSION_CODE)) {
            throw new ApiException('Permission denied');
        }

        DB::transaction(function () use ($operator, $agentId, $sysLock, $remark) {
            $agent = Agent::query()->whereKey($agentId)->lockForUpdate()->first();

            if (!$agent) {
                throw new ApiException('Agent not found');
            }

            $this->assertInDownline($operator, $agent);

            if ($agent->sys_lock == $sysLock) {
                throw new ApiException('Agent sys_lock unchanged');
            }

            $update = ['sys_lock' => $sysLock];

            if ($sysLock == 0) {
                // 解除限制同時歸零連續錯誤計數，避免下一次錯誤立即再鎖
                $update['login_fail_count'] = 0;
            }

            $agent->update($update);

            // status 與 sys_lock 各記各的維度：本操作不動 status，前後同值
            AgentStatusLog::create([
                'target_type' => AgentStatusLog::TARGET_TYPE_AGENT,
                'target_id' => $agent->id,
                'before_status' => $agent->status,
                'after_status' => $agent->status,
                'before_sys_lock' => $sysLock == 1 ? 0 : 1,
                'after_sys_lock' => $sysLock,
                'remark' => $remark,
                'admin' => $operator->id,
            ]);

            app(AgentOperateLogService::class)->log(
                agentId: $operator->id,
                type: AgentOperateTypeEnum::UPDATE_AGENT,
                debugInfo: new PlainDebugInfo([
                    'originalDataJson' => ['sys_lock' => $sysLock == 1 ? 0 : 1],
                    'sys_lock' => $sysLock,
                    'remark' => $remark,
                ]),
                // 不用 Context 的 ip：那是 WEB 後台轉發機的 IP，非操作者真實 IP
                ip: Common::getAgentClientIp(),
                referenceId: (string) $agent->id,
                referenceType: AgentOperateReferenceTypeEnum::AGENT,
            );
        });
    }

    /**
     * 操作者是否持有指定權限碼（agent_menus.code）。
     *
     * 委派 AgentMenuPermissionService 判定「個人 ∪ 角色」的生效權限（工單4080）；
     * 原本自查 agent_permissions 只認個人授權，權限靠角色取得的操作者
     * 會被誤判為無權限（工單4191）。
     */
    private function hasPermissionCode(Agent $operator, string $code): bool
    {
        return app(AgentMenuPermissionService::class)->hasAny($operator, [$code]);
    }

    /**
     * 目標代理須在操作者的下線範圍；admin（id = 1 或其子帳號）不受限。
     */
    private function assertInDownline(Agent $operator, Agent $target): void
    {
        if ($operator->id == 1 || $operator->host_id == 1) {
            return;
        }

        $scopePath = $operator->hierarchy_path;

        // 子帳號以其主帳號的層級為準
        if ($operator->host_id != 0) {
            $scopePath = Agent::query()->whereKey($operator->host_id)->value('hierarchy_path') ?? $scopePath;
        }

        if (!str_starts_with((string) $target->hierarchy_path, (string) $scopePath)) {
            throw new ApiException('Permission denied');
        }
    }

    private function writeSystemStatusLog(int $agentId, string $remark): void
    {
        // 系統自動鎖僅發生於啟用中帳號（登入路徑與排程都先過 status=1）；status 維度前後同值
        AgentStatusLog::create([
            'target_type' => AgentStatusLog::TARGET_TYPE_AGENT,
            'target_id' => $agentId,
            'before_status' => AgentStatusEnum::ENABLED->value,
            'after_status' => AgentStatusEnum::ENABLED->value,
            'before_sys_lock' => 0,
            'after_sys_lock' => 1,
            'remark' => $remark,
            'admin' => AgentStatusLog::ADMIN_SYSTEM,
        ]);
    }
}
