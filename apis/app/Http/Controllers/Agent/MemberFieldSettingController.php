<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\MemberFieldPageTypeEnum;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\MemberFieldSetting\MemberFieldSettingFieldsUpdateRequest;
use App\Http\Requests\Agent\MemberFieldSetting\MemberFieldSettingGroupRequest;
use App\Http\Requests\Agent\MemberFieldSetting\MemberFieldSettingLimitsUpdateRequest;
use App\Services\Agent\MemberFieldSettingManageService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 會員欄位設置組別（agent_group_type=6）：註冊、登入、實名驗證欄位與註冊限制（target/tickets#4459）。
 *
 * 對應舊系統 API_Agent AgentMemberFieldSettingController，規則見 {@see MemberFieldSettingManageService}。
 */
#[Group('member-field-setting (會員欄位設置)')]
class MemberFieldSettingController extends Controller
{
    public function __construct(
        private readonly MemberFieldSettingManageService $service,
    ) {}

    /**
     * 取得組別的註冊、登入、實名驗證欄位與註冊限制
     */
    public function view(MemberFieldSettingGroupRequest $request): JsonResponse
    {
        return ApiResponse::success($this->service->view($this->getAgentContext()->agent, $request->integer('group_id')));
    }

    /**
     * 組別底下是否已有會員（玩家帳號從開改成關、存檔前查，有會員才跳確認框）
     */
    public function hasMembers(MemberFieldSettingGroupRequest $request): JsonResponse
    {
        return ApiResponse::success([
            'has_members' => $this->service->hasMembers($this->getAgentContext()->agent, $request->integer('group_id')),
        ]);
    }

    /**
     * 更新某一頁的欄位設定
     */
    public function saveFields(MemberFieldSettingFieldsUpdateRequest $request): JsonResponse
    {
        $this->service->saveFields(
            operator: $this->getAgentContext()->agent,
            groupId: $request->integer('group_id'),
            pageType: MemberFieldPageTypeEnum::from($request->integer('page_type')),
            fields: $request->input('fields'),
        );

        return ApiResponse::success();
    }

    /**
     * 更新註冊限制
     */
    public function saveRegisterLimits(MemberFieldSettingLimitsUpdateRequest $request): JsonResponse
    {
        $this->service->saveRegisterLimits($this->getAgentContext()->agent, $request->integer('group_id'), $request->input('limits'));

        return ApiResponse::success();
    }
}
