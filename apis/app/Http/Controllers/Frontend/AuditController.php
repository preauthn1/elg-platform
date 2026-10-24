<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ClearFlowLimitApplication;
use App\Services\AgentService;
use App\Services\WalletService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\Redis;

#[Group('Audit (稽核流水)')]
class AuditController extends Controller
{
    public function __construct(
        protected WalletService $walletService,
        protected AgentService $agentService,
    ) {}

    /**
     * 申請解除稽核流水限制
     *
     * - 檢查代理商設定是否啟用多錢包模式。
     *      - 若啟用，檢查會員的遊戲錢包是否存在且餘額小於 1，否則丟出餘額錯誤異常。
     *      - 若未啟用，直接檢查會員主錢包餘額是否小於 1，否則丟出餘額錯誤異常。
     * - 檢查該會員是否已有申請中的解除稽核流水紀錄，若有則丟出已申請異常。
     */
    public function releaseApplication()
    {
        $userData = $this->getUserContext();

        $member = $userData->member;
        $wallets = $this->walletService->getMemberWallets($member);
        $agentSettings = $this->agentService->getAgentSettings($member->agent);
        $maxBalance = (float) ($agentSettings[AgentCustomizedSettingKeyEnum::RELEASE_APPLICATION_MAX_BALANCE] ?? 1);
        $wallet = $wallets->wallet;

        if ($wallet->balance >= $maxBalance) {
            throw new ApiException(__('param.balance_error'));
        }

        // 是否有待處理的申請
        $hasPendingApplication = ClearFlowLimitApplication::query()
            ->where('member_id', $member->id)
            ->where('status', 0)
            ->exists();

        if ($hasPendingApplication) {
            throw new ApiException(__('param.already_apply'));
        }

        // 建立新的申請
        ClearFlowLimitApplication::create([
            'member_id' => $member->id,
            'flow_limit_id' => 0,
            'status' => 0, // 狀態: 待審核
        ]);

        // 清除後台鈴鐺的待審數快取（舊系統 API_Agent 寫入、每代理一把、快取有效期 TTL 60 秒），
        // 讓新申請在下一輪輪詢即出現於通知，不必等快取自然過期（target/tickets#4158）
        foreach (Redis::connection('json')->keys('*notice:removeFlowLimitRequest:*') as $cacheKey) {
            Redis::connection('json')->del($cacheKey);
        }

        return ApiResponse::success();
    }
}
