<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Report\AgentDashboardDetailRequest;
use App\Http\Requests\Agent\Report\AgentDashboardListRequest;
use App\Http\Requests\Agent\Report\AgentDashboardRequest;
use App\Services\Agent\AgentDashboardDetailService;
use App\Services\Agent\AgentDashboardService;
use Dedoc\Scramble\Attributes\Group;

/**
 * 代理數據看板（target/tickets#4180 第一階段）。
 *
 * 三支端點皆回「原始量」，推導值（各種率、人均、NGR、貢獻佔比）由前端計算：
 * 成本開關（支付手續費 / 遊戲商盤費 / 簡訊費）純前端勾選，切換不需重打 API。
 *
 * @see docs/代理數據看板/api-plan.md
 */
#[Group('報表 (代理數據看板)')]
class AgentDashboardController extends Controller
{
    public function __construct(
        private AgentDashboardService $service,
        private AgentDashboardDetailService $detailService,
    ) {}

    /**
     * 指標總覽
     *
     * 25 項非留存指標的原始量與六項成本分項；`scope_total` 為貢獻佔比的分母
     * （＝登入者可視範疇的同期合計，平台端登入時才等於全站）。
     */
    public function overview(AgentDashboardRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $input = $request->validated();

        $target = $this->service->resolveTargetAgent($agent, $input['agent_username'] ?? null);

        if ($target === null) {
            return ApiResponse::error('查無此代理');
        }

        return ApiResponse::success($this->service->overview($agent, $target, $input));
    }

    /**
     * 留存矩陣
     *
     * 以期間內的註冊批次為分母，往後判定 D1 / D7 / D30 當天的登入 / 投注 / 充值行為。
     * 未到期批次照算（命中數為 0），故查近 7 天時 D30 通常整排是 0。
     */
    public function retention(AgentDashboardRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $input = $request->validated();

        $target = $this->service->resolveTargetAgent($agent, $input['agent_username'] ?? null);

        if ($target === null) {
            return ApiResponse::error('查無此代理');
        }

        return ApiResponse::success($this->service->retention($agent, $target, $input));
    }

    /**
     * 代理明細列表
     *
     * 逐層展開：`parent_agent_id` 空＝查詢對象的直屬下級，帶值＝該代理的下一層。
     * 同一層皆為兄弟節點，故本頁加總不會父子重複計算。
     */
    public function agentList(AgentDashboardListRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $input = $request->validated();

        $target = $this->service->resolveTargetAgent($agent, $input['agent_username'] ?? null);

        if ($target === null) {
            return ApiResponse::error('查無此代理');
        }

        return ApiResponse::success($this->service->agentList($agent, $target, $input));
    }

    /**
     * 指標明細下鑽
     *
     * 一列＝一位會員的期間彙總（同一會員只出現一次，沒有逐筆交易列）。欄位由 `group` 決定，
     * 同組的指標格子點下去開同一張表；`columns` 由後端回，前端照著渲染表頭。
     * 時間欄回「使用者所選時區」的牆鐘，前端不需再平移。
     */
    public function detail(AgentDashboardDetailRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $input = $request->validated();

        $target = $this->service->resolveTargetAgent($agent, $input['agent_username'] ?? null);

        if ($target === null) {
            return ApiResponse::error('查無此代理');
        }

        return ApiResponse::success($this->detailService->list($agent, $target, $input));
    }

    /**
     * 指標明細匯出
     *
     * 與 `detail` 同一份名單與欄位，但一次回全部（上限 10 萬列）且不算總筆數、不做加總——
     * 匯出檔只要資料列，省掉的那三段查詢在全站範圍是十幾秒的差距。回傳的 `limit` 給前端
     * 判斷有沒有被截斷（列數等於上限即代表還有）。
     */
    public function detailExport(AgentDashboardDetailRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $input = $request->validated();

        $target = $this->service->resolveTargetAgent($agent, $input['agent_username'] ?? null);

        if ($target === null) {
            return ApiResponse::error('查無此代理');
        }

        return ApiResponse::success($this->detailService->export($agent, $target, $input));
    }
}
