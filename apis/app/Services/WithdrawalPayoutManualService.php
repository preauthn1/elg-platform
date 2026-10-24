<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\AgentOperateLog\StatusChangeDebugInfo;
use App\Enums\Agent\AgentOperateReferenceTypeEnum;
use App\Enums\Agent\AgentOperateTypeEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Agent\AgentOperateLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 代付停留單後台人工定案（target/tickets#4137，承接自 API_Agent 後台按鈕）。
 *
 * - fillThirdPartyOrderNo：人工於支付商後台查到「有單」→ 填寫三方單號，21 → 22 回到等回調軌道
 * - markFailed：人工於支付商後台確認「查無此單」→ 21/22 → 29，可重新發起
 *
 * 守衛：
 * - 功能授權與代理範疇：由路由的 CheckAgentPermission 與 WithdrawalController::isOrderInScope()
 *   把關（target/tickets#4140 稽核 #9／#13），本 Service 不重複實作
 * - in-flight 保護窗：21 的 updated_at 距今未滿 3 分鐘＝原 HTTP 可能仍在途，拒絕操作
 *   （三方 HTTP timeout 上限約 60 秒；窗內轉 29 後原請求仍可能被支付商受理＝重複出款）
 * - 鎖序：fill 觸碰兩表，一律先鎖 record 再鎖 order（與回調路徑一致，防反向鎖序 deadlock）
 *
 * 29 已移出回調放行：誤標後不再有補送回調自動糾正的機會，呼叫端（後台按鈕）
 * 必須帶「須先於支付商後台確認查無此單」的二次確認文案。
 *
 * 兩個操作都在同一個交易內寫代理操作紀錄（agent_operate_logs）：狀態沒改成功就不會留下紀錄。
 */
class WithdrawalPayoutManualService
{
    /** 21 的 in-flight 保護窗（分鐘）：低於此時長視為原請求可能仍在途 */
    private const IN_FLIGHT_PROTECTION_MINUTES = 3;

    public function __construct(
        private readonly AgentOperateLogService $agentOperateLogService,
    ) {}

    public function fillThirdPartyOrderNo(int $orderId, string $thirdPartyOrderNo, Agent $operator, ?string $remark = null): void
    {
        DB::transaction(function () use ($orderId, $thirdPartyOrderNo, $operator, $remark): void {
            // 鎖序約定：先鎖最新 record、後鎖 order（與回調一致）。
            // 無 record＝HTTP 未送出＝支付商必然查無此單：拒絕補單（正確出口是 mark-failed）；
            // 三家雙條件對單商（GMPay/GffPay/MajesticPay）沒有 record 可回填，轉 22 只會等不到回調
            $record = WithdrawalPayoutRecord::query()
                ->where('withdrawal_order_id', $orderId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (!$record) {
                throw new ApiException('Payout record not found, confirm with provider and use mark-failed instead');
            }

            $order = $this->lockOrder($orderId);

            // 只有 21（代付請求中）可補單號；22 已有三方單號、其餘狀態不適用
            if ((int) $order->status !== WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value) {
                throw new ApiException('Order status not allowed for filling third party order no');
            }

            $this->assertNotInFlight($order);

            $previousStatus = (int) $order->status;

            $order->update([
                'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
                'third_party_order_no' => $thirdPartyOrderNo,
                // 清掉發起側暫存的 attempt token（人工補單情境無三方回應可放）
                'third_party_response' => null,
                ...$this->appendMemo($order, $remark),
            ]);

            // 同步回填最新一筆代付請求紀錄（雙欄回填，工單 C2）
            $record->update(['third_party_transaction_no' => $thirdPartyOrderNo]);

            $this->logOperation(
                type: AgentOperateTypeEnum::WITHDRAWAL_FILL_THIRD_PARTY_NO,
                order: $order,
                previousStatus: $previousStatus,
                operator: $operator,
                remark: $remark,
            );

            Log::warning('Withdrawal payout manually filled third party order no', [
                'order_id' => $orderId,
                'payout_record_id' => $record->id,
                'third_party_order_no' => $thirdPartyOrderNo,
                'operator' => $operator->username,
            ]);
        });

        $this->notify(sprintf(
            '代付訂單 %d 已由後台人工填寫三方單號轉代付處理中(22)，回到等回調軌道。操作者:%s',
            $orderId,
            $operator->username,
        ), $orderId);
    }

    public function markFailed(int $orderId, Agent $operator, ?string $remark = null): void
    {
        $previousStatus = null;

        DB::transaction(function () use ($orderId, $operator, $remark, &$previousStatus): void {
            $order = $this->lockOrder($orderId);

            $allowedStatuses = [
                WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value,
                WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            ];

            if (!in_array((int) $order->status, $allowedStatuses, true)) {
                throw new ApiException('Order status not allowed for marking payout failed');
            }

            if ((int) $order->status === WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value) {
                $this->assertNotInFlight($order);
            }

            $previousStatus = (int) $order->status;

            $order->update([
                'status' => WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value,
                ...$this->appendMemo($order, $remark),
            ]);

            $this->logOperation(
                type: AgentOperateTypeEnum::WITHDRAWAL_MARK_FAILED,
                order: $order,
                previousStatus: $previousStatus,
                operator: $operator,
                remark: $remark,
            );

            Log::warning('Withdrawal payout manually marked failed', [
                'order_id' => $orderId,
                'previous_status' => $previousStatus,
                'operator' => $operator->username,
            ]);
        });

        $this->notify(sprintf(
            '代付訂單 %d 已由後台人工由 %d 標記三方失敗(29)、開放重新發起。操作者:%s。29 不再接受補送回調，若支付商實際已出款，重新發起將導致重複出款',
            $orderId,
            $previousStatus,
            $operator->username,
        ), $orderId);
    }

    /**
     * 事務提交後送通知：資料已生效，通知失敗只記 log、不得使 API 呈現失敗
     * （操作者會誤判定案未生效而重做）。
     */
    private function notify(string $message, int $orderId): void
    {
        try {
            BotNotify::send($message);
        } catch (\Throwable $notifyError) {
            Log::error('Withdrawal manual resolve notification failed after commit', [
                'order_id' => $orderId,
                'error' => $notifyError->getMessage(),
            ]);
        }
    }

    /**
     * 操作備註寫入 memo 的 update 片段（比照舊 reviewOrder：memo 空則寫入、否則換行附加）。
     *
     * @return array{memo?: string}
     */
    private function appendMemo(WithdrawalOrder $order, ?string $remark): array
    {
        if ($remark === null || trim($remark) === '') {
            return [];
        }

        $existing = (string) ($order->memo ?? '');

        return ['memo' => $existing === '' ? $remark : $existing . PHP_EOL . $remark];
    }

    /**
     * 寫代理操作紀錄；改後狀態取訂單更新後的值，不另外傳入，避免與實際寫入的狀態不一致。
     */
    private function logOperation(
        AgentOperateTypeEnum $type,
        WithdrawalOrder $order,
        int $previousStatus,
        Agent $operator,
        ?string $remark,
    ): void {
        $this->agentOperateLogService->log(
            agentId: $operator->id,
            type: $type,
            debugInfo: new StatusChangeDebugInfo(
                oldStatus: $previousStatus,
                newStatus: (int) $order->status,
                remark: $remark,
            ),
            // 不用 Context 的 ip：那是 WEB 後台轉發機的 IP，非操作者真實 IP
            ip: Common::getAgentClientIp(),
            referenceId: (string) $order->id,
            referenceType: AgentOperateReferenceTypeEnum::WITHDRAWAL_ORDER,
        );
    }

    private function lockOrder(int $orderId): WithdrawalOrder
    {
        $order = WithdrawalOrder::query()->whereKey($orderId)->lockForUpdate()->first();

        if (!$order) {
            throw new ApiException('Withdrawal order not found');
        }

        return $order;
    }

    /**
     * 21 的 in-flight 保護窗：updated_at 距今未滿門檻＝原代付 HTTP 可能仍在途。
     * 此刻標 29 後原請求仍可能被支付商受理（第二階段有拉回 22 的補救，但不該倚賴補救）；
     * 補單號同理（record 可能尚未回填完畢）。
     */
    private function assertNotInFlight(WithdrawalOrder $order): void
    {
        if ($order->updated_at !== null
            && $order->updated_at->gt(now()->subMinutes(self::IN_FLIGHT_PROTECTION_MINUTES))) {
            throw new ApiException('Payout request may still be in flight, retry after a few minutes');
        }
    }
}
