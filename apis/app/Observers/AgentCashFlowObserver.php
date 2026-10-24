<?php

declare(strict_types=1);

namespace App\Observers;

use App\Exceptions\ApiException;
use App\Models\AcfCascadeDeactivateLog;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use Illuminate\Support\Facades\DB;

/**
 * ACF 下架時連動關閉旗下 ACFOT，避免留下「ACF 已停用、關聯仍 active」的孤兒。
 *
 * cash_flow_type 繼承（便利欄，權威=cash_flows.type）：
 * - creating：一律以所屬 CF 的 type 覆寫（衍生欄不接受外部指定值）；CF 不存在（懸空
 *   cash_flow_id）時留 NULL 不拋錯。
 * - updating：僅在 cash_flow_id 變更（provider 版本搬遷型操作）時重新繼承。
 *
 * 觸發時機刻意不對稱：
 * - status 1→0：連動把旗下 status=1 的 ACFOT 關掉，每筆寫 {@see AcfCascadeDeactivateLog} 審計。
 * - status 0→1：**不**自動把 ACFOT 開回。連動開回會一併打開營運刻意個別停用的渠道，
 *   還原改為依審計 log 的顯式操作（見還原命令）。
 * - 已是 status=0 的 ACFOT 不寫 log，否則還原時會誤開營運自行停用的渠道。
 *
 * 軟刪除（SoftDeletes，入口 fix:acf-delete）：
 * - deleting：五張業務事實表任一有引用即拋錯擋下——有史料的是真配置，不是該刪的重複列。
 * - deleted：連動軟刪旗下**全部**存活 ACFOT（status 原樣保留＝還原後即回到刪除前狀態），
 *   ACF 自身 status 歸 0（舊系統只認 status、不認識 deleted_at 的保險），逐筆寫 soft_delete 審計。
 * - restored：只復原本次連動軟刪的 ACFOT（依審計 log 閉環），ACF status 維持 0 由人工顯式啟用。
 *
 * 覆蓋範圍限 Eloquent 路徑；raw SQL／其他系統直改 DB 攔不到，由排程孤兒報告接住。
 */
class AgentCashFlowObserver
{
    public function creating(AgentCashFlow $agentCashFlow): void
    {
        $agentCashFlow->cash_flow_type = $this->deriveCashFlowType($agentCashFlow);
    }

    public function updating(AgentCashFlow $agentCashFlow): void
    {
        if ($agentCashFlow->isDirty('cash_flow_id')) {
            $agentCashFlow->cash_flow_type = $this->deriveCashFlowType($agentCashFlow);
        }
    }

    public function updated(AgentCashFlow $agentCashFlow): void
    {
        if (!$agentCashFlow->wasChanged('status')) {
            return;
        }

        // 僅 active → inactive 觸發；重啟（0→1）不連動
        if ((int) $agentCashFlow->status !== 0 || (int) $agentCashFlow->getOriginal('status') === 0) {
            return;
        }

        $this->cascadeDeactivateOrderTypes($agentCashFlow);
    }

    /**
     * 擋下有業務足跡的 ACF 刪除
     *
     * orders / order_histories / withdrawal_payout_records / ota_orders /
     * summary_profit_share_payment_costs 皆以 agent_cash_flow_id 引用本表，DB 層無 FK：
     * 刪除會讓歷史引用斷裂（對帳／客服查單受害）。即使是軟刪除，引用經預設查詢也會解析失敗。
     * 需要「停用」時請改 status=0（本 observer 會連動關閉旗下 ACFOT）。
     *
     * @throws ApiException 任一事實表有引用時
     */
    public function deleting(AgentCashFlow $agentCashFlow): void
    {
        $references = $agentCashFlow->businessReferenceCounts();

        if ($references === []) {
            return;
        }

        throw new ApiException(sprintf(
            'AgentCashFlow %d is referenced by business records (%s) and cannot be deleted; set status=0 to deactivate instead. (存在訂單／業務引用，禁止刪除，請改為停用)',
            $agentCashFlow->id,
            collect($references)
                ->map(fn (int $count, string $table): string => sprintf('%s:%d', $table, $count))
                ->implode(', '),
        ));
    }

    /**
     * 軟刪 ACF 時連動軟刪旗下全部存活 ACFOT，並寫審計供還原
     *
     * ACFOT 的 status 原樣保留（deleted_at 才是刪除語意），還原時免重放狀態；
     * ACF 自身 status 以 query builder 歸 0（不觸發 updated 連動）——舊系統查詢
     * 只過濾 status=1、不認識 deleted_at，歸 0 讓軟刪列對舊系統同樣失效。
     */
    public function deleted(AgentCashFlow $agentCashFlow): void
    {
        if ($agentCashFlow->isForceDeleting()) {
            return;
        }

        DB::transaction(function () use ($agentCashFlow): void {
            $now = now();
            $connection = (string) config('database.default');
            $acfFromStatus = (int) $agentCashFlow->status;

            $liveLinks = AgentCashFlowOrderType::query()
                ->where('agent_cash_flow_id', $agentCashFlow->id)
                ->lockForUpdate()
                ->get(['id', 'order_type_id', 'status']);

            if ($liveLinks->isNotEmpty()) {
                AgentCashFlowOrderType::query()
                    ->whereIn('id', $liveLinks->pluck('id')->all())
                    ->update(['deleted_at' => $now]);
            }

            AgentCashFlow::withTrashed()
                ->where('id', $agentCashFlow->id)
                ->update(['status' => 0]);

            $logs = $liveLinks->map(fn (AgentCashFlowOrderType $link): array => [
                'connection' => $connection,
                'agent_cash_flow_id' => $agentCashFlow->id,
                'agent_cash_flow_order_type_id' => $link->id,
                'order_type_id' => $link->order_type_id,
                'action' => AcfCascadeDeactivateLog::ACTION_SOFT_DELETE,
                'from_status' => (int) $link->status,
                'source' => AcfCascadeDeactivateLog::SOURCE_OBSERVER,
                'detail' => sprintf('AgentCashFlow %d 軟刪除連動', $agentCashFlow->id),
                'created_at' => $now,
            ])->all();

            // ACF 自身一列（acfot_id=0）：記錄刪除前 status 供追查
            $logs[] = [
                'connection' => $connection,
                'agent_cash_flow_id' => $agentCashFlow->id,
                'agent_cash_flow_order_type_id' => 0,
                'order_type_id' => 0,
                'action' => AcfCascadeDeactivateLog::ACTION_SOFT_DELETE,
                'from_status' => $acfFromStatus,
                'source' => AcfCascadeDeactivateLog::SOURCE_OBSERVER,
                'detail' => sprintf('AgentCashFlow %d 軟刪除（status %d→0）', $agentCashFlow->id, $acfFromStatus),
                'created_at' => $now,
            ];

            AcfCascadeDeactivateLog::insert($logs);
        });
    }

    /**
     * 還原軟刪的 ACF 時，依審計 log 只復原當初一併連動軟刪的 ACFOT
     *
     * 獨立軟刪（非本次連動、無 pending log）的 ACFOT 不會被誤救；
     * 還原後回填 restored_by_log_id 閉環，重複還原不再命中。
     * ACF status 維持 0（刪除時已歸 0），確認無誤後由人工顯式啟用。
     */
    public function restored(AgentCashFlow $agentCashFlow): void
    {
        DB::transaction(function () use ($agentCashFlow): void {
            $now = now();
            $connection = (string) config('database.default');

            $pending = AcfCascadeDeactivateLog::query()
                ->where('agent_cash_flow_id', $agentCashFlow->id)
                ->where('action', AcfCascadeDeactivateLog::ACTION_SOFT_DELETE)
                ->whereNull('restored_by_log_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($pending as $log) {
                if ((int) $log->agent_cash_flow_order_type_id !== 0) {
                    AgentCashFlowOrderType::withTrashed()
                        ->where('id', $log->agent_cash_flow_order_type_id)
                        ->update(['deleted_at' => null]);
                }

                $restoreLog = AcfCascadeDeactivateLog::create([
                    'connection' => $connection,
                    'agent_cash_flow_id' => $agentCashFlow->id,
                    'agent_cash_flow_order_type_id' => $log->agent_cash_flow_order_type_id,
                    'order_type_id' => $log->order_type_id,
                    'action' => AcfCascadeDeactivateLog::ACTION_RESTORE_DELETED,
                    'from_status' => (int) $log->from_status,
                    'source' => AcfCascadeDeactivateLog::SOURCE_OBSERVER,
                    'detail' => sprintf('依 log %d 還原軟刪', $log->id),
                    'created_at' => $now,
                ]);

                $log->update(['restored_by_log_id' => $restoreLog->id]);
            }
        });
    }

    /**
     * 自所屬 CF 取 type 供繼承；CF 不存在（懸空 cash_flow_id）回 NULL
     */
    private function deriveCashFlowType(AgentCashFlow $agentCashFlow): ?int
    {
        $type = CashFlow::query()->whereKey($agentCashFlow->cash_flow_id)->value('type');

        return $type === null ? null : (int) $type;
    }

    /**
     * 連動關閉旗下 active ACFOT 並寫審計
     */
    private function cascadeDeactivateOrderTypes(AgentCashFlow $agentCashFlow): void
    {
        DB::transaction(function () use ($agentCashFlow): void {
            $activeLinks = AgentCashFlowOrderType::query()
                ->where('agent_cash_flow_id', $agentCashFlow->id)
                ->where('status', 1)
                ->lockForUpdate()
                ->get(['id', 'order_type_id', 'status']);

            if ($activeLinks->isEmpty()) {
                return;
            }

            AgentCashFlowOrderType::query()
                ->whereIn('id', $activeLinks->pluck('id')->all())
                ->update(['status' => 0]);

            $now = now();
            $connection = (string) config('database.default');

            AcfCascadeDeactivateLog::insert(
                $activeLinks->map(fn (AgentCashFlowOrderType $link): array => [
                    'connection' => $connection,
                    'agent_cash_flow_id' => $agentCashFlow->id,
                    'agent_cash_flow_order_type_id' => $link->id,
                    'order_type_id' => $link->order_type_id,
                    'action' => AcfCascadeDeactivateLog::ACTION_CASCADE_DEACTIVATE,
                    'from_status' => (int) $link->status,
                    'source' => AcfCascadeDeactivateLog::SOURCE_OBSERVER,
                    'detail' => sprintf('AgentCashFlow %d status 1→0 連動下架', $agentCashFlow->id),
                    'created_at' => $now,
                ])->all(),
            );
        });
    }
}
