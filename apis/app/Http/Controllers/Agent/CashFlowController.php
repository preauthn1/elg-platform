<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\CashFlow\ApplyWithdrawalRequest;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\WithdrawalOrder;
use App\Services\AgentService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

#[Group('cash-flow (代理儲值與提款)')]
class CashFlowController extends Controller
{
    public function __construct(
        protected AgentService $agentService,
        protected OrderService $orderService,
        protected PaymentService $paymentService,
    ) {}

    /**
     * 提交提款單 (暫停)
     */
    // public function applyWithdrawal(ApplyWithdrawalRequest $request)
    // {
    //     // 請求參數
    //     $input = $request->validated();
    //
    //     // 上下文
    //     $agent = $this->getAgentContext()->agent;
    //     $cashFlowGroupId = $agent->cash_flow_group_id;
    //
    //     // 代理設定
    //     $agentSettings = $this->agentService->getAgentSettings($agent);
    //
    //     // 檢查代理是否允許自助開分/洗分
    //     $withdrawalEnable = $agentSettings[AgentCustomizedSettingKeyEnum::AGENT_SELF_WITHDRAWAL_ENABLE] ?? 0;
    //     if (!$withdrawalEnable) {
    //         throw new ApiException('Agent self cash flow is disabled');
    //     }
    //
    //     // 是否允許修改真實姓名
    //     $enableModifyRealName = (bool) ($agentSettings[AgentCustomizedSettingKeyEnum::AGENT_SELF_WITHDRAWAL_MODIFY_REAL_NAME] ?? 0);
    //     $realName             = trim($input['real_name']);
    //
    //     if ($enableModifyRealName) {
    //         // 允許修改：直接覆蓋真實姓名
    //         $agent->real_name = $realName;
    //     } elseif ($agent->real_name !== $realName) {
    //         // 如果已經有真實姓名，則不能修改
    //         if (!empty($agent->real_name)) {
    //             throw new ApiException('Real name cannot be modified');
    //         }
    //         // 第一次申請時設定真實姓名
    //         $agent->real_name = $realName;
    //     }
    //
    //     if ($agent->isDirty('real_name')) {
    //         $agent->save();
    //     }
    //
    //     // 檢查銀行是否存在並啟用
    //     $bankQuery = Bank::query()
    //         ->where('id', $input['bank_id'])
    //         ->where('status', 1);
    //
    //     try {
    //         $bank = $bankQuery->firstOrFail();
    //     } catch (Exception $e) {
    //         Log::error('找不到對應的銀行', [
    //             'bank_id' => $input['bank_id'],
    //             'sql'     => $bankQuery->toRawSql(),
    //             'error'   => $e->getMessage(),
    //         ]);
    //         throw new ApiException('Bank not found');
    //     }
    //
    //     // 提款金額計算
    //     $point         = Common::numMul($input['amount'], $bank->rate, 4);
    //     $beforeBalance = $agent->balance;
    //     $balance       = Common::numSub($beforeBalance, $point, 4);
    //
    //     // 每日提款限制檢查
    //     $dailyWithdrawalTimes  = $agentSettings[AgentCustomizedSettingKeyEnum::AGENT_SELF_WITHDRAWAL_DAILY_MAX_TIMES] ?? 0;
    //     $dailyWithdrawalAmount = $agentSettings[AgentCustomizedSettingKeyEnum::AGENT_SELF_WITHDRAWAL_DAILY_MAX_AMOUNT] ?? 0;
    //
    //     if ($dailyWithdrawalTimes > 0 || $dailyWithdrawalAmount > 0) {
    //         // 查詢當天已提領紀錄（UTC 時間範圍對應 +8 當天）
    //         $start = Carbon::now()->toBackendTime()->startOfDay()->toSystemTime()->toDateTimeString();
    //         $end   = Carbon::now()->toBackendTime()->endOfDay()->toSystemTime()->toDateTimeString();
    //
    //         $withdrawalCount = WithdrawalOrder::query()
    //             ->selectRaw('COUNT(*) as total_count, COALESCE(SUM(amount * rate), 0) as total_amount')
    //             ->where('agent_id', $agent->id)
    //             ->where('member_id', 0)
    //             ->whereNotIn('status', [WithdrawalOrderStatusEnum::FAILED->value, WithdrawalOrderStatusEnum::REFUND->value])
    //             ->whereBetween('created_at', [$start, $end])
    //             ->first();
    //
    //         // 檢查當日提款次數上限
    //         if ($dailyWithdrawalTimes > 0 && $withdrawalCount->total_count >= $dailyWithdrawalTimes) {
    //             Log::warning('超過每日提款次數限制', [
    //                 'agent_id' => $agent->id,
    //                 'point' => $point,
    //                 'withdrawal_count' => $withdrawalCount->total_count,
    //                 'withdrawal_amount' => $withdrawalCount->total_amount,
    //                 'daily_limit' => $dailyWithdrawalTimes,
    //             ]);
    //             throw new ApiException(__('error_message.9989', ['limit' => $dailyWithdrawalTimes]));
    //         }
    //
    //         // 計算當前提款金額 + 已提領金額
    //         $totalWithdrawalAmount = Common::numAdd($point, $withdrawalCount->total_amount);
    //         // 檢查當日提款金額上限
    //         if ($dailyWithdrawalAmount > 0 && Common::numCompare($totalWithdrawalAmount, $dailyWithdrawalAmount) > 0) {
    //             Log::warning('超過每日提款金額限制', [
    //                 'agent_id' => $agent->id,
    //                 'point' => $point,
    //                 'withdrawal_count' => $withdrawalCount->total_count,
    //                 'withdrawal_amount' => $withdrawalCount->total_amount,
    //                 'total_withdrawal_amount' => $totalWithdrawalAmount,
    //                 'daily_limit' => $dailyWithdrawalAmount,
    //             ]);
    //             throw new ApiException(__('error_message.9990', ['limit' => $dailyWithdrawalAmount]));
    //         }
    //     }
    //
    //
    //     // 檢查三方商戶的提款限額是否與銀行限額匹配
    //     $amount_limit = [];
    //     $agent_cash_flow = AgentCashFlow::query()
    //         ->where('agent_group_id', $agent->cash_flow_group_id)
    //         ->where('cash_flow_id', CashFlowEnum::WITHDRAW_AUTO_WITHDRAWAL)
    //         ->where('status', 1)
    //         ->first();
    //     if ($agent_cash_flow) {
    //         $amount_limit = json_decode($agent_cash_flow->info, true);
    //     } else {
    //         $amount_limit['MinAmount'] = 0;
    //         $amount_limit['MaxAmount'] = 0;
    //     }
    //     $min_amount = $bank->min_amount > 0 ? $bank->min_amount : $amount_limit['MinAmount'];
    //     $max_amount = $bank->max_amount > 0 ? $bank->max_amount : $amount_limit['MaxAmount'];
    //     if ($amount > $max_amount || $amount < $min_amount) {
    //         throw new Exception(__('error_message.2057', ['min' => $min_amount, 'max' => $max_amount]), 2057);
    //     }
    //
    //     // 銀行帳號處理
    //     $accountNo = $input['account_no'];
    //     // 澳洲銀行帳號補上 bsb_code
    //     if (!empty($input['bsb_code'])) {
    //         $accountNo = $input['bsb_code'] . '__' . $input['account_no'];
    //     }
    // }

}
