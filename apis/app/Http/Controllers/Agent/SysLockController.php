<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\SysLock\UpdateRequest;
use App\Services\AgentLoginRestrictionService;
use Dedoc\Scramble\Attributes\Group;

#[Group('營運商管理')]
class SysLockController extends Controller
{
    public function __construct(
        protected AgentLoginRestrictionService $restrictionService,
    ) {}

    /**
     * 人工限制登入 / 解除限制（agents.sys_lock），需持有 agent_sys_lock 權限。
     * 系統自動鎖定（密碼錯誤達上限、長期未登入排程）不走此 API。
     */
    public function update(UpdateRequest $request)
    {
        $input = $request->validated();
        $operator = $this->getAgentContext()->agent;

        $this->restrictionService->manualSetSysLock(
            operator: $operator,
            agentId: (int) $input['agent_id'],
            sysLock: (int) $input['sys_lock'],
            remark: $input['remark'] ?? '',
        );

        return ApiResponse::success();
    }
}
