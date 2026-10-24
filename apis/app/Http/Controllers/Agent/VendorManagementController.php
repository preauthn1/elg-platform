<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\VendorManagement\UpdateCallbackPathRequest;
use App\Services\Agent\VendorManagementService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * @knowledge rules/vendor-and-channel-management
 */
#[Group('vendor-management (廠商管理)')]
class VendorManagementController extends Controller
{
    public function __construct(
        private readonly VendorManagementService $service,
    ) {}

    /**
     * 廠商管理列表
     *
     * 回傳全部狀態的遊戲商（依更新時間新到舊），每列除既有欄位外另含：錢包模式、接入通道
     * （聚合商對外名稱，直連為 direct，未掛 Driver 為 null）、有無 gamelist API、回調路徑與完整回調網址。
     * 回調路徑只適用無縫錢包；後台有覆寫用覆寫值，否則用 Driver 預設（default_callback_path）。
     * channels 為通道篩選下拉的選項，callback_base_url 為回調網址的網域部分。
     *
     * 權限：回調路徑只給帳號角色為 admin（role_id = 1）的使用者看。callback_visible 標示本次是否含回調資訊；
     * 非 admin 時為 false，且每列不含 callback_applicable、default_callback_path、callback_path、callback_url，
     * 頂層也不含 callback_base_url。
     */
    public function list(): JsonResponse
    {
        $agent = $this->getAgentContext()->agent;

        return ApiResponse::success($this->service->list($this->service->canManageCallback($agent)));
    }

    /**
     * 更新回調路徑後綴
     *
     * 只適用無縫錢包且有掛 Driver 的遊戲商，轉帳錢包會被拒絕。
     * 傳 null 或空字串代表清除覆寫，列表改回顯示 Driver 預設。
     *
     * 權限：只有帳號角色為 admin（role_id = 1）的使用者可修改，其餘回權限不足（error_message.9003）。
     */
    public function updateCallbackPath(UpdateCallbackPathRequest $request): JsonResponse
    {
        $operator = $this->getAgentContext()->agent;

        if (!$this->service->canManageCallback($operator)) {
            throw new ApiException(__('error_message.9003')); // 權限不足
        }

        $this->service->updateCallbackPath(
            vendorId: $request->integer('id'),
            path: $request->input('callback_path'),
            adminId: (int) $operator->id,
        );

        return ApiResponse::success();
    }
}
