<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\ContextKeyEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\Order;
use App\Models\TransactionLog;
use App\Models\VipFlowLimit;
use App\Models\WalletTransactionLog;
use Exception;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/agent-self-deposit
 * @knowledge rules/deposit-order-fees
 * @knowledge rules/payment-cost-summary
 */
class PaymentService
{
    /**
     * 金流商 Driver 的**唯一解析入口**（third_party_code 去掉前兩碼並轉大寫 → config/payment.php）。
     *
     * 🔴 禁止在 Controller 或 Service 內 `new` 具體 Driver，也禁止自寫第二份 code 對 class 的對照表
     * ——多一份對照表就多一個會 drift 的來源。
     *
     * ⚠️ 一份實作供多商戶共用時（config/payment.php 直接指到同一 Driver 類，例 SPEEDPAY 指
     * PayMate\Driver），**共用方沒有自己的 class**——真實商戶名靠被共用的那個 Driver 覆寫
     * DriverTrait::driverLabelOverride 取得（PayMate\Driver 的作法是讀 ACF 的
     * info['DRIVER_LABEL']）。所以新增共用商戶要做的是在該 ACF 的 info 設 DRIVER_LABEL，
     * 不是去建一個新 class；沒設的話 log、例外訊息、Http::logAction 與代付查表灰度閘門
     * 會全部標成被共用的那一家。
     * 自有目錄的薄繼承殼（例 TTPay 繼承 DgPay）由目錄名自動歸屬，不需也不應覆寫。
     */
    public function getProvider(AgentCashFlow $agentCashFlow)
    {
        // $thirdPartyCode 第一位是存取款 (D/W), 排除前兩位
        $thirdPartyCode = $agentCashFlow->third_party_code; // ! $agentCashFlow->cashFlow->third_party_code
        if (!$thirdPartyCode) {
            throw new Exception('The third_party_code field is missing in AgentCashFlow.');
        }
        $providerCode = substr($thirdPartyCode, 2);
        $providerCode = strtoupper($providerCode);

        // 對應的 class 名稱
        $providerPath = config('payment.provider.' . $providerCode);

        // 檢查提供者類別是否存在
        if (!$providerPath || !class_exists($providerPath)) {
            throw new Exception("Payment provider for code [{$thirdPartyCode}] is not ready or does not exist.");
        }

        Context::addHidden(ContextKeyEnum::AGENT_CASH_FLOW->value, $agentCashFlow);

        return App::make($providerPath, ['agentCashFlow' => $agentCashFlow]);
    }

    /**
     * 更新用戶餘額, 建立交易紀錄
     *
     * @throws \Throwable
     */
    public function updateMemberBalance(Order $order): void
    {
        Log::withContext([
            'order_id' => $order->id,
            'member_id' => $order->member_id,
            'agent_id' => $order->agent_id,
        ]);

        // 代理訂單直接交給 updateAgentBalance 處理，各自管理自己的事務
        if ($order->agent_id > 0) {
            Log::info('UpdateMemberBalance: 代理訂單，轉交 updateAgentBalance 處理');
            $this->updateAgentBalance($order);

            return;
        }

        // 此處原本在大交易前預建 type=2 遊戲錢包（ensureGameWalletExists）；
        // type=2 已廢除（決議⑬）不再建立，該步驟隨之移除。存量錢包的入帳
        // 仍由下方多錢包分支以帶鎖讀取處理。
        DB::beginTransaction();

        try {
            Log::info('UpdateMemberBalance 開始處理');

            $lockOrder = Order::lockForUpdate()->find($order->id);

            // 找不到訂單，拋出例外由 catch 統一 rollBack
            if (!$lockOrder) {
                Log::error('UpdateMemberBalance: 找不到訂單');
                throw new ApiException('Order not found.');
            }

            // 檢查訂單狀態是否為 SUCCESS（成功但未上分）
            if ($lockOrder->status != OrderStatusEnum::SUCCESS->value) {
                Log::warning('UpdateMemberBalance: 訂單狀態非 SUCCESS，跳過處理', [
                    'current_status' => $lockOrder->status,
                    'expected_status' => OrderStatusEnum::SUCCESS->value,
                ]);

                // 狀態不符合預期，直接返回不處理（可能已被處理過）
                DB::rollBack();

                return;
            }

            $member = Member::lockForUpdate()->find($lockOrder->member_id);

            // 找不到會員，拋出例外由 catch 統一 rollBack
            if (!$member) {
                Log::error('UpdateMemberBalance: 找不到會員', ['member_id' => $lockOrder->member_id]);
                throw new ApiException('Member not found.');
            }

            $agent = $member->agent;

            // 是否為多錢包模式
            $isMultipleWallet = $agent->getSettingValue(AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_SWITCH);
            // 充值流水倍數
            $depositFlowLimitRate = $agent->getSettingValue(AgentCustomizedSettingKeyEnum::DEPOSIT_FLOW_LIMIT_RATE);
            // 啟用VIP功能
            $enableVip = $agent->getSettingValue(AgentCustomizedSettingKeyEnum::VIP_VISIBLE);
            // VIP充值稽核流水倍數
            $vipFlowLimitRate = $agent->getSettingValue(AgentCustomizedSettingKeyEnum::VIP_DEPOSIT_FLOW_LIMIT_SETTING);

            if ($isMultipleWallet) {
                $walletService = app(WalletService::class);
                // type=2 已廢除（決議⑬）只查不建；多錢包為死路徑，查無存量即顯性中止
                $memberWallet = $walletService->getLockedMemberWallet($member->id, WalletEnum::CODE_GAME)
                    ?? throw new \LogicException("type=2 遊戲錢包已廢除且查無存量，member_id={$member->id}");
                Log::info('UpdateMemberBalance 查詢多錢包');

                // 檢查錢包狀態是否正常
                if ($memberWallet->status != 1) {
                    Log::warning('UpdateMemberBalance: 錢包已鎖定，跳過處理', [
                        'member_id' => $member->id,
                        'wallet_status' => $memberWallet->status,
                        'error_message' => __('error_message.90062'),
                    ]);

                    // 錢包被鎖定，回滾事務並返回（訂單保持狀態 1，可等待後續處理）
                    DB::rollBack();

                    return;
                }

                // 更新錢包餘額
                $beforeBalance = $memberWallet->balance;
                $balance = Common::numAdd($memberWallet->balance, $lockOrder->point);
                $memberWallet->update(['balance' => $balance]);
                Log::info('UpdateMemberBalance 更新多錢包餘額');

                // 建立交易紀錄
                $log = [
                    'transaction_no' => Common::getTransactionNo(true),
                    'wallet_type_id' => WalletEnum::CODE_GAME->value,
                    'agent_id' => $member->parent,
                    'member_id' => $lockOrder->member_id,
                    'order_id' => $lockOrder->id,
                    'point' => $lockOrder->point,
                    'before_balance' => $beforeBalance,
                    'balance' => $balance,
                    'type' => WalletTransactionEnum::GAME_WALLET_ORDER_DEPOSIT->value,
                    'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                    'admin' => 0,
                ];
                $transactionLog = WalletTransactionLog::create($log);
                Log::info('UpdateMemberBalance 寫入多錢包交易紀錄 WalletTransactionLog #' . $transactionLog->id);
            } else {
                // 更新錢包餘額
                $beforeBalance = $member->balance;
                $balance = Common::numAdd($member->balance, $lockOrder->point);
                $member->update(['balance' => $balance]);
                Log::info('UpdateMemberBalance 更新單錢包餘額');

                // 建立交易紀錄
                $log = [
                    'transaction_no' => Common::getTransactionNo(),
                    'agent_id' => $member->parent,
                    'member_id' => $lockOrder->member_id,
                    'order_id' => $lockOrder->id,
                    'point' => $lockOrder->point,
                    'before_balance' => $beforeBalance,
                    'balance' => $balance,
                    'type' => TransactionEnum::ORDER_DEPOSIT->value,
                    'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                    'operator' => '',
                    'remark' => '',
                    'admin' => 0,
                ];
                $transactionLog = TransactionLog::create($log);
                Log::info('UpdateMemberBalance 寫入單錢包交易紀錄 TransactionLog #' . $transactionLog->id);
            }

            // 更新訂單狀態 => 充值成功（使用鎖定的訂單）
            // payment_cost（支付商手續費）在回調成功這一刻依渠道 cost × 最終 point 寫入 target/tickets#4361；回調已寫實際值的沿用（見 paymentCostOf）
            $lockOrder->update([
                'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
                'balance' => $balance,
                'pay_time' => now()->toDateTimeString(),
                'payment_cost' => $this->paymentCostOf($lockOrder),
            ]);
            Log::info('UpdateMemberBalance 更新訂單狀態 => 充值成功');

            // 增加稽核流水 Log
            if ($depositFlowLimitRate > 0) {
                $flowLimit = FlowLimit::create([
                    'member_id' => $lockOrder->member_id,
                    'type' => TransactionEnum::ORDER_DEPOSIT->value,
                    'reference_id' => !$isMultipleWallet ? $transactionLog->id : 0,
                    'wallet_transaction_log_id' => $isMultipleWallet ? $transactionLog->id : 0,
                    'flow_limit' => Common::numMul($lockOrder->point, $depositFlowLimitRate),
                    'admin' => 0,
                ]);
                Log::info('UpdateMemberBalance 寫入稽核流水 FlowLimit #' . $flowLimit->id);

                // @TODO 先寫死只限老虎機, 之後再來調整
                // @TODO 先關閉, 之後再來寫 by mars
                // FlowLimitRestriction::create([
                //    'flow_limit_id' => $flowLimit->id,
                //    'vendor_id' => 0,
                //    'type_id' => 2
                // ]);
            }

            // 有開啟 VIP 需寫入 VIP 稽核流水
            if ($enableVip) {
                $vipFlowLimit = VipFlowLimit::create([
                    'member_id' => $lockOrder->member_id,
                    'type' => TransactionEnum::ORDER_DEPOSIT->value,
                    'deposit_amount' => $lockOrder->point,
                    'flow_limit' => Common::numMul($lockOrder->point, $vipFlowLimitRate),
                    'status' => ($vipFlowLimitRate == 0) ? 1 : 0,
                    'admin' => 0,
                ]);
                Log::info('UpdateMemberBalance 寫入 VIP 稽核流水 VipFlowLimit #' . $vipFlowLimit->id);

                if ($vipFlowLimitRate == 0) {
                    // 如果倍數是0 直接加進充值分數
                    DB::statement('CALL SP_calc_member_vip_deposit_amount(?, ?, ?)', [$lockOrder->member_id, $lockOrder->point, $vipFlowLimit->id]);
                    Log::info('UpdateMemberBalance 執行 SP_calc_member_vip_deposit_amount', [
                        'member_id' => $lockOrder->member_id,
                        'amount' => $lockOrder->point,
                        'vip_flow_limit_id' => $vipFlowLimit->id,
                    ]);
                }
            }

            // 充值獎勵
            DB::statement('CALL SP_event_deposit(?)', [$lockOrder->id]);
            Log::info('UpdateMemberBalance 執行 SP_event_deposit', ['order_id' => $lockOrder->id]);

            // 是否為該用戶首充（查詢已完成的充值訂單數量）
            $memberOrderCount = Order::query()
                ->where('member_id', $member->id)
                ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
                ->where('created_at', '>=', $member->created_at)
                ->count();

            if ($memberOrderCount == 1) {
                $backendCreditCount = 0;
                $backendDepositCountsAsDeposit = $agent->getSettingValue(AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT);
                if ($backendDepositCountsAsDeposit) {
                    $backendCreditCount = TransactionLog::query()
                        ->where('member_id', $member->id)
                        ->where('type', TransactionEnum::DEPOSIT->value)
                        ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
                        ->where('created_at', '>=', $member->created_at)
                        ->count();
                }

                // 如果是該用戶首充，則 call SP_event_invite_deposit 發放下線充值獎金
                if ($backendCreditCount == 0) {
                    DB::statement('CALL SP_event_invite_deposit(?)', [$lockOrder->id]);
                    Log::info('UpdateMemberBalance 執行 SP_event_invite_deposit', ['order_id' => $lockOrder->id]);
                }
            }

            // 成長彩金計劃：累積儲值金額，每筆儲值都要執行（不像上面首充才觸發的邀請獎勵）
            DB::statement('CALL SP_calc_event_progressive_jackpot_deposit(?)', [$lockOrder->id]);
            Log::info('UpdateMemberBalance 執行 SP_calc_event_progressive_jackpot_deposit', ['order_id' => $lockOrder->id]);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('UpdateMemberBalance 發生例外', [
                'order_id' => $order->id,
                'member_id' => $order->member_id,
                'agent_id' => $order->agent_id,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            Log::withoutContext([
                'order_id',
                'member_id',
                'agent_id',
            ]);
        }
    }

    /**
     * 支付商手續費（成本）＝渠道 cost × 訂單最終 point，只在代收成功（status→3）時寫入；
     * 建單、Ap8Pay／TngPin 改 point 都不用管，成功這一刻拿到的就是最終值。
     * 自助上分不經此處（審核在 API_Agent），改在 AutoDepositController 建單時寫入；彙總只計 status=3，待審核單不會被算進去。
     *
     * 例外：回調已先在單上寫好金流商回報的實際手續費（目前只有 CCPayment，見其 DepositCallback）就沿用，不以成本費率覆寫。
     * 判斷依據是「單上的值大於 0」：其他金流商的單入帳前一律是 0；實際手續費剛好是 0 的單無法與「沒寫」區分，同樣走成本費率。
     */
    private function paymentCostOf(Order $order): string
    {
        $prewritten = Common::numAdd('0', (string) ($order->payment_cost ?? '0'), 4);
        if (Common::numCompare($prewritten, '0', 4) > 0) {
            return $prewritten;
        }

        $agentCashFlow = AgentCashFlow::query()->find((int) $order->agent_cash_flow_id);

        return $agentCashFlow?->paymentCostFor((string) ($order->point ?? '0')) ?? '0';
    }

    /**
     * 更新代理餘額，建立交易紀錄
     * 內部自行執行 lockForUpdate 取得最新訂單狀態，並管理獨立事務
     *
     * @see support 專案 src/Services/CashFlowService.php updateAgentBalance 方法
     */
    public function updateAgentBalance(Order $order): void
    {
        if ($order->member_id > 0) {
            Log::error('UpdateAgentBalance: 訂單同時關聯到會員與代理', [
                'order_id' => $order->id,
                'member_id' => $order->member_id,
                'agent_id' => $order->agent_id,
            ]);
            throw new ApiException('UpdateAgentBalance: Order is associated with a member, not an agent.');
        }

        DB::beginTransaction();

        try {
            $lockOrder = Order::lockForUpdate()->find($order->id);

            if (!$lockOrder) {
                Log::error('UpdateAgentBalance: 找不到訂單', [
                    'order_id' => $order->id,
                    'agent_id' => $order->agent_id,
                ]);
                throw new ApiException('Order not found.');
            }

            if ($lockOrder->status != OrderStatusEnum::SUCCESS->value) {
                Log::error('UpdateAgentBalance: 訂單狀態非 SUCCESS，跳過處理', [
                    'order_id' => $lockOrder->id,
                    'agent_id' => $lockOrder->agent_id,
                    'current_status' => $lockOrder->status,
                    'expected_status' => OrderStatusEnum::SUCCESS->value,
                ]);
                throw new ApiException('Order status is not SUCCESS.');
            }

            $agent = Agent::lockForUpdate()->find($lockOrder->agent_id);

            if (!$agent) {
                Log::error('UpdateAgentBalance: 找不到代理', [
                    'order_id' => $lockOrder->id,
                    'agent_id' => $lockOrder->agent_id,
                ]);
                throw new ApiException('Agent not found.');
            }

            // 更新錢包餘額
            $beforeBalance = $agent->balance;
            $balance = Common::numAdd($agent->balance, $lockOrder->point);
            $agent->update(['balance' => $balance]);
            Log::info(sprintf('UpdateAgentBalance: 更新代理 %s (%s) 額度 %s => %s', $agent->username, $agent->id, $beforeBalance, $balance));

            // 建立交易紀錄
            $transactionLog = TransactionLog::create([
                'transaction_no' => Common::getTransactionNo(),
                'agent_id' => $agent->id,
                'member_id' => 0,
                'order_id' => $lockOrder->id,
                'point' => $lockOrder->point,
                'before_balance' => $beforeBalance,
                'balance' => $balance,
                'type' => TransactionEnum::ORDER_DEPOSIT->value,
                'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                'operator' => 'system',
                'admin' => 0,
            ]);
            Log::info('UpdateAgentBalance: 建立 TransactionLog #' . $transactionLog->id);

            // 更新訂單（payment_cost 同會員單，回調成功時依渠道 cost × 最終 point 寫入；回調已寫實際值的沿用）
            $lockOrder->update([
                'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
                'balance' => $balance,
                'pay_time' => now()->toDateTimeString(),
                'payment_cost' => $this->paymentCostOf($lockOrder),
            ]);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('UpdateAgentBalance: 事務回滾', [
                'order_id' => $order->id,
                'agent_id' => $order->agent_id,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
