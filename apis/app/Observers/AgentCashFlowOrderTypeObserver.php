<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CashFlow\CashFlowScopeEnum;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\OrderType;

/**
 * 維護 acfot 的兩個 invariant：
 *
 * 1. cash_flow 對齊：acf.cash_flow_id 必須等於 ot.cash_flow_id。
 *    只在 APIs 範疇（cf > 62）強制；舊系統 (cf<=62) 已有歷史殘留資料，
 *    由舊系統流程自負其責，本 observer 不擋。
 * 2. 唯一 live 連結：同 (agent_cash_flow_id, order_type_id) 至多一筆未軟刪列
 *    （啟停以 status 開關，不以第二列表達）。終防線在 DB 層：is_live generated column
 *    ＋ uq_acfot_live_link unique index（蓋併發 race／restore()／bulk update 全路徑），
 *    本 guard 僅為 Eloquent 路徑的友善錯誤訊息層。2026-08-07 六站盤點皆零重複。
 *    同群組跨 ACF（同金流商多商戶號）掛同一 order_type 屬合法配置，不在此限——
 *    前端查詢對該情境取最早建立（最小 id）；舊查詢無排序、重複時行為未定義，
 *    此為契約化的確定性規則，見 OrderTypeService::getOrderTypes。
 *
 * 觸發時機：
 * - creating：一律驗證
 * - updating：只在 agent_cash_flow_id / order_type_id 變動時重驗
 */
class AgentCashFlowOrderTypeObserver
{
    public function creating(AgentCashFlowOrderType $acfot): void
    {
        $this->assertCashFlowAligned($acfot->agent_cash_flow_id, $acfot->order_type_id);
        $this->assertNoDuplicateLiveLink($acfot);
    }

    public function updating(AgentCashFlowOrderType $acfot): void
    {
        if (!$acfot->isDirty(['agent_cash_flow_id', 'order_type_id'])) {
            return;
        }

        $this->assertCashFlowAligned($acfot->agent_cash_flow_id, $acfot->order_type_id);
        $this->assertNoDuplicateLiveLink($acfot);
    }

    private function assertCashFlowAligned(int $agentCashFlowId, int $orderTypeId): void
    {
        $acfCf = AgentCashFlow::query()->where('id', $agentCashFlowId)->value('cash_flow_id');
        if ($acfCf === null) {
            throw new ApiException(sprintf(
                'agent_cash_flow_order_types invariant violated: agent_cash_flow not found (acf_id=%d)',
                $agentCashFlowId,
            ));
        }

        if ($this->isLegacyScope((int) $acfCf)) {
            return;
        }

        $otCf = OrderType::query()->where('id', $orderTypeId)->value('cash_flow_id');
        if ($otCf === null) {
            throw new ApiException(sprintf(
                'agent_cash_flow_order_types invariant violated: order_type not found (ot_id=%d)',
                $orderTypeId,
            ));
        }

        if ((int) $acfCf !== (int) $otCf) {
            throw new ApiException(sprintf(
                'agent_cash_flow_order_types invariant violated: cash_flow_id mismatch (acf_id=%d cf=%d, ot_id=%d cf=%d)',
                $agentCashFlowId,
                $acfCf,
                $orderTypeId,
                $otCf,
            ));
        }
    }

    /**
     * 同 (agent_cash_flow_id, order_type_id) 已有未軟刪列（不分 status）即拒絕，
     * 防止同渠道同支付方式出現第二筆設定列
     */
    private function assertNoDuplicateLiveLink(AgentCashFlowOrderType $acfot): void
    {
        $duplicated = AgentCashFlowOrderType::query()
            ->where('agent_cash_flow_id', $acfot->agent_cash_flow_id)
            ->where('order_type_id', $acfot->order_type_id)
            ->when($acfot->exists, fn ($q) => $q->whereKeyNot($acfot->getKey()))
            ->exists();

        if ($duplicated) {
            throw new ApiException(sprintf(
                'agent_cash_flow_order_types duplicate link rejected: (acf_id=%d, ot_id=%d) already has a live row; toggle its status instead. (同渠道同支付方式已存在設定列，請改 status 啟停)',
                $acfot->agent_cash_flow_id,
                $acfot->order_type_id,
            ));
        }
    }

    private function isLegacyScope(int $cashFlowId): bool
    {
        return $cashFlowId <= CashFlowScopeEnum::LEGACY_BOUNDARY_CASH_FLOW_ID;
    }
}
