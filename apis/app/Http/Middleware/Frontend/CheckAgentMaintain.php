<?php

declare(strict_types=1);

namespace App\Http\Middleware\Frontend;

use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Helpers\ApiResponse;
use App\Services\AgentService;
use App\Services\MemberAccessTokenService;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * 檢查代理維護狀態中間件（Frontend 路由中間件）
 *
 * 此中間件用於檢查代理是否處於維護狀態，僅掛載於 Frontend 路由。
 * - 訪客：由 HasDomain 中間件設置代理上下文
 * - 登入用戶：由 AuthMember 中間件設置代理上下文
 *
 * 處理邏輯：
 * 1. 如果 $agent = null（無法識別代理，如 TgBot 無 domain 請求），直接放行
 * 2. 如果代理在維護中且為登入用戶，先將 token 設為過期（強制登出）
 * 3. 返回 HTTP 317 及維護時間
 */
class CheckAgentMaintain
{
    public function __construct(
        private MemberAccessTokenService $memberAccessTokenService,
    ) {}

    /**
     * 處理傳入的請求
     *
     * 執行流程：
     * 1. 從上下文中獲取代理資訊（由 HasDomain 或 AuthMember 等前置中間件設置）
     * 2. 如果 $agent 為 null（無法識別代理），直接放行——無法確定要檢查的代理
     * 3. 獲取當前所有維護中的代理列表
     * 4. 檢查代理層級中是否有維護中的代理，並確認當前代理不在排除列表中
     * 5. 若在維護狀態且未被排除：
     *    - 如果是登入用戶（有 token），將 token 設為過期（強制登出）
     *    - 返回 HTTP 317 及維護時間
     *
     * @param  Request  $request  傳入的請求
     * @param  Closure  $next  下一個中間件
     * @return Response 響應結果
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 從上下文中獲取代理資訊
        // 訪客：由 HasDomain 中間件透過 domain 參數設置
        // 登入用戶：由 AuthMember 中間件透過 bearer token 設置
        $agent = Context::getHidden(ContextKeyEnum::AGENT->value);

        // 如果 $agent 為 null，屬於不應該存在的情境，直接阻擋
        // 如果 $agent 為 null，代表請求未攜帶可識別代理的參數（如 TgBot 無 domain 請求）
        // 無法確定要檢查的代理，直接放行
        if ($agent === null) {
            return $next($request);
        }

        // 獲取所有維護中的代理資訊（含排除列表、開始/結束時間）
        $maintainAgents = app(AgentService::class)->getMaintainAgents();

        // 若沒有任何代理在維護中，直接通過
        if ($maintainAgents->isEmpty()) {
            return $next($request);
        }

        // 檢查當前代理的層級（hierarchy_array）中是否有維護中的代理
        // 需逐一檢查每個維護代理及其排除列表
        foreach ($agent->hierarchy_array as $hierarchyAgentId) {
            // 找到該階層代理對應的維護設定
            $maintainAgent = $maintainAgents->firstWhere('agent_id', $hierarchyAgentId);

            // 如果該階層代理不在維護中，繼續檢查下一個
            if (!$maintainAgent) {
                continue;
            }

            // 解析該維護代理的排除列表
            $excludedAgentIds = array_filter(
                array_map('intval', explode(',', $maintainAgent->exclude_agents ?? ''))
            );

            // 如果當前代理在該維護代理的排除列表中，繼續檢查下一個
            if (in_array($agent->id, $excludedAgentIds)) {
                continue;
            }

            // 當前代理受該維護代理影響，返回維護提示

            // 如果是登入用戶，需先作廢所有 access token（強制登出）
            $member = Context::getHidden(ContextKeyEnum::MEMBER->value);
            if ($member) {
                $this->memberAccessTokenService->revoke($member->id);
            }

            // 將 UTC 時間轉換為 Asia/Taipei (UTC+8) 用於顯示
            $startTime = Carbon::parse($maintainAgent->start_time, 'UTC')
                ->timezone('Asia/Taipei')
                ->format('Y-m-d H:i:s');
            $endTime = Carbon::parse($maintainAgent->end_time, 'UTC')
                ->timezone('Asia/Taipei')
                ->format('Y-m-d H:i:s');

            // 返回維護狀態及時間（比照 LoginController::getAgentMaintenanceErrorMessage 格式）
            return ApiResponse::error(
                message: "$startTime ~ $endTime (UTC+08:00)",
                code: HttpCode::HTTP_AGENT_MAINTAIN->value,
            );
        }

        return $next($request);
    }
}
