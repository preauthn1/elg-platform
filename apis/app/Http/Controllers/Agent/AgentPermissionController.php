<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\AgentPermission\GroupPermissionUpdateRequest;
use App\Http\Requests\Agent\AgentPermission\GroupPermissionViewRequest;
use App\Http\Requests\Agent\AgentPermission\MenuTreeRequest;
use App\Http\Requests\Agent\AgentPermission\SettingRequest;
use App\Services\Agent\AgentGroupPermissionService;
use App\Services\Agent\AgentMenuTreeService;
use App\Services\Agent\AgentPermissionSettingService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 代理個人權限、子帳號同步、選單樹、權限地圖、組別管理權（target/tickets#4422 自 API_Agent 搬入，新後台改打這裡）。
 *
 * 舊後台仍走 API_Agent 同名接口，兩邊讀寫同一批表；規則見各 Service。
 */
#[Group('agent permission (代理權限)')]
class AgentPermissionController extends Controller
{
    public function __construct(
        private readonly AgentPermissionSettingService $settingService,
        private readonly AgentMenuTreeService $menuTreeService,
        private readonly AgentGroupPermissionService $groupPermissionService,
    ) {}

    /**
     * 存代理個人權限
     *
     * 差集更新；不能授出自己沒有的權限（目標原本就有的、已停用的選單除外），super admin 不受限。
     */
    public function setting(SettingRequest $request): JsonResponse
    {
        $this->settingService->setting(
            $this->getAgentContext()->agent,
            (int) $request->input('agent_id'),
            (array) $request->input('menus', []),
        );

        return ApiResponse::success();
    }

    /** 主帳號把個人權限補給所有子帳號（只增不刪），並把角色補給沒有角色的子帳號 */
    public function syncChildren(): JsonResponse
    {
        return ApiResponse::success([
            'agent_ids' => $this->settingService->syncChildren($this->getAgentContext()->agent),
        ]);
    }

    /** 操作者自己的子帳號清單（host_id＝自己）；只回自己的資料，照 API_Agent 不另掛選單權限 */
    public function childAccounts(): JsonResponse
    {
        return ApiResponse::success($this->settingService->childAccounts($this->getAgentContext()->agent));
    }

    /** 代理的生效選單樹（每次即時算，不快取） */
    public function menuTree(MenuTreeRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->menuTreeService->tree($this->getAgentContext()->agent, (int) $request->input('agent_id')),
        );
    }

    /** 權限地圖 {code: true}，前端判斷按鈕顯示用 */
    public function codeMap(): JsonResponse
    {
        return ApiResponse::success($this->menuTreeService->codeMap($this->getAgentContext()->agent));
    }

    /** 代理的組別管理權（能編輯哪些設定組別） */
    public function groupPermissions(GroupPermissionViewRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->groupPermissionService->view($this->getAgentContext()->agent, (int) $request->input('agent_id')),
        );
    }

    /** 更新代理的組別管理權 */
    public function updateGroupPermissions(GroupPermissionUpdateRequest $request): JsonResponse
    {
        $this->groupPermissionService->update(
            $this->getAgentContext()->agent,
            (int) $request->input('agent_id'),
            array_map('intval', (array) $request->input('group_permission', [])),
        );

        return ApiResponse::success();
    }
}
