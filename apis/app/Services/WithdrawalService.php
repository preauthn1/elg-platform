<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\MemberBindingLogTypeEnum;
use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Bank;
use App\Models\Member;
use App\Models\MemberCryptoWallet;
use App\Models\Param;
use App\Models\TransactionLog;
use App\Models\WithdrawalOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * @knowledge rules/withdrawal-member-fee
 */
class WithdrawalService
{
    public function __construct(
        private MemberService $memberService,
        private AgentService $agentService,
        private MemberLimitResolver $memberLimitResolver,
        private MemberRestrictionGuard $memberRestrictionGuard,
    ) {}

    /**
     * 檢查風控會員限制:提款鎖定(禁止申請提現)。
     * 僅擋真出金路徑(createOrder);代理獎勵提現(three/unlimit-hierarchy)為站內錢包轉入,不在範圍。
     *
     * @throws ApiException error_message.member_restriction.withdrawal_locked
     */
    public function checkMemberRestriction(Member $member): void
    {
        if ($this->memberRestrictionGuard->isWithdrawalLocked($member)) {
            throw new ApiException(__('error_message.member_restriction.withdrawal_locked'));
        }
    }

    /**
     * 檢查活動 V2 進行中禁止提款。
     *
     * @throws ApiException error_message.event_mode.withdrawal_event_v2_not_allowed
     */
    public function checkEventV2Restriction(Member $member): void
    {
        if ($member->is_event_v2) {
            throw new ApiException(__('error_message.event_mode.withdrawal_event_v2_not_allowed'));
        }
    }

    /**
     * 今日累積提款通知
     *
     * 通知對象：
     * 1. 所屬代理商的風控群組（agentSetting RISK_CONTROL_TG_CHAT_ID）
     * 2. 總代的技術群組（params 表 type=tg_chat_id, parameter=technology）
     * 3. 平台監控頻道（config bot-notify.large_amount.notify_channel）— 雙重保障副本
     *
     * 三個對象都以 sendOnCommit() 送出：交易 rollBack 代表這筆提款沒有成立，通知就不該送出去。
     */
    public function notifyDailyWithdrawalOverLimit(Member $member, Agent $agent, array $agentSetting, int|float|string $balance): void
    {
        $overLimitNoticeAmount = $agentSetting[AgentCustomizedSettingKeyEnum::OVER_LIMIT_NOTICE_AMOUNT] ?? 0;

        if ($overLimitNoticeAmount <= 0) {
            return;
        }

        $todayStart = Carbon::now()->startOfDay();
        $todayEnd = Carbon::now()->endOfDay();

        $memberTotalAmount = TransactionLog::query()
            ->where('member_id', $member->id)
            ->where('type', 5)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->whereIn('status', [9, 1000])
            ->sum('point');

        if ($memberTotalAmount <= $overLimitNoticeAmount) {
            return;
        }

        $message = implode(PHP_EOL, [
            '【今日累積大額提款通知】',
            '帳號：' . $member->username,
            '今日累積提款：' . $memberTotalAmount,
            '目前餘額：' . $balance,
        ]);

        $chatId = $agentSetting[AgentCustomizedSettingKeyEnum::RISK_CONTROL_TG_CHAT_ID] ?? '';
        if (!empty($chatId)) {
            BotNotify::chatId($chatId)->sendOnCommit($message);
        }

        // 找出會員的總代並附上站點資訊
        $agents = $agent->hierarchy_array;
        if (count($agents) > 1) {
            $generalAgentId = $agents[1];
            $generalAgent = Agent::find($generalAgentId);
            $params = Param::query()
                ->where('type', 'tg_chat_id')
                ->where('parameter', 'technology')
                ->first();
            $chatId = $params->value ?? '';

            $message .= PHP_EOL . '站點：' . $generalAgent->username;

            if (!empty($chatId)) {
                BotNotify::chatId($chatId)->sendOnCommit($message);
            }
        }

        // 雙重保障：副本送至平台監控頻道
        BotNotify::channel('large_amount')->sendOnCommit($message);
    }

    /**
     * 後臺提款通知
     */
    public function setRedisNotice(Agent $agent): void
    {
        $key = [
            'notice',
            'withdrawal',
            $agent->cash_flow_group_id,
        ];
        $key = implode(':', $key);

        $model = WithdrawalOrder::query()
            ->where('agent_group_id', $agent->cash_flow_group_id)
            ->where('status', 1);
        $amount = $model->count();
        $lastOrderId = $model->orderByDesc('id')->value('id');

        $data = [
            'amount' => $amount,
            'last_order_id' => $lastOrderId,
        ];

        // TODO 後台是接收json格式
        Redis::connection('json')->setex($key, 60, $data);
    }

    /**
     * 檢查流水限制
     *
     * @throws ApiException error_message.1980, flow limit is not passed
     */
    public function checkFlowLimit(Member $member, array $agentSetting): void
    {
        $switch = $agentSetting[AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE];
        if ($switch == 1) {
            $flowLimit = $this->memberService->getFlowLimit($member);
            if ($flowLimit['pass'] == 0) {
                throw new ApiException(__('error_message.1980'));
            }
        }
    }

    /**
     * 檢查近期是否修改過個人資料
     *
     * @throws ApiException error_message.2046, member binding log is found
     */
    public function checkBindingLog(Member $member, array $agentSetting): void
    {

        $switch = $agentSetting[AgentCustomizedSettingKeyEnum::MODIFY_BINDING_LIMIT_WITHDRAWAL];
        if ($switch == 1) {
            $bindingLog = $this->memberService->getBindingLog($member, MemberBindingLogTypeEnum::UPDATE_PHONE, 1);
            if ($bindingLog) {
                throw new ApiException(__('error_message.2046'));
            }
        }
    }

    /**
     * 檢查真實姓名
     *
     * @throws ApiException error_message.2047, realname is not validated
     */
    public function checkRealname(Member $member, array $agentSetting): void
    {
        if (!RealnameValidationService::isWithdrawRealnameValidated($agentSetting, $member)) {
            throw new ApiException(__('error_message.realname_invalid.withdrawal'));
        }
    }

    /**
     * 檢查提領次數
     *
     * @throws ApiException error_message.1956, withdrawal times limit is exceeded
     */
    public function checkWithdrawalTimes(Member $member, array $agentSetting): void
    {
        $withdrawalLimit = $agentSetting[AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT];
        $hasVip = $agentSetting[AgentCustomizedSettingKeyEnum::VIP_VISIBLE] ?? 0;

        // 有群組 → 基礎次數改抓「代理 ∩ 群組 取低」（不限制的層不參與），沿用下方原本檢查與 VIP 額外加總；
        // 合併值 0（群組設 0=禁止）原「>0 才檢查」慣例無法表達，直接擋；null（皆不限）→ 0 沿用原慣例
        if ($this->memberLimitResolver->usesMemberGroupLimit($member)) {
            $base = $this->memberLimitResolver->withdrawCountBaseLimit($member, $agentSetting);
            if ($base === 0) {
                throw new ApiException(__('error_message.1956'));
            }
            $withdrawalLimit = $base ?? 0;
        }

        if ($withdrawalLimit > 0) {
            // vip 提領次數是額外加上代理組別的提領次數，並非覆蓋
            if ($hasVip == 1) {
                $vip_info = $this->memberService->getVipContent($member);
                $withdrawalLimit += $vip_info->withdrawal_times_limit;
            }

            $withdrawalCount = $this->memberService->getTodayWithdrawalCount($member);
            if ($withdrawalCount >= $withdrawalLimit) {
                throw new ApiException(__('error_message.1956'));
            }
        }
    }

    /**
     * 檢查當日累計提領金額是否超過 VIP 設定的每日上限
     *
     * 規則：
     * - 須 AgentCustomizedSettingKeyEnum::VIP_VISIBLE = 1 才啟用
     * - 上限取自 VIP 內容 withdrawal_amount_limit
     *   - 負值 (-1 或更低) = 無限制，不檢查
     *   - 0 = 禁止提款，直接擋
     *   - 正值 = 累計 + 本次 point > 上限 → 擋
     * - 累計範圍：當日 TransactionLog.point，type=WITHDRAWAL、status in [STATUS_REVIEW, STATUS_SUCCESS]
     * - 比較單位皆為 point (amount * rate)
     *
     * @throws ApiException error_message.9990, daily withdrawal amount exceeded
     */
    public function checkDailyWithdrawalAmountLimit(Member $member, Bank $bank, $withdrawalAmount, array $agentSetting): void
    {
        // 有群組 → 上限改抓「群組 ∩ VIP」取嚴合成值（point），跳過下方「未開 VIP 即 return」閘門，併入共用判斷；
        // 無群組 → 走原本純 VIP 判斷（含負值視為無限制）
        if ($this->memberLimitResolver->usesMemberGroupLimit($member)) {
            $dailyLimit = $this->memberLimitResolver->withdrawDailyAmountLimit($member);
            if ($dailyLimit === null) {
                return;
            }
        } else {
            $hasVip = $agentSetting[AgentCustomizedSettingKeyEnum::VIP_VISIBLE] ?? 0;
            if ($hasVip != 1) {
                return;
            }

            $vipInfo = $this->memberService->getVipContent($member);
            $dailyLimit = Common::numAdd('0', (string) ($vipInfo?->withdrawal_amount_limit ?? 0), 4);
        }

        // 負值視為無限制
        if (Common::numCompare($dailyLimit, '0', 4) < 0) {
            return;
        }

        // 0 視為禁止提款
        if (Common::numCompare($dailyLimit, '0', 4) === 0) {
            throw new ApiException(__('error_message.9990', ['limit' => 0]));
        }

        $accumulated = $this->memberService->getTodayWithdrawalAmount($member);
        $currentPoint = Common::numMul((string) $withdrawalAmount, (string) $bank->rate, 4);
        $total = Common::numAdd($accumulated, $currentPoint, 4);

        if (Common::numCompare($total, $dailyLimit, 4) > 0) {
            throw new ApiException(__('error_message.9990', ['limit' => $dailyLimit]));
        }
    }

    /**
     * 檢查餘額
     *
     * @throws ApiException error_message.1022, balance is not enough
     */
    public function checkBalance(Member $member, Bank $bank, $withdrawalAmount): void
    {
        if (($withdrawalAmount * $bank->rate) > $member->balance) {
            throw new ApiException(__('error_message.1022'));
        }
    }

    /**
     * 檢查剩餘流水需小於提款後錢包餘額
     *
     * @throws ApiException error_message.1980, flow limit is not passed
     */
    public function checkFlowLimitBalance(Member $member, Bank $bank, $withdrawalAmount, array $agentSetting): void
    {
        if ($agentSetting[AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT] == 1
            && $agentSetting[AgentCustomizedSettingKeyEnum::WITHDRAWAL_FLOW_LIMIT_SETTING_001] == 1) {
            // 取得轉帳的流水限制
            $transferFlowLimit = $this->memberService->getTransferChangeFlowLimit($member);
            $remainFlowLimit = $transferFlowLimit['flow_limit'] - $transferFlowLimit['reach'];
            $afterBalance = $member->balance - ($withdrawalAmount * $bank->rate);
            if ($remainFlowLimit > $afterBalance) {
                throw new ApiException(__('error_message.1980'));
            }
        }
    }

    /**
     * 檢查提款金額限制
     *
     * @throws ApiException error_message.9997, withdrawal amount is not valid
     */
    public function checkWithdrawalAmountLimit(Agent $agent, Bank $bank, $withdrawalAmount, ?Member $member = null): void
    {
        $amount_limit = $this->agentService->getWithdrawalAmountLimit($agent);
        $min_amount = $bank->min_amount > 0 ? $bank->min_amount : $amount_limit['min_amount'];
        $max_amount = $bank->max_amount > 0 ? $bank->max_amount : $amount_limit['max_amount'];

        // 有會員時，上下限改與群組/VIP 取嚴合成（amount 單位，與顯示端共用同一函式故數字必然一致）；
        // helper 內部已含「無群組回原值」判斷，沿用下方原本比較
        if ($member !== null) {
            $min_amount = $this->memberLimitResolver->withdrawFloorAmount($member, (string) $min_amount, $bank->rate);
            $max_amount = $this->memberLimitResolver->withdrawCeilingAmount($member, (string) $max_amount, $bank->rate);
        }

        if ($withdrawalAmount > $max_amount || $withdrawalAmount < $min_amount) {
            throw new ApiException(__('error_message.9997'));
        }
    }

    /**
     * 檢查 Fonpay 提款
     *
     * @throws ApiException error_message.1351, fonpay withdrawal is not allowed
     */
    public function checkFonpay(Member $member, Bank $bank, $accountNo): void
    {
        // TODO FonPay 內轉先寫死不給過
        // TODO 三方回覆:
        // TODO 正常的情況不會出金至系統產出的地址
        // TODO 可以由您的系統上透過資料庫做帳務轉移(內轉)
        if ($bank->type == 3 && $bank->code == 'FON') {
            $exist = MemberCryptoWallet::query()
                ->where('address', $accountNo)
                ->exists();
            if ($exist) {
                throw new ApiException(__('error_message.1351'));
            }
        }
    }
}
