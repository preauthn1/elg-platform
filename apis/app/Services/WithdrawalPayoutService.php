<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CashFlow\CashFlowScopeEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Helpers\Traits\GetContextTrait;
use App\Models\AgentCashFlow;
use App\Models\CustomerAuditTime;
use App\Models\TransactionLog;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 代付發起服務（target/tickets#4137 事務邊界拆分；業務自 WithdrawalController 下沉）。
 *
 * 第一階段短事務先提交 21 佔位（防重送責任由「長事務持鎖」轉移到「已提交的狀態 21」）；
 * 三方 HTTP 與請求紀錄留在事務外（pod 中斷不再回滾）；第二階段短事務條件寫入同步結果，
 * 讓路規則見 settleSyncResult()。
 *
 * @knowledge rules/payout-submit-and-status
 * @knowledge rules/provider-b3wallet
 */
class WithdrawalPayoutService
{
    use GetContextTrait;

    public function __construct(
        protected PaymentService $paymentService,
    ) {}

    /**
     * 發起三方代付。回傳 null＝成功（訂單 22 等回調）；string＝客服可讀錯誤。
     */
    public function submit(int $orderId, int $agentCashFlowId): ?string
    {
        $agentCashFlow = AgentCashFlow::query()
            ->where('id', $agentCashFlowId)
            ->whereRaw('JSON_VALID(info) = 1')
            ->with(['cashFlow', 'currency'])
            ->first();

        if (!$agentCashFlow) {
            throw new ApiException('Agent cash flow not found');
        }

        // 舊系統範疇的通道不受理：新代理後台以同一條分界決定打哪支 API，這裡是後端的最後一道
        if (!CashFlowScopeEnum::isHandledByApis((int) $agentCashFlow->cash_flow_id)) {
            return CashFlowScopeEnum::notHandledMessage((int) $agentCashFlow->cash_flow_id);
        }

        // ─── 第一階段短事務：鎖單 → 驗狀態(3/29) → 覆核渠道 → 寫 21 → 提交 ───
        [$errorMessage, $withdrawalOrder] = DB::transaction(function () use ($orderId, $agentCashFlow) {
            $withdrawalOrder = WithdrawalOrder::query()
                ->with(['agent', 'member', 'bank'])
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (!$withdrawalOrder) {
                return ['Order not found', null];
            }

            /**
             * 狀態檢查 - 只允許特定狀態發起代付請求
             *
             * 允許的狀態：
             * - 3 (APPROVED): 已審核通過，首次發起代付
             * - 29 (THIRD_PARTY_FAILED): 三方失敗，允許重試
             *
             * 其他所有狀態（包括 0, 1, 2, 4, 9, 21, 22, 24）都會被自動阻擋；
             * 21 不在集合內＝第一階段一經提交即擋住重複發起
             */
            $allowedStatuses = [
                WithdrawalOrderStatusEnum::APPROVED->value,
                WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value,
            ];

            if (!in_array($withdrawalOrder->status, $allowedStatuses, true)) {
                return ['Order status not allowed for payout request', null];
            }

            // 渠道覆核（缺陷 C）：送出前以 channels 同一條過濾鏈驗證提交的
            // ACF ∈ available 集合；不合格 fail-fast，訂單維持原狀態、不觸發三方請求
            $channelError = $this->validatePayoutChannel($withdrawalOrder, $agentCashFlow);
            if ($channelError !== null) {
                return [$channelError, null];
            }

            // attempt token：與 21 同一筆 UPDATE 原子寫入，作為本次發起的精確身分標識。
            // third_party_response 於 21 期間本無三方回應可放：受理時被真實回應覆寫、
            // 明確拒絕轉 29 時清空；重新發起會原子換上新 token，第二階段據此辨識
            // 「這個 21 是否仍屬本次發起」——不依賴秒級時間戳，同一秒內連續改狀態也不會誤判
            $withdrawalOrder->update([
                'status' => WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value,
                'third_party_response' => 'attempt:' . Str::uuid()->toString(),
            ]);

            return [null, $withdrawalOrder];
        });

        if ($errorMessage !== null) {
            return $errorMessage;
        }

        try {
            // attempt 錨點：本次三方請求建立的 record 必然 id > 此值；第二階段據此辨識
            // 「最新 record 是否仍屬本次發起」。防 ABA 問題（ABA problem：值走了 A→B→A
            // 一圈又變回原值，「值沒變」的檢查誤以為沒被動過）：本次發起在途期間，訂單被
            // 回調定為 29、又被重新發起回到 21——兩個 21 狀態值相同、實為兩次不同的發起，
            // 本次（較早）的遲到結果不得寫到新一次發起的訂單上
            $maxRecordIdBeforeAttempt = (int) WithdrawalPayoutRecord::query()
                ->where('withdrawal_order_id', $orderId)
                ->max('id');

            // ─── 事務之外：建請求紀錄＋送三方 HTTP（processPayout 內建 record → HTTP → 回填），
            //     寫入即生效，pod 中斷不再消失 ───
            $provider = $this->paymentService->getProvider($agentCashFlow);

            $params = WithdrawalRequestData::from([
                'order' => $withdrawalOrder,
                'owner' => $withdrawalOrder->owner,
            ]);

            /** @var WithdrawalResponseData $result */
            $result = $provider->withdrawal($params);

            return $this->settleSyncResult(
                $orderId,
                $agentCashFlowId,
                $result,
                $maxRecordIdBeforeAttempt,
                (string) $withdrawalOrder->third_party_response,
            );
        } catch (Exception $e) {
            Log::error('Payout flow failed after order submitted', [
                'order_id' => $orderId,
                'agent_cash_flow_id' => $agentCashFlowId,
                'error_message' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            // 21 已提交、後續流程例外：訂單停留 21 擋重送，告警交人工定案。
            // 通知失敗不得吞掉原始例外（停留 21 另有排程掃描兜底）
            try {
                BotNotify::send(sprintf(
                    '代付流程例外，訂單 %d 停留在代付請求中(21)鎖定重送。請至支付商後台確認是否出款：有單以後台「填寫三方單號」轉 22，查無此單以「修改為代付失敗」轉 29。原因:%s',
                    $orderId,
                    $e->getMessage(),
                ));
            } catch (\Throwable $notifyError) {
                Log::error('Payout stuck-21 notification failed', [
                    'order_id' => $orderId,
                    'error' => $notifyError->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * 解析訂單可用／不可用代付渠道（channels 查詢與 payout 覆核共用的單一事實來源）。
     * 回傳 string 時為客服可讀的錯誤原因。
     *
     * @return array{available: list<array>, unavailable: list<array>}|string
     */
    public function resolveChannels(WithdrawalOrder $order): array|string
    {
        $currencyId = $order->bank?->currency_id;

        if (!$currencyId) {
            return '無法取得訂單銀行的幣別資訊';
        }

        return $this->filterChannels(
            channels: $this->fetchPayoutChannels((int) $order->agent_group_id, (int) $currencyId),
            bankCode: (string) $order->bank_code,
            bankName: (string) $order->bank_name,
            amount: (string) $order->amount,
            currencyId: (int) $currencyId,
        );
    }

    /**
     * 第二階段短事務：條件寫入同步結果。
     *
     * 寫入條件：
     * - 訂單仍為 21 → 正常寫入（受理→22／明確拒絕→29／未明→停留 21＋告警）
     * - 訂單已為 29 且本次「受理」且最新 record 非回調判死 → 人工誤標，拉回 22＋強告警
     *   （29 已移出回調放行，誤標不再有補送回調自動糾正；受理是硬事實，必須擋住重新發起）
     * - 訂單已為 29 且最新 record 為 CALLBACK_FAILED(-3) → 三方失敗回調已定案，讓路
     * - 其餘（24 終態、22 已被按鈕定案）→ 讓路，受理結果僅告警不落單
     *
     * 通知一律於事務提交後送出：BotNotify 故障不得回滾狀態寫入（例：29 誤標拉回 22 的
     * 安全寫入若因通知失敗被回滾，訂單留在可重送的 29＝重複出款窗口）。
     */
    private function settleSyncResult(int $orderId, int $agentCashFlowId, WithdrawalResponseData $result, int $maxRecordIdBeforeAttempt, string $attemptToken): ?string
    {
        $notifications = [];

        $outcome = DB::transaction(function () use ($orderId, $agentCashFlowId, $result, $maxRecordIdBeforeAttempt, $attemptToken, &$notifications) {
            $lockedOrder = WithdrawalOrder::query()
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            $currentStatus = (int) $lockedOrder->status;
            $isAccepted = !$result->errorMessage;

            // 發起身分守衛（兩道，防 ABA，定義見 submit() 的 attempt 錨點註解）：
            // 本次發起的 record＝id 大於錨點的最早一筆。
            // ①若訂單已有更新的 record（新一次發起已建檔），本次（較早）的同步結果一律讓路——
            //   否則遲到的「明確拒絕」會把新發起的在途 21 洗成 29 再開重送窗口（重複出款），
            //   遲到的「受理」也會覆寫新發起的三方單號。
            // ②token 身分守衛封住「新一次發起已寫 21、尚未建 record」的窗口：第一階段把
            //   attempt token 與 21 原子寫入 third_party_response，重新發起必然換上新 token——
            //   訂單「當前為 21」但 token 非本次＝這個 21 已是新一次發起，讓路。
            //   精確等值比對、不依賴秒級時間戳（同一秒內連續改狀態也不會誤判）。
            //   ⚠️只限 21：29／24／22 各有自己的判別路徑（29 由 latestRecordIsCallbackFailed
            //   區分回調判死與人工誤標，不套 token 以免誤殺「29 誤標拉回 22」機制）。
            $attemptRecordId = WithdrawalPayoutRecord::query()
                ->where('withdrawal_order_id', $orderId)
                ->where('id', '>', $maxRecordIdBeforeAttempt)
                ->min('id');
            $latestRecordId = (int) WithdrawalPayoutRecord::query()
                ->where('withdrawal_order_id', $orderId)
                ->max('id');

            $isNewerAttemptPresent = $attemptRecordId !== null
                && $latestRecordId !== (int) $attemptRecordId;
            $isResubmittedAfterAttempt = $currentStatus === WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value
                && (string) $lockedOrder->third_party_response !== $attemptToken;

            $isStaleAttempt = $isNewerAttemptPresent || $isResubmittedAfterAttempt;

            if ($isStaleAttempt) {
                Log::warning('Payout sync result skipped, newer payout attempt exists', [
                    'order_id' => $orderId,
                    'attempt_record_id' => $attemptRecordId !== null ? (int) $attemptRecordId : null,
                    'latest_record_id' => $latestRecordId,
                    'token_mismatch' => $isResubmittedAfterAttempt,
                    'sync_success' => $isAccepted,
                ]);

                if ($isAccepted) {
                    $notifications[] = sprintf(
                        '代付訂單 %d 已有較新的代付請求，本次（較早）的三方受理結果（單號 %s）未寫入訂單，請人工核對兩邊一致性',
                        $orderId,
                        (string) ($result->providerOrderNo ?? ''),
                    );
                }

                return null;
            }
            $isSubmitted = $currentStatus === WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value;
            $isManualMisMarkedFailed = $isAccepted
                && $currentStatus === WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value
                && !$this->latestRecordIsCallbackFailed($orderId);

            if (!$isSubmitted && !$isManualMisMarkedFailed) {
                // 讓路：回調（24/29）或後台按鈕（22）已搶先定案
                Log::warning('Payout sync result skipped, order already resolved during request', [
                    'order_id' => $orderId,
                    'current_status' => $currentStatus,
                    'sync_success' => $isAccepted,
                ]);

                if ($isAccepted) {
                    $notifications[] = sprintf(
                        '代付訂單 %d 於三方請求在途期間已被定案（當前狀態 %d），三方受理結果（單號 %s）未寫入訂單，請人工核對兩邊一致性',
                        $orderId,
                        $currentStatus,
                        (string) ($result->providerOrderNo ?? ''),
                    );
                }

                return null;
            }

            if (!$isAccepted) {
                Log::error('Payout request failed', [
                    'order_id' => $orderId,
                    'agent_cash_flow_id' => $agentCashFlowId,
                    'error_message' => $result->errorMessage,
                    'is_indeterminate' => $result->isIndeterminate,
                ]);

                // 結果未知（逾時／連線例外）：停留 21 擋重送，告警交人工定案
                if ($result->isIndeterminate) {
                    $notifications[] = sprintf(
                        '代付請求結果未知（逾時／連線例外），訂單 %d 停留在代付請求中(21)鎖定重送。請至支付商後台確認是否出款：有單以後台「填寫三方單號」轉 22，查無此單以「修改為代付失敗」轉 29。原因:%s',
                        $orderId,
                        $result->errorMessage,
                    );

                    return 'Payout result unknown, order stays at payment submitted';
                }

                // 同步明確拒絕（provider interpretResult 白名單制，!1905 定案）＝確定失敗：
                // 一律轉 29（2026-08-17 決議，不再還原發起前狀態），可重新發起；
                // attempt token 已完成使命，清空（拒絕情境無三方回應可放）
                $lockedOrder->update([
                    'status' => WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value,
                    'third_party_response' => null,
                ]);

                return $result->errorMessage;
            }

            if ($isManualMisMarkedFailed) {
                $notifications[] = sprintf(
                    '代付訂單 %d 於三方請求在途期間遭人工標記失敗(29)，但支付商已受理本次請求，已拉回代付處理中(22)防止重複發起，請人工核對三方後台',
                    $orderId,
                );
            }

            // 支付商受理 → 22 等待回調
            $lockedOrder->update([
                'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
                'third_party_order_no' => $result->providerOrderNo ?? '',
                'third_party_response' => $this->formatThirdPartyResponse($result->providerResponse),
            ]);

            $this->updateOperatorLogs($orderId);

            return null;
        });

        // 事務已提交、狀態安全落地；通知失敗只記 log（停留 21 另有排程掃描兜底），
        // 不得使請求失敗——否則操作端誤以為發起失敗而重試
        foreach ($notifications as $message) {
            try {
                BotNotify::send($message);
            } catch (\Throwable $notifyError) {
                Log::error('Payout settle notification failed after commit', [
                    'order_id' => $orderId,
                    'error' => $notifyError->getMessage(),
                ]);
            }
        }

        return $outcome;
    }

    /**
     * 訂單最新一筆代付請求紀錄是否已被回調判死（CALLBACK_FAILED，-3）。
     * 用於區分第二階段遇到的 29 是「三方失敗回調定案」（讓路）還是「人工誤標」（拉回）。
     * 唯讀不加鎖：本方法在持有訂單鎖的事務內呼叫，不觸碰 record 鎖（鎖序約定 record→order，
     * 只讀不構成反向持鎖）。
     */
    private function latestRecordIsCallbackFailed(int $orderId): bool
    {
        $latestRecord = WithdrawalPayoutRecord::query()
            ->where('withdrawal_order_id', $orderId)
            ->orderByDesc('id')
            ->first();

        return $latestRecord !== null
            && $latestRecord->is_success_payout === WithdrawalPayoutStatusEnum::CALLBACK_FAILED;
    }

    /**
     * 代付渠道覆核（缺陷 C）：驗證提交的 ACF 在該訂單的可用渠道集合內。
     *
     * 複用 resolveChannels 的過濾鏈（單一事實來源），available 集合天然涵蓋：
     * agent_group 歸屬、ACF 啟用狀態、幣別匹配、銀行支援與金額上下限。
     * 不在集合時回傳客服可讀的具體原因；合格回傳 null。
     */
    private function validatePayoutChannel(WithdrawalOrder $order, AgentCashFlow $agentCashFlow): ?string
    {
        $result = $this->resolveChannels($order);

        if (is_string($result)) {
            return $result . '，禁止發起代付';
        }

        $availableIds = array_map('intval', array_column($result['available'], 'agent_cash_flow_id'));

        if (in_array((int) $agentCashFlow->id, $availableIds, true)) {
            return null;
        }

        $reason = collect($result['unavailable'])
            ->first(fn (array $row): bool => (int) $row['agent_cash_flow_id'] === (int) $agentCashFlow->id)['reason']
            ?? '渠道不屬於本代理群組、幣別不符或已停用';

        Log::warning('Payout channel validation rejected', [
            'order_id' => $order->id,
            'agent_cash_flow_id' => $agentCashFlow->id,
            'agent_group_id' => $order->agent_group_id,
            'currency_id' => $order->bank?->currency_id,
            'reason' => $reason,
        ]);

        return '代付渠道驗證未通過：' . $reason;
    }

    /**
     * 格式化三方回應資料
     */
    private function formatThirdPartyResponse(mixed $response): ?string
    {
        if (is_null($response)) {
            return null;
        }

        if (is_array($response)) {
            return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $response;
    }

    /**
     * 取得代付渠道列表（type=3, status=1，排除系統自動處理的渠道）
     */
    private function fetchPayoutChannels(int $cashFlowGroupId, int $currencyId): Collection
    {
        return AgentCashFlow::query()
            ->with('cashFlow')
            ->whereHas('cashFlow', function ($query) {
                $query->where('type', 3);
            })
            ->where('agent_group_id', $cashFlowGroupId)
            ->where('currency_id', $currencyId)
            ->where('status', 1)
            ->whereNotIn('cash_flow_id', [5, 6])
            ->get()
            ->map(fn (AgentCashFlow $acf) => (object) [
                'cash_flow_id' => $acf->cash_flow_id,
                'cash_flow_name' => $acf->cashFlow->name,
                'agent_cash_flow_id' => $acf->id,
                'agent_cash_flow_name' => $acf->info['DisplayName'] ?? $acf->name,
                'currency_id' => $acf->currency_id,
                'info' => $acf->info,
            ]);
    }

    /**
     * 將渠道分為可用（available）與不可用（unavailable）兩組
     *
     * 幣別不符的渠道（currency_id 有明確值且與訂單幣別不同）不納入任一組。
     *
     * @return array{available: list<array>, unavailable: list<array>}
     */
    private function filterChannels(Collection $channels, string $bankCode, string $bankName, string $amount, ?int $currencyId = null): array
    {
        $available = [];
        $unavailable = [];

        if ($channels->isEmpty()) {
            return ['available' => $available, 'unavailable' => $unavailable];
        }

        if (!Common::isNotEmptyString($bankCode)) {
            foreach ($channels as $channel) {
                // 排除幣別明確不符的渠道（currency_id = 0 視同未設定，不排除）
                if ($currencyId !== null && isset($channel->currency_id) && $channel->currency_id > 0 && $channel->currency_id !== $currencyId) {
                    continue;
                }

                $available[] = [
                    'cash_flow_id' => $channel->cash_flow_id,
                    'cash_flow_name' => $channel->cash_flow_name,
                    'agent_cash_flow_id' => $channel->agent_cash_flow_id,
                    'agent_cash_flow_name' => $channel->agent_cash_flow_name,
                ];
            }

            return ['available' => $available, 'unavailable' => $unavailable];
        }

        $normalizedAmount = Common::numAdd('0', $amount, 2);

        foreach ($channels as $channel) {
            // 排除幣別明確不符的渠道（不納入 available 也不納入 unavailable；currency_id = 0 視同未設定，不排除）
            if ($currencyId !== null && isset($channel->currency_id) && $channel->currency_id > 0 && $channel->currency_id !== $currencyId) {
                continue;
            }

            if (!$channel->info) {
                $unavailable[] = [
                    'cash_flow_id' => $channel->cash_flow_id,
                    'cash_flow_name' => $channel->cash_flow_name,
                    'agent_cash_flow_id' => $channel->agent_cash_flow_id,
                    'agent_cash_flow_name' => $channel->agent_cash_flow_name,
                    'reason' => '缺少渠道設定資訊',
                ];

                continue;
            }

            $reason = $this->resolveExcludeReason($channel->info, $bankCode, $bankName, $normalizedAmount);

            if ($reason !== null) {
                $unavailable[] = [
                    'cash_flow_id' => $channel->cash_flow_id,
                    'cash_flow_name' => $channel->cash_flow_name,
                    'agent_cash_flow_id' => $channel->agent_cash_flow_id,
                    'agent_cash_flow_name' => $channel->agent_cash_flow_name,
                    'reason' => $reason,
                ];
            } else {
                $available[] = [
                    'cash_flow_id' => $channel->cash_flow_id,
                    'cash_flow_name' => $channel->cash_flow_name,
                    'agent_cash_flow_id' => $channel->agent_cash_flow_id,
                    'agent_cash_flow_name' => $channel->agent_cash_flow_name,
                ];
            }
        }

        return ['available' => $available, 'unavailable' => $unavailable];
    }

    /**
     * 解析渠道被排除的原因，若符合條件則回傳 null
     */
    private function resolveExcludeReason(array $setting, string $targetBankCode, string $bankName, string $amount): ?string
    {
        if (!isset($setting['available_banks'])) {
            return '缺少 available_banks 設定';
        }

        if (!is_array($setting['available_banks'])) {
            return 'available_banks 格式錯誤';
        }

        if (isset($setting['min_amount'])) {
            $minAmount = Common::numAdd('0', (string) $setting['min_amount'], 2);
            if (Common::numCompare($minAmount, $amount, 2) === 1) {
                return sprintf('訂單金額 %s 低於最小限額 %s', $amount, $minAmount);
            }
        }

        if (isset($setting['max_amount'])) {
            $maxAmount = Common::numAdd('0', (string) $setting['max_amount'], 2);
            if (Common::numCompare($maxAmount, $amount, 2) === -1) {
                return sprintf('訂單金額 %s 超過最大限額 %s', $amount, $maxAmount);
            }
        }

        $supportedBankCodes = collect($setting['available_banks'])->pluck('BankCode_ELG');

        if (!$supportedBankCodes->contains($targetBankCode)) {
            return sprintf('不支援銀行 "%s" (%s)', $bankName, $targetBankCode);
        }

        return null;
    }

    /**
     * 更新操作記錄
     */
    private function updateOperatorLogs(string|int $orderId): void
    {
        $agentData = $this->getAgentContext();
        $agent = $agentData->agent;

        // 更新交易日誌
        TransactionLog::query()
            ->where('order_id', $orderId)
            ->where('type', 5)
            ->update([
                'operator' => $agent->username,
                'admin' => $agent->id,
            ]);

        // 更新審核時間記錄
        CustomerAuditTime::query()
            ->where('order_id', $orderId)
            ->where('cash_flow_id', 6) // 自助洗分
            ->where('type', 3) // 審核訂單
            ->where('operator', 0) // 尚未記錄操作者
            ->update([
                'operator_time' => now(),
                'operator' => $agent->id,
            ]);
    }
}
