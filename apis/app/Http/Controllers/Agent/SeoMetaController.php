<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\SeoMeta\SeoMetaDomainDropdownRequest;
use App\Http\Requests\Agent\SeoMeta\SeoMetaListRequest;
use App\Http\Requests\Agent\SeoMeta\SeoMetaSaveRequest;
use App\Http\Resources\Agent\SeoMeta\SeoMetaResource;
use App\Services\Agent\AgentSeoMetaService;
use App\Services\Agent\GeneralAgentScopeService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('seo-meta (SEO 元數據)')]
class SeoMetaController extends Controller
{
    public function __construct(
        private readonly AgentSeoMetaService $service,
        private readonly GeneralAgentScopeService $scopeService,
    ) {}

    /**
     * 總代下拉
     *
     * 僅 super admin 需要——其餘代理的歸屬總代由身分決定、無從選擇，回空陣列。
     */
    public function agentDropdown(): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        if (!$this->scopeService->isSuperAdmin($operator)) {
            return ApiResponse::success([]);
        }

        return ApiResponse::success($this->scopeService->generalAgentOptions());
    }

    /**
     * 域名下拉
     *
     * 列出總代自己名下、啟用中的一般域名（與代理列表「已登記網域清單」同源），下線代理名下的域名不列。
     * super admin 需帶 agent_id，未帶時回空陣列（前端顯示「請先選擇代理」）；其餘代理一律取自己歸屬的總代。
     */
    public function domainDropdown(SeoMetaDomainDropdownRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        if ($this->scopeService->isSuperAdmin($operator) && !$request->input('agent_id')) {
            return ApiResponse::success([]);
        }

        $generalAgentId = $this->scopeService->resolveTargetAgentId(
            $operator,
            $request->integer('agent_id') ?: null,
        );

        return ApiResponse::success($this->service->domainOptions($generalAgentId)->all());
    }

    /**
     * SEO 元數據清單
     *
     * 回傳指定域名每個頁面一列（沒設定過補空字串），以及該域名是否為同線預設回退。
     * 域名必須屬於解析出的總代，帶別條線的 agent_domain_id 會被拒絕。
     */
    public function list(SeoMetaListRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        $generalAgentId = $this->scopeService->resolveTargetAgentId(
            $operator,
            $request->integer('agent_id') ?: null,
        );

        $agentDomainId = $request->integer('agent_domain_id');

        $this->service->assertDomainOwnedBy($agentDomainId, $generalAgentId);

        $data = $this->service->list($agentDomainId);

        return ApiResponse::success([
            'can_select_agent' => $this->scopeService->isSuperAdmin($operator),
            'agent_id' => $generalAgentId,
            'agent_domain_id' => $agentDomainId,
            'is_default' => $data['is_default'],
            'items' => SeoMetaResource::collection($data['items'])->toArray($request),
        ]);
    }

    /**
     * 儲存 SEO 元數據
     *
     * 一次儲存單一域名的所有頁面。is_default=true 時會取消同一總代線其他域名的預設，
     * 同線只會有一個預設回退來源。
     */
    public function save(SeoMetaSaveRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        $generalAgentId = $this->scopeService->resolveTargetAgentId(
            $operator,
            $request->integer('agent_id') ?: null,
        );

        $agentDomainId = $request->integer('agent_domain_id');

        $this->service->assertDomainOwnedBy($agentDomainId, $generalAgentId);

        $this->service->save(
            agentDomainId: $agentDomainId,
            generalAgentId: $generalAgentId,
            isDefault: $request->boolean('is_default'),
            items: $request->input('items', []),
            adminId: (int) $operator->id,
        );

        return ApiResponse::success();
    }
}
