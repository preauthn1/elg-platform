<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\MemberBindingLogTypeEnum;
use App\Enums\MemberRegisterLimitFieldEnum;
use App\Enums\MemberTransferLogStatusEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\ClearFlowLimitApplication;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\MemberBindingLog;
use App\Models\MemberTransferLog;
use App\Models\Order;
use App\Models\TransactionLog;
use App\Models\VipContent;
use App\Models\WalletTransactionLog;
use App\Models\WithdrawalOrder;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * @knowledge rules/withdrawal-member-fee
 */
class MemberService
{
    public function __construct(
        private AgentService $agentService,
        private MemberRegisterLimitService $memberRegisterLimitService,
    ) {}

    /**
     * 綁定上限「值」的來源：type=6「註冊/綁定限制」（取代舊個性化設置 2013/2016）。
     * 自訂逐值優先、未命中走平台上限；後台未勾選該欄位即回 null = 不限制。
     */
    public function bindingLimitFor(
        Agent $agent,
        MemberRegisterLimitFieldEnum $field,
        string $value = '',
    ): ?int {
        return $this->memberRegisterLimitService->bindingLimit($agent, $field, $value);
    }

    /**
     * 取得 vip 設定
     */
    public function getVipContent(Member $member): ?VipContent
    {
        $memberId = $member->id;
        $agent = $member->agent;

        // 取得個人 vip 設定
        $vipContent = VipContent::query()
            ->whereHas('memberVip', function ($query) use ($memberId) {
                $query->where('member_id', $memberId);
            })
            ->first();

        // 不存在的話取得代理的 vip 設定
        if (!$vipContent) {
            // 代理id, 由 parent 近到遠排序
            $agentIds = array_reverse($agent->hierarchy_array);
            $vipContent = VipContent::query()
                ->where('level', 0)
                ->whereIn('agent_id', $agentIds)
                ->orderByRaw('FIELD(agent_id, ' . implode(',', $agentIds) . ')')
                ->first();
        }

        return $vipContent;
    }

    /**
     * 取得國碼和電話碼號
     */
    public function getMobileAndCountryCode(Member $member): array
    {
        $agent = $member->agent;
        $result = [];
        $result['country_code'] = '';
        $result['mobile'] = $member->mobile;

        $countries = DB::table('sms_gateway_infos as sgi')
            ->join('sms_gateways as sg', function ($join) {
                $join->on('sg.id', 'sgi.sms_gateway_id')
                    ->where('sg.status', 1);
            })
            ->join('sms_gateway_info_countries as sgic', function ($join) {
                $join->on('sgic.sms_gateway_info_id', 'sgi.id')
                    ->where('sgic.status', 1);
            })
            ->join('countries as c', function ($join) {
                $join->on('c.id', 'sgic.country_id')
                    ->where('c.status', 1);
            })
            ->where('sgi.sms_gateway_group_id', $agent->sms_gateway_group_id)
            ->where('sgi.status', 1)
            ->select(
                'c.country_code'
            )
            ->get();
        if (count($countries) > 0) {
            foreach ($countries as $country) {
                $countryCode = (string) $country->country_code;
                $mobile = (string) $member->mobile;
                if (str_starts_with($mobile, $countryCode)) {
                    $result['country_code'] = $countryCode;
                    $result['mobile'] = substr($mobile, strlen($countryCode));
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * +8時區的今日提款次數
     */
    public function getTodayWithdrawalCount(Member $member): int
    {
        // 計算當天已提領次數
        $start = Carbon::now()->timezone('+8')->startOfDay()->setTimezone('utc')->toDateTimeString();
        $end = Carbon::now()->timezone('+8')->endOfDay()->setTimezone('utc')->toDateTimeString();
        $withdrawalCount = WithdrawalOrder::query()
            ->where('member_id', $member->id)
            ->whereNotIn('status', [
                WithdrawalOrderStatusEnum::FAILED->value,
                WithdrawalOrderStatusEnum::REFUND->value,
            ])
            ->whereBetween('created_at', [$start, $end])
            ->count();

        return $withdrawalCount;
    }

    /**
     * 取得會員當天累計提領金額 (point 單位, 精度 4)
     *
     * 累計範圍：TransactionLog.point, type=WITHDRAWAL, status in [STATUS_REVIEW, STATUS_SUCCESS]
     */
    public function getTodayWithdrawalAmount(Member $member): string
    {
        $start = Carbon::now()->timezone('+8')->startOfDay()->setTimezone('utc')->toDateTimeString();
        $end = Carbon::now()->timezone('+8')->endOfDay()->setTimezone('utc')->toDateTimeString();

        $sum = TransactionLog::query()
            ->where('member_id', $member->id)
            ->where('type', TransactionEnum::WITHDRAWAL->value)
            ->whereIn('status', [
                TransactionStatusEnum::STATUS_REVIEW->value,
                TransactionStatusEnum::STATUS_SUCCESS->value,
            ])
            ->whereBetween('created_at', [$start, $end])
            ->sum('point');

        return Common::numAdd('0', (string) $sum, 4);
    }

    /**
     * +8時區的今日存款次數（訂單口徑，防「連續建單」繞過每日次數限制）
     *
     * 計算範圍：orders 表（一般 / 自助 / 多錢包存款皆先建單，為存款唯一入口），排除 FAILED（審核不通過 / 取消 / 過期）；
     * 未完成單（CREATED / SUCCESS / WAITING_MANUAL_DEPOSIT）與已上分（DEPOSIT_FINISHED）皆佔次數——
     * 口徑對齊提款 getTodayWithdrawalCount（訂單表、僅排除失敗類狀態）。
     */
    public function getTodayDepositCount(Member $member): int
    {
        $start = Carbon::now()->timezone('+8')->startOfDay()->setTimezone('utc')->toDateTimeString();
        $end = Carbon::now()->timezone('+8')->endOfDay()->setTimezone('utc')->toDateTimeString();

        return Order::query()
            ->where('member_id', $member->id)
            ->whereNotIn('status', [
                OrderStatusEnum::FAILED->value,
            ])
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    /**
     * 取得會員當天（UTC+8 當日）作為「轉出方」的會員轉帳累計金額 (point 單位, 精度 4)。
     *
     * 計算範圍：member_transfer_logs，僅計成功單（該表只在轉帳交易成功提交時建立）。
     * 註：本專案 `amount` 欄存的是轉出方實際扣款的**含稅毛額**（`tax` 另存），與舊版 API_Frontend
     * 存淨額的口徑不同，故此處直接 SUM(amount)，不可照舊版加上 tax，否則稅額會被重複計算。
     */
    public function getTodayMemberTransferOutAmount(Member $member): string
    {
        $start = Carbon::now()->timezone('+8')->startOfDay()->setTimezone('utc')->toDateTimeString();
        $end = Carbon::now()->timezone('+8')->endOfDay()->setTimezone('utc')->toDateTimeString();

        $sum = MemberTransferLog::query()
            ->where('operator', $member->id)
            ->where('status', MemberTransferLogStatusEnum::SUCCESS->value)
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        return Common::numAdd('0', (string) $sum, 4);
    }

    /**
     * 取得會員當天（UTC+8 當日）作為「轉出方」的會員轉帳累計次數。
     */
    public function getTodayMemberTransferOutCount(Member $member): int
    {
        $start = Carbon::now()->timezone('+8')->startOfDay()->setTimezone('utc')->toDateTimeString();
        $end = Carbon::now()->timezone('+8')->endOfDay()->setTimezone('utc')->toDateTimeString();

        return MemberTransferLog::query()
            ->where('operator', $member->id)
            ->where('status', MemberTransferLogStatusEnum::SUCCESS->value)
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    /**
     * 取得會員當天累計存款金額 (point 單位, 精度 4)＝已上分 + 今日未完成單（防「連續建單」繞過每日金額限制）
     *
     * - 已上分：TransactionLog(type=ORDER_DEPOSIT) + WalletTransactionLog(type=GAME_WALLET_ORDER_DEPOSIT，
     *   多錢包代理的存款成功只寫此表) 之 STATUS_SUCCESS point 並計（口徑對齊 Missions DepositSummary）。
     * - 未完成單：orders 今日 CREATED / SUCCESS / WAITING_MANUAL_DEPOSIT 之 point；
     *   上分時訂單同流程轉 DEPOSIT_FINISHED 退出此口徑、改由 ledger 計，兩者不重複。
     */
    public function getTodayDepositAmount(Member $member): string
    {
        $start = Carbon::now()->timezone('+8')->startOfDay()->setTimezone('utc')->toDateTimeString();
        $end = Carbon::now()->timezone('+8')->endOfDay()->setTimezone('utc')->toDateTimeString();

        $sum = TransactionLog::query()
            ->where('member_id', $member->id)
            ->where('type', TransactionEnum::ORDER_DEPOSIT->value)
            ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->whereBetween('created_at', [$start, $end])
            ->sum('point');

        $walletSum = WalletTransactionLog::query()
            ->where('member_id', $member->id)
            ->where('type', WalletTransactionEnum::GAME_WALLET_ORDER_DEPOSIT->value)
            ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->whereBetween('created_at', [$start, $end])
            ->sum('point');

        $pendingSum = Order::query()
            ->where('member_id', $member->id)
            ->whereIn('status', [
                OrderStatusEnum::CREATED->value,
                OrderStatusEnum::SUCCESS->value,
                OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value,
            ])
            ->whereBetween('created_at', [$start, $end])
            ->sum('point');

        return Common::numAdd(Common::numAdd((string) $sum, (string) $walletSum, 4), (string) $pendingSum, 4);
    }

    /**
     * 檢查支付密碼
     *
     * @throws Exception error_message.9014, member payment password is not set
     * @throws Exception error_message.90052, member payment password is incorrect
     */
    public function checkPaymentPassword(Member $member, ?string $password = [REDACTED] Member
    {
        if (empty($member->payment_password)) {
            throw new Exception(__('error_message.9014'), 9014);
        }

        if (!empty($password) && !Hash::check($password, $member->payment_password)) {
            // 密碼錯誤
            throw new Exception(__('error_message.90052'), 9005);
        }

        return $member;
    }

    /**
     * 取得流水限制
     */
    public function getFlowLimit(Member $member): array
    {
        $agentSetting = $this->agentService->getAgentSettings($member->agent);

        // 儲值是否加入流水限制
        $deposit_flow_limit = $agentSetting[AgentCustomizedSettingKeyEnum::DEPOSIT_FLOW_LIMIT_VISIBLE];

        // 活動是否加入流水限制
        $event_flow_limit = $agentSetting[AgentCustomizedSettingKeyEnum::EVENT_FLOW_LIMIT_VISIBLE];

        // 取得流水限制
        $flowLimit = FlowLimit::query()
            ->where('member_id', $member->id)
            ->where('status', 0)
            ->when($deposit_flow_limit == 1 && $event_flow_limit == 0, function ($query) {
                // 只顯示儲值稽核流水，活動稽核流水不顯示
                $query->whereIn('type', [TransactionEnum::DEPOSIT->value, TransactionEnum::ORDER_DEPOSIT->value, TransactionEnum::MEMBER_TRANSFER_IN->value, TransactionEnum::COUPON_CODE->value]);
            })
            ->when($deposit_flow_limit == 0 && $event_flow_limit == 1, function ($query) {
                // 只顯示活動稽核流水，儲值稽核流水不顯示
                $query->whereNotIn('type', [TransactionEnum::DEPOSIT->value, TransactionEnum::ORDER_DEPOSIT->value, TransactionEnum::MEMBER_TRANSFER_IN->value, TransactionEnum::COUPON_CODE->value]);
            })
            ->select(
                DB::raw('IFNULL(SUM(flow_limit), 0) AS flow_limit'),
                DB::raw('IFNULL(SUM(reach), 0) AS reach'),
            )
            ->first();

        $return = [
            'flow_limit' => $flowLimit->flow_limit,
            'reach' => $flowLimit->reach,
            'pass' => $flowLimit->flow_limit > $flowLimit->reach ? 0 : 1,
        ];

        return $return;
    }

    /**
     * 取得轉帳的流水限制
     * 檢查剩餘流水需小於提款後錢包餘額
     */
    public function getTransferChangeFlowLimit(Member $member): array
    {
        // 取得流水限制
        $flowLimit = FlowLimit::query()
            ->where('member_id', $member->id)
            ->where('status', 0)
            ->where('type', '!=', TransactionEnum::PROMOTION_WALLET_REWARD->value)
            ->selectRaw('IFNULL(SUM(flow_limit), 0) AS flow_limit, IFNULL(SUM(reach), 0) AS reach')
            ->first();

        $return = [
            'flow_limit' => $flowLimit->flow_limit,
            'reach' => $flowLimit->reach,
        ];

        return $return;
    }

    /**
     * 取得個人資料綁定修改紀錄     *
     */
    public function getBindingLog(Member $member, MemberBindingLogTypeEnum $type, int $days = 7): ?MemberBindingLog
    {
        $bindingLog = MemberBindingLog::query()
            ->where('member_id', $member->id)
            ->where('type', $type->value)
            ->where('created_at', '>=', Carbon::now()->subDays($days)->toDateTimeString())
            ->where('status', 1)
            ->first();

        return $bindingLog;
    }

    /**
     * 檢查個人資料綁定修改紀錄
     */
    public function checkBindingLog(Member $member, MemberBindingLogTypeEnum $type, int $days = 7): void {}

    /**
     * @return string[]
     */
    public function nicknameProhibitedWords(Agent $agent): array
    {
        $prohibitedWords = $this->agentService->getSpecificAgentSetting($agent, AgentCustomizedSettingKeyEnum::NICKNAME_PROHIBITED_WORDS);
        if (empty($prohibitedWords)) {
            return ['customer', 'service', 'vworld', 'agent', 'a g e n t', 'v w o r l d', 's e r v i c e', '代理', '客服', '代理', '客服'];
        }

        $settingValues = explode(',', $prohibitedWords);
        $invalid_nicknames = $settingValues;
        $invalid_nicknames = array_diff($invalid_nicknames, ['']);

        return $invalid_nicknames;
    }

    /**
     * 檢查手機號是否已超過允許綁定的次數
     */
    public function hasReachedMobileBindingLimit(string $mobile, Agent $agent, int $excludeMemberId = 0): bool
    {
        // 3970:上限值改讀 type=6「註冊/綁定限制」(phone);未勾選=不限制。計數口徑不變(僅計已驗證)
        $limit = $this->bindingLimitFor($agent, MemberRegisterLimitFieldEnum::PHONE, $mobile);
        if ($limit === null) {
            return false;
        }

        $count = Member::where('mobile', $mobile)
            ->whereHas('agent', function ($query) use ($agent) {
                $query->where('organization_id', $agent->organization_id);
                $query->where('binding_scope_id', $agent->binding_scope_id);
            })
            ->where('mobile', $mobile)
            ->where('mobile_validate', 1)
            ->when($excludeMemberId > 0, function ($query) use ($excludeMemberId) {
                $query->where('id', '!=', $excludeMemberId);
            })
            ->count();

        return $count >= $limit;
    }

    /**
     * 檢查Email是否已超過允許綁定的次數
     *
     * 計數範圍與手機一致：同 organization_id ＋ 同 binding_scope_id（同組織下以 scope 分站的品牌各算各的，
     * target/tickets#4309）、只計已驗證。
     */
    public function hasReachedEmailBindingLimit(string $email, Agent $agent): bool
    {
        // 3970:上限值改讀 type=6「註冊/綁定限制」(email);未勾選=不限制。計數口徑不變(僅計已驗證)
        $limit = $this->bindingLimitFor($agent, MemberRegisterLimitFieldEnum::EMAIL, $email);
        if ($limit === null) {
            return false;
        }

        $count = Member::onWriteConnection()
            ->whereHas('agent', function ($query) use ($agent) {
                $query->where('organization_id', $agent->organization_id);
                $query->where('binding_scope_id', $agent->binding_scope_id);
            })
            ->where('members.email', $email)
            ->where('members.email_validate', 1)
            ->count();

        return $count >= $limit;
    }

    public function isMemberExistsInVendor(int $memberId, string $vendorTname): int
    {
        try {
            // 不回傳 false，避免 phpredis cache miss
            return DB::table($vendorTname . '_accounts')
                ->where('member_id', $memberId)
                ->exists() ? 1 : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * 取得解除稽核流水限制申請狀態的
     * 如果有申請中的解除稽核流水限制，則返回 2
     *
     * @return int 0:未鎖定，1:鎖定，2:申請中
     */
    public function getFlowLimitApplicationStatus(Member $member): int
    {
        $flowLimitApplication = ClearFlowLimitApplication::where('member_id', $member->id)
            ->where('status', 0)
            ->first();

        if (is_object($flowLimitApplication)) {
            return 2;
        }

        return ($member->event_mode == 0) ? 0 : 1;
    }
}
