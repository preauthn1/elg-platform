<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\Agent\RoleEnum;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\SocialLink\SocialLinkListRequest;
use App\Http\Requests\Agent\SocialLink\SocialLinkSaveRequest;
use App\Http\Resources\Agent\SocialLink\SocialLinkResource;
use App\Models\Agent;
use App\Services\Agent\AgentSocialLinkService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('social-link (社群入口配置)')]
class SocialLinkController extends Controller
{
    public function __construct(
        private readonly AgentSocialLinkService $service,
    ) {}

    /**
     * 社群入口配置清單
     *
     * 設定綁在總代身上，該線路下的所有代理共用同一份。super admin 需帶 agent_id
     * 指定要看哪個總代；其餘代理一律取自己歸屬的總代，送來的 agent_id 不予採信。
     */
    public function list(SocialLinkListRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        $canSelectAgent = $this->service->isSuperAdmin($operator);

        // super admin 未指定代理時回空清單，前端顯示「請先選擇代理」
        if ($canSelectAgent && !$request->input('agent_id')) {
            return ApiResponse::success([
                'can_select_agent' => true,
                'agent_id' => null,
                'items' => [],
            ]);
        }

        $agentId = $this->service->resolveTargetAgentId(
            $operator,
            $request->integer('agent_id') ?: null,
        );

        return ApiResponse::success([
            'can_select_agent' => $canSelectAgent,
            'agent_id' => $agentId,
            'items' => SocialLinkResource::collection($this->service->list($agentId))->toArray($request),
        ]);
    }

    /**
     * 儲存社群入口配置
     *
     * 整批覆寫：前端送完整清單，後端依 id 判斷新增／更新，清單中沒有的既有列刪除。
     * 每個總代上限 10 筆。
     */
    public function save(SocialLinkSaveRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        $agentId = $this->service->resolveTargetAgentId(
            $operator,
            $request->integer('agent_id') ?: null,
        );

        $this->service->save(
            agentId: $agentId,
            items: $request->input('items', []),
            adminId: (int) $operator->id,
        );

        return ApiResponse::success();
    }

    /**
     * 總代下拉
     *
     * 僅 super admin 需要——其餘代理的歸屬總代由身分決定、無從選擇。
     */
    public function agentDropdown(): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        if (!$this->service->isSuperAdmin($operator)) {
            return ApiResponse::success([]);
        }

        $agents = Agent::query()
            ->where('role_id', RoleEnum::GENERAL_AGENT->value)
            ->where('status', 1)
            ->orderBy('username')
            ->get(['id', 'username'])
            ->map(fn (Agent $a) => [
                'id' => (int) $a->id,
                'username' => $a->username,
            ])
            ->all();

        return ApiResponse::success($agents);
    }
}
