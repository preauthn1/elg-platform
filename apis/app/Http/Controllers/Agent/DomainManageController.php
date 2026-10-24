<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\DomainManage\IndexRequest;
use App\Http\Requests\Agent\DomainManage\StoreRequest;
use App\Http\Requests\Agent\DomainManage\UpdateRequest;
use App\Http\Requests\Agent\DomainManage\ViewRequest;
use App\Http\Resources\Agent\DomainManage\DomainResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\DomainManageService;
use Dedoc\Scramble\Attributes\Group;

/**
 * 域名管理（4035）：系統管理 → 域名管理。
 *
 * 管理 `domains`（後台系統自身的網域），與代理站台網域 `agent_domains` 為不同資料。
 */
#[Group('domain-manage (域名管理)')]
class DomainManageController extends Controller
{
    public function __construct(
        protected DomainManageService $domainManageService,
    ) {}

    /**
     * 域名列表
     *
     * 支援域名 / 名稱模糊搜尋，類型與狀態精確篩選。
     */
    public function list(IndexRequest $request)
    {
        $this->assertPlatformAdmin();

        $paginator = $this->domainManageService->paginate($request->validated());

        return ApiResponse::successWithPagination(
            data: DomainResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }

    /**
     * 類型下拉選單
     *
     * 回傳啟用中的網域類型（名稱以中文呈現）。
     */
    public function typeList()
    {
        $this->assertPlatformAdmin();

        return ApiResponse::success($this->domainManageService->typeOptions());
    }

    /**
     * `domains` 是後台系統自身的網域（不是代理站點的網域），沒有組織／代理歸屬欄位可以過濾。
     * 不擋的話，任一下線代理都能列舉並改寫全表（含停用他人的網域），所以限定平台端才能操作。
     * 平台端的判定和 EmailGateway 一樣用 isPlatformAdmin()；EmailGateway 另外依組織過濾非平台端，
     * 這張表沒有組織欄位，無法這樣做。
     */
    private function assertPlatformAdmin(): void
    {
        if (!$this->getAgentContext()->agent->isPlatformAdmin()) {
            throw new ApiException(__('error_message.9003')); // 權限不足
        }
    }

    /**
     * 域名詳情
     */
    public function view(ViewRequest $request)
    {
        $this->assertPlatformAdmin();

        $domain = $this->domainManageService->find((int) $request->input('id'));
        if (!$domain) {
            throw new ApiException(__('error_message.1213'));
        }

        return ApiResponse::success(DomainResource::make($domain));
    }

    /**
     * 新增域名
     */
    public function create(StoreRequest $request)
    {
        $this->assertPlatformAdmin();

        $agent = $this->getAgentContext()->agent;
        $domain = $this->domainManageService->create($request->validated(), (int) $agent->id);

        return ApiResponse::success(['id' => $domain->id]);
    }

    /**
     * 編輯域名（含狀態切換）
     */
    public function update(UpdateRequest $request)
    {
        $this->assertPlatformAdmin();

        $agent = $this->getAgentContext()->agent;
        $data = $request->validated();
        $domain = $this->domainManageService->find((int) $data['id']);
        if (!$domain) {
            throw new ApiException(__('error_message.1213'));
        }

        unset($data['id']);
        $this->domainManageService->update($domain, $data, (int) $agent->id);

        return ApiResponse::success();
    }
}
