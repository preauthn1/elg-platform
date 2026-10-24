<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\NewbieChallenge\AllowJoinRequest;
use App\Http\Requests\Agent\NewbieChallenge\BlockDetailRequest;
use App\Http\Requests\Agent\NewbieChallenge\BlockListRequest;
use App\Http\Requests\Agent\NewbieChallenge\ConfigCreateRequest;
use App\Http\Requests\Agent\NewbieChallenge\ConfigDetailRequest;
use App\Http\Requests\Agent\NewbieChallenge\ConfigListRequest;
use App\Http\Requests\Agent\NewbieChallenge\ConfigUpdateRequest;
use App\Http\Requests\Agent\NewbieChallenge\ConfigUpdateStatusRequest;
use App\Http\Requests\Agent\NewbieChallenge\OperationLogListRequest;
use App\Http\Requests\Agent\NewbieChallenge\ProgressDetailRequest;
use App\Http\Requests\Agent\NewbieChallenge\ProgressListRequest;
use App\Http\Requests\Agent\NewbieChallenge\QuitRequest;
use App\Http\Requests\Agent\NewbieChallenge\UpdateWalletBalanceRequest;
use App\Http\Resources\Agent\NewbieChallenge\BlockDetailResource;
use App\Http\Resources\Agent\NewbieChallenge\BlockListResource;
use App\Http\Resources\Agent\NewbieChallenge\ConfigDetailResource;
use App\Http\Resources\Agent\NewbieChallenge\ConfigListResource;
use App\Http\Resources\Agent\NewbieChallenge\MonitorResource;
use App\Http\Resources\Agent\NewbieChallenge\OperationLogListResource;
use App\Http\Resources\Agent\NewbieChallenge\ProgressDetailResource;
use App\Http\Resources\Agent\NewbieChallenge\ProgressListResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\NewbieChallengeAgentService;
use Dedoc\Scramble\Attributes\Group;

#[Group('NewbieChallenge (新人專享挑戰活動-代理後台)')]
class NewbieChallengeController extends Controller
{
    public function __construct(
        private NewbieChallengeAgentService $service,
    ) {}

    /**
     * 活動設定列表
     */
    public function configList(ConfigListRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $paginator = $this->service->configList(
            agent: $agent,
            filters: $request->only(['agent_username', 'status']),
            page: (int) $request->input('page'),
            perPage: (int) $request->input('per_page'),
        );

        return ApiResponse::successWithPagination(
            data: ConfigListResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }

    /**
     * 活動設定詳情
     */
    public function configDetail(ConfigDetailRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $config = $this->service->configDetail($agent, (int) $request->input('config_id'));

        return ApiResponse::success(ConfigDetailResource::make($config));
    }

    /**
     * 新增活動設定
     */
    public function configCreate(ConfigCreateRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $config = $this->service->configCreate(
            $agent,
            $request->only([
                'agent_id', 'activity_amount', 'challenge_flow_multiple', 'bonus_flow_multiple',
                'min_unlock_amount', 'max_unlock_amount', 'bonus_window_hours',
                'allowed_vendor_ids', 'allowed_game_type_ids', 'excluded_vendor_ids',
                'bonus_flow_vendor_ids', 'bonus_flow_game_type_ids', 'excluded_bonus_flow_vendor_ids',
                'ip_block', 'phone_block', 'account_block',
                'restrict_new_member', 'sort', 'status', 'start_time', 'end_time',
            ]),
            $request->input('config_lang', []),
            $request->input('exclude_agent', []),
        );

        return ApiResponse::success(ConfigDetailResource::make($config));
    }

    /**
     * 更新活動設定
     */
    public function configUpdate(ConfigUpdateRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $config = $this->service->configUpdate(
            $agent,
            (int) $request->input('config_id'),
            $request->only([
                'agent_id', 'activity_amount', 'challenge_flow_multiple', 'bonus_flow_multiple',
                'min_unlock_amount', 'max_unlock_amount', 'bonus_window_hours',
                'allowed_vendor_ids', 'allowed_game_type_ids', 'excluded_vendor_ids',
                'bonus_flow_vendor_ids', 'bonus_flow_game_type_ids', 'excluded_bonus_flow_vendor_ids',
                'ip_block', 'phone_block', 'account_block',
                'restrict_new_member', 'sort', 'status', 'start_time', 'end_time',
            ]),
            $request->input('config_lang', []),
            $request->input('exclude_agent', []),
        );

        return ApiResponse::success(ConfigDetailResource::make($config));
    }

    /**
     * 切換活動設定啟用/停用
     */
    public function configUpdateStatus(ConfigUpdateStatusRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $this->service->configUpdateStatus(
            $agent,
            (int) $request->input('config_id'),
            (int) $request->input('status'),
        );

        return ApiResponse::success();
    }

    /**
     * 參加紀錄列表
     */
    public function progressList(ProgressListRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $paginator = $this->service->progressList(
            agent: $agent,
            filters: $request->only(['stage', 'member_username', 'agent_username', 'start_time', 'end_time']),
            page: (int) $request->input('page'),
            perPage: (int) $request->input('per_page'),
        );

        return ApiResponse::successWithPagination(
            data: ProgressListResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }

    /**
     * 參加紀錄詳情
     */
    public function progressDetail(ProgressDetailRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $progress = $this->service->progressDetail($agent, (int) $request->input('progress_id'));

        return ApiResponse::success(ProgressDetailResource::make($progress));
    }

    /**
     * 後台調整會員活動錢包餘額（WalletEnum::CODE_EVENT_WALLET，Event V2 與新人挑戰共用同一顆錢包）
     */
    public function updateWalletBalance(UpdateWalletBalanceRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $this->service->updateWalletBalance(
            $agent,
            (int) $request->input('progress_id'),
            (string) $request->input('balance'),
        );

        return ApiResponse::success();
    }

    /**
     * 代理後台強制取消會員參加中的挑戰
     */
    public function quit(QuitRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $this->service->quit($agent, (int) $request->input('progress_id'));

        return ApiResponse::success();
    }

    /**
     * 重複阻擋名單（IP/手機/真實姓名）
     */
    public function blockList(BlockListRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $paginator = $this->service->blockList(
            agent: $agent,
            filters: $request->only(['status', 'member_username', 'agent_username', 'start_time', 'end_time']),
            page: (int) $request->input('page'),
            perPage: (int) $request->input('per_page'),
        );

        return ApiResponse::successWithPagination(
            data: BlockListResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }

    /**
     * 單一阻擋名單的完整觸發歷史
     */
    public function blockDetail(BlockDetailRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $data = $this->service->blockDetail($agent, (int) $request->input('block_join_id'));

        return ApiResponse::success(BlockDetailResource::collection($data));
    }

    /**
     * 後台放行：讓被重複阻擋的玩家下次可以直接參加，一次性豁免
     */
    public function allowJoin(AllowJoinRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $this->service->allowJoin($agent, (int) $request->input('block_join_id'));

        return ApiResponse::success();
    }

    /**
     * 操作日誌列表
     */
    public function operationLogList(OperationLogListRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $paginator = $this->service->operationLogList(
            agent: $agent,
            filters: $request->only(['progress_id', 'member_username', 'agent_username', 'start_time', 'end_time']),
            page: (int) $request->input('page'),
            perPage: (int) $request->input('per_page'),
        );

        return ApiResponse::successWithPagination(
            data: OperationLogListResource::collection($paginator),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }

    /**
     * 監控頁：依狀態分組的即時彙總
     */
    public function monitor()
    {
        $agent = $this->getAgentContext()->agent;

        $data = $this->service->monitor($agent);

        return ApiResponse::success(MonitorResource::collection($data));
    }
}
