<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\Agent\AgentPersonalSettingKeyEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\DomainRequest;
use App\Http\Requests\Frontend\Shareholder\ApplicationRequest;
use App\Models\AgentDomain;
use App\Services\AgentService;
use App\Services\ShareHolderService;
use Illuminate\Support\Facades\DB;
use Throwable;

class ShareholderController extends Controller
{
    public function __construct(
        private AgentService $agentService,
        private ShareHolderService $shareHolderService
    ) {}

    /**
     * 會員申請成為代理(股東) #2418
     */
    public function apply(ApplicationRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent;

        $agentSettings = $this->agentService->getAgentSettings($agent);

        /**
         * 1. 是否開放會員申請成為代理
         * 2. 檢查上級代理是否是透過會員申請
         * 3. 檢查會員是否已經提出申請
         * 4. 判斷上級代理股份是否低於 "股份門檻" 上限
         */
        $this->shareHolderService->applyCheck($member, $agentSettings);

        try {
            DB::beginTransaction();

            /** 替會員註冊代理帳號 */
            $agent = $this->shareHolderService->registerAgent($member, $input);

            /** 為代理分配必要的後台菜單權限 */
            $this->shareHolderService->assignAgentPermission($agent);

            /** 建立申請紀錄 */
            $this->shareHolderService->createShareholderApplication($member, $agent);

            /** 取得最小股份 */
            $sharePoint = $this->shareHolderService->getMinSharePointByAgentGroupId($agent);

            /** 寫入股份 */
            $this->agentService->setAgentPersonalSetting(
                agent: $agent,
                settingKey: AgentPersonalSettingKeyEnum::SHAREHOLDER_POINT,
                value: $sharePoint
            );

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return ApiResponse::success();
    }

    /**
     * 股東分潤（免登入，依 domain 判定品牌）
     *
     * - 回傳最近一次已派發的分潤日期與金額
     *
     * OLD: /api/shareholder/profit
     */
    public function getProfit(DomainRequest $request)
    {
        $input = $request->validated();

        $agentDomain = AgentDomain::with('agent')
            ->where('domain', $input['domain'])
            ->where('status', 1)
            ->first();

        // 查無 domain 時明確回錯；舊系統 API_Frontend 的寫法會直接讀 null 屬性，在 PHP 8.4 下會致命錯誤
        if (!$agentDomain?->agent || $agentDomain->agent->status != 1) {
            throw new ApiException(__('error_message.1005'));
        }

        return ApiResponse::success($this->shareHolderService->getProfit($agentDomain->agent));
    }
}
