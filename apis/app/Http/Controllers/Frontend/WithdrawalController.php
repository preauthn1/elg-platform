<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\CurrencyEnum;
use App\Enums\General\HttpCode;
use App\Enums\MemberStatusLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\WithdrawalTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\Withdrawal\BindBankAccountRequest;
use App\Http\Requests\Frontend\Withdrawal\CheckBankAccountRequest;
use App\Http\Requests\Frontend\Withdrawal\ConfirmPaymentPasswordRequest;
use App\Http\Requests\Frontend\Withdrawal\CreateOrderRequest;
use App\Http\Requests\Frontend\Withdrawal\getBanksRequest;
use App\Http\Requests\Frontend\Withdrawal\GetOrdersRequest;
use App\Http\Resources\Frontend\BankResource;
use App\Http\Resources\Frontend\FlowLimitResource;
use App\Http\Resources\Frontend\Withdrawal\CheckResource;
use App\Http\Resources\Frontend\Withdrawal\WithdrawalOrderResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Models\Agent;
use App\Models\Bank;
use App\Models\CustomerAuditTime;
use App\Models\Member;
use App\Models\MemberBankAccount;
use App\Models\TransactionLog;
use App\Models\WithdrawalOrder;
use App\Services\AgentService;
use App\Services\LargeAmountNotifyService;
use App\Services\MemberAccessTokenService;
use App\Services\MemberFailCountService;
use App\Services\MemberLimitResolver;
use App\Services\MemberService;
use App\Services\WithdrawalFeeResolver;
use App\Services\WithdrawalService;
use App\SP\SP_cancel_login_event;
use App\SP\SP_check_login_event;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

#[Group('Withdrawal (洗分/取款)')]
/**
 * @knowledge rules/withdrawal-member-fee
 */
class WithdrawalController extends Controller
{
    public function __construct(
        protected AgentService $agentService,
        protected MemberService $memberService,
        protected WithdrawalService $withdrawalService,
        protected LargeAmountNotifyService $largeAmountNotifyService,
        protected MemberAccessTokenService $memberAccessTokenService,
        protected MemberLimitResolver $memberLimitResolver,
        protected MemberFailCountService $memberFailCountService,
        protected WithdrawalFeeResolver $withdrawalFeeResolver,
    ) {}

    /**
     * 取得洗分紀錄
     */
    public function getOrders(GetOrdersRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();
        $member = $userData->member;
        $memberId = $member->id;
        $languageId = $userData->languageId;
        $page = $input['page'] ?? 1;
        $per_page = $input['per_page'] ?? 15;

        $data = WithdrawalOrder::query()
            ->with('bank', function ($query) use ($languageId) {
                $query->with('names', function ($query) use ($languageId) {
                    $query->where('language_id', $languageId);
                });
            })
            ->where('member_id', $memberId)
            ->orderBy('id', 'DESC')
            ->paginate($per_page);

        return ApiResponse::successWithPagination(
            data: WithdrawalOrderResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    /**
     * 取得取款限制條件
     */
    public function getFlowLimit()
    {
        $userData = $this->getUserContext();

        $member = $userData->member;
        $agent = $userData->agent;

        // 取得流水限制
        $flowLimit = $this->memberService->getFlowLimit($member);

        $return = [
            'flow_limit' => $flowLimit['flow_limit'],
            'reach' => $flowLimit['reach'],
            'pass' => $flowLimit['pass'],
            'message' => __('error_message.1980'),
        ];

        $resource = FlowLimitResource::make($return);

        return ApiResponse::success($resource);
    }

    /**
     * 檢查是否有綁定銀行帳號
     *
     * 建議拆成兩個 API
     * 1. 檢查是否可以提款
     * 2. 取得用戶綁定銀行帳號列表
     *
     * @todo 複雜度過高
     */
    public function checkBankAccount(CheckBankAccountRequest $request)
    {
        $params = $request->validated();
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;
        $memberId = $member->id;
        $agentId = $agent->id;

        // 取得代理組別設置
        $agentSettings = $this->agentService->getAgentSettings($agent);

        // 取得提領限額
        $amount_limit = $this->agentService->getWithdrawalAmountLimit($agent);
        $max_amount = $amount_limit['max_amount'];
        $min_amount = $amount_limit['min_amount'];

        // 取當天可提領次數，0代表無限制
        $withdrawalLimit = $agentSettings[AgentCustomizedSettingKeyEnum::WITHDRAWAL_LIMIT];

        // 有群組：基礎次數改抓「代理 ∩ 群組 取低」（不限制的層不參與），VIP 仍於下方額外加總；
        // $groupCountBase===0 代表群組設 0（禁止提款），於下方直接轉跳（原「>0 才檢查」慣例無法表達 0）
        $groupCountBase = $this->memberLimitResolver->usesMemberGroupLimit($member)
            ? $this->memberLimitResolver->withdrawCountBaseLimit($member, $agentSettings)
            : null;
        if ($groupCountBase !== null) {
            $withdrawalLimit = $groupCountBase;
        }

        // 取得當天已提領次數
        $withdrawalCount = $this->memberService->getTodayWithdrawalCount($member);

        // 檢查提款是否需要綁定手機號
        $checkMobile = (bool) ($agentSettings[AgentCustomizedSettingKeyEnum::WITHDRAWAL_HAS_MOBILE_VALIDATE] ?? false);
        if ($checkMobile) {
            // 確認玩家是否有手機支付：菲律賓的電子錢包（GCash 類）提款要綁手機。
            // 幣別看 PHP(22)，不看 GCASH(12)——GCASH 是付款方式不是幣別，多幣別決議㉛ 會把它
            // 從幣別主檔清掉（docs/plans/2026-08-13-multi-currency-wallet/multi-currency-wallet-decisions.md）。
            // ⚠️ 不能只看 type：type=2 是所有電子錢包（含 PayNow、印尼盾電子錢包），
            //    只看 type 會讓 Alfawin77／Yes2Win 開了本設定的組別突然開始要求綁手機（2026-09-18 實查）。
            $mobileBanks = Bank::query()
                ->where('agent_group_id', $agent->cash_flow_group_id)
                ->where('type', WithdrawalTypeEnum::GCASH->value)
                ->where('currency_id', CurrencyEnum::PHP->value)
                ->where('status', 1)
                ->orderBy('sort', 'desc')
                ->get();

            if ($mobileBanks->count() > 0) {
                // 玩家未通過手機認證
                if ($member->mobile_validate != 1 || $member->mobile == '') {
                    return ApiResponse::error(
                        message: __('error_message.9007'),
                        code: HttpCode::HTTP_REDIRECT_SET_MOBILE->value,
                        data: [
                            'member_id' => $member->id,
                        ]
                    );
                }
            }
        }

        // 檢查 VIP 提款配置
        $vip_tax = 0;
        $has_vip = $agentSettings[AgentCustomizedSettingKeyEnum::VIP_VISIBLE] ?? 0;
        if ($has_vip == 1) {
            // 取自己營運商、營運商上線及平台的VIP內容
            $vip_info = $this->memberService->getVipContent($member);

            // vip 提領次數是額外加上代理組別的提領次數，並非覆蓋
            if ($withdrawalLimit > 0) {
                $withdrawalLimit += $vip_info->withdrawal_times_limit;
            }
            $max_amount = $vip_info->withdrawal_amount_limit;
            $vip_tax = $vip_info->tax;
        }

        // 群組設 0（禁止提款）→ 直接轉跳（VIP 額外加總在上方已因 $withdrawalLimit 非 >0 而略過）
        if ($groupCountBase === 0) {
            return ApiResponse::error(
                message: __('error_message.1956'),
                code: HttpCode::HTTP_REDIRECT_MY->value,
            );
        }

        if ($withdrawalLimit > 0) {
            if ($withdrawalCount >= $withdrawalLimit) {
                // 通知前端轉跳找回密碼
                return ApiResponse::error(
                    message: __('error_message.1956'),
                    code: HttpCode::HTTP_REDIRECT_MY->value,
                );
            }
        } else {
            $withdrawalLimit = null;
        }

        $country_code = null;
        $mobile = null;

        if ($member->mobile != '') {
            // 取得手機號碼&國碼
            $mobileArr = $this->memberService->getMobileAndCountryCode($member);
            $country_code = $mobileArr['country_code'];
            $mobile = $mobileArr['mobile'];
        }

        // issue 1136: 充值獎勵未領取前，洗分與發紅包後應該要被取消資格
        $count = SP_check_login_event::handle($member, $agent);
        $has_login_event_reward = ($count > 0) ? 1 : 0;

        // return 格式
        $data = [
            'has_bank_card_setting' => $agentSettings[AgentCustomizedSettingKeyEnum::HAS_BANK_CARD_SETTING],
            'has_bank_card' => 0,
            'bank_card_amount' => $agentSettings[AgentCustomizedSettingKeyEnum::BANK_CARD_AMOUNT],
            'country_code' => $country_code,
            'mobile' => $mobile,
            'account_name' => $member->first_name,
            'account' => null,
            'min_amount' => $min_amount,
            'max_amount' => $max_amount,
            'is_first' => ($member->first_name == '') ? 1 : 0,
            'account_name_modify' => $agentSettings[AgentCustomizedSettingKeyEnum::MODIFY_AUTO_WITHDRAWAL_ACCOUNT_NAME_VISIBLE],
            'has_login_event_reward' => $has_login_event_reward,
            'withdrawal_times' => $withdrawalCount,
            'withdrawal_times_limit' => $withdrawalLimit,
            'over_times' => 0, // 作廢, 直接返回 exception
            'vip_tax' => $vip_tax,
        ];

        $query = MemberBankAccount::join('banks as b', function ($join) {
            $join->on('b.id', 'member_bank_accounts.bank_id')
                ->where('b.status', 1);
        })
            ->where('member_id', $memberId)
            ->where('member_bank_accounts.status', 1)
            ->select(
                'b.id as bank_id',
                'b.name as bank_name',
                'b.code as bank_code',
                'b.type as bank_type',
                'b.rate as bank_rate',
                DB::raw('CASE WHEN b.min_amount = 0 THEN ' . $data['min_amount'] . ' ELSE b.min_amount END as min_amount'),
                DB::raw('CASE WHEN b.max_amount = 0 THEN ' . $data['max_amount'] . ' ELSE b.max_amount END as max_amount'),
                'b.tax_type as tax_type',
                'b.tax as bank_tax',
                'member_bank_accounts.account_name as account_name',
                'member_bank_accounts.account_no as account_no'
            )
            ->orderBy('member_bank_accounts.sort', 'DESC')
            ->orderBy('member_bank_accounts.id', 'DESC');

        // AgentCustomizedSettingKeyEnum::HAS_BANK_CARD_SETTING == 0 代表不篩選銀行, 不設置數量
        if ($agentSettings[AgentCustomizedSettingKeyEnum::HAS_BANK_CARD_SETTING] == 0) {
            $account = $query->get();
        } else {
            // type 預設本地銀行
            $type = $request->input('type', 1);

            // type == -1 代表不限制銀行類型
            // 目前設定幾組就顯示幾組
            $account = $query
                ->when($type != -1, fn ($q) => $q->where('b.type', $type))
                ->take($agentSettings[AgentCustomizedSettingKeyEnum::BANK_CARD_AMOUNT])
                ->get();
        }

        if (count($account) > 0) {
            // 會員群組提款限額（顯示用）：base（原 SQL 算出的 amount） ∩ 群組 ∩ VIP 依各銀行匯率取嚴；無群組時零改變
            foreach ($account as $row) {
                $row->min_amount = $this->memberLimitResolver->withdrawFloorAmount($member, (string) $row->min_amount, $row->bank_rate);
                $row->max_amount = $this->memberLimitResolver->withdrawCeilingAmount($member, (string) $row->max_amount, $row->bank_rate);
            }

            $data['has_bank_card'] = 1;
            $data['account'] = $account;
        }

        $resource = CheckResource::make($data);

        return ApiResponse::success($resource);
    }

    /**
     * 綁定銀行帳號
     * AgentCustomizedSettingKeyEnum::HAS_BANK_CARD_SETTING 開啟時，同個銀行不可修改帳號
     */
    public function bindBankAccount(BindBankAccountRequest $request)
    {
        $params = $request->validated();
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;
        $accounts = $params['account'];

        $agentSetting = $this->agentService->getAgentSettings($agent);

        DB::transaction(function () use ($accounts, $member, $agentSetting, $agent) {
            foreach ($accounts as $row) {
                // 只能綁會員自己看得到的銀行；條件與 banks() 清單端相同
                $this->findSelectableBank($agent, (int) $row['bank_id']);

                $account = MemberBankAccount::where('member_id', $member->id)
                    ->where('bank_id', $row['bank_id'])
                    ->where('status', 1)
                    ->orderBy('sort', 'desc')
                    ->first();
                // 若輸入真實姓名為空，則使用真實姓名 > 會員帳號
                $account_name = $row['account_name'] ?? '';
                if (empty($account_name)) {
                    $account_name = $member->first_name;
                    if (empty($account_name)) {
                        $account_name = $member->username;
                    }
                }

                /** @var MemberBankAccount $account */
                if ($account) {
                    $account->account_name = $account_name;
                    $account->account_no = trim((string) $row['account_no']);

                    if ($agentSetting[AgentCustomizedSettingKeyEnum::HAS_BANK_CARD_SETTING] == 0) {
                        $account->save();
                    } else {
                        // 不可以更改
                        if ($account->isDirty()) {
                            throw new ApiException(__('error_message.9020'));
                        }
                    }
                } else {
                    $data['member_id'] = $member->id;
                    $data['operator_cs'] = 0;

                    if ($agentSetting[AgentCustomizedSettingKeyEnum::HAS_BANK_CARD_SETTING] == 1) {
                        // 如果是開啟銀行卡的要檢查同個組織內不重復卡號
                        $checkAccountNo = DB::table('member_bank_accounts as mba')
                            ->join('members as m', 'm.id', 'mba.member_id')
                            ->join('agents as a', 'a.id', 'm.parent')
                            ->where('mba.bank_id', $row['bank_id'])
                            ->where('mba.account_no', $row['account_no'])
                            ->where('a.organization_id', $agent->organization_id)
                            ->where('a.binding_scope_id', $agent->binding_scope_id)
                            ->exists();

                        if ($checkAccountNo) {
                            throw new ApiException(__('error_message.9017'));
                        }
                    }

                    $data['bank_id'] = $row['bank_id'];
                    $data['account_name'] = $account_name;
                    $data['account_no'] = trim((string) $row['account_no']);
                    $data['status'] = 1;

                    MemberBankAccount::create($data);
                }
            }
        });

        return ApiResponse::success();
    }

    /**
     * 洗分銀行列表
     */
    public function getBanks(getBanksRequest $request)
    {
        $userData = $this->getUserContext();

        $languageId = $userData->languageId;
        $cashFlowGroupId = $userData->agent->cash_flow_group_id;
        $agent = $userData->agent;

        // 取得 API 提領限額預設值
        $amount_limit = $this->agentService->getWithdrawalAmountLimit($agent);
        $defaultMinAmount = $amount_limit['min_amount'];
        $defaultMaxAmount = $amount_limit['max_amount'];

        $list = Bank::query()
            ->when($request->type, function ($query) use ($request) {
                $query->where('type', $request->type);
            })
            ->with([
                'withdrawalType' => function ($query) use ($languageId) {
                    $query->with('names', function ($query) use ($languageId) {
                        $query->where('language_id', $languageId);
                    });
                },
                'names' => function ($query) use ($languageId) {
                    $query->where('language_id', $languageId);
                },
                'currency',
            ])
            ->with('currency')
            ->where('agent_group_id', $cashFlowGroupId)
            ->where('status', 1)
            ->orderByDesc('sort')
            ->get();

        foreach ($list as $key => $row) {
            $row->min_amount = $row->min_amount > 0 ? $row->min_amount : $defaultMinAmount;
            $row->max_amount = $row->max_amount > 0 ? $row->max_amount : $defaultMaxAmount;
        }

        // 會員群組提款限額（顯示用）：base ∩ 群組 ∩ VIP 依各銀行匯率取嚴，無群組時原值零改變
        foreach ($list as $row) {
            $row->min_amount = $this->memberLimitResolver->withdrawFloorAmount($userData->member, (string) $row->min_amount, $row->rate);
            $row->max_amount = $this->memberLimitResolver->withdrawCeilingAmount($userData->member, (string) $row->max_amount, $row->rate);
        }

        // 下面這段依 VIP 過濾代付方式、覆蓋代付最大金額的邏輯停用；舊系統 API_Frontend 的同一段也已註解掉。
        // 會員有會員群組時，VIP 的單筆上限另由上方 withdrawCeilingAmount() 併入。
        // 取得代理設定, 是否啟用 VIP
        // $setting = $this->agentService->getAgentSetting($userData->agent);
        // $hasVip = $setting[AgentCustomizedSettingKeyEnum::VIP_VISIBLE] ?? 0;

        // if ($hasVip == 1) {
        //     $vipWithdrawalTypeIds = [];
        //     $vipContent = $this->memberService->getVipContent($userData->member);

        //     // 啟用 VIP 但沒查到 VIP 設定
        //     if (!$vipContent) {
        //         return ApiResponse::success();
        //     }

        //     $vipWithdrawalTypeIds = $vipContent->withdrawalTypes->pluck('withdrawal_type_id')->toArray();
        //     $vipMaxWithdrawalAmount = $vipContent->withdrawal_amount_limit;

        //     // 過濾代付方式
        //     $list = $list->filter(function ($row) use ($vipWithdrawalTypeIds) {
        //         return in_array($row->type, $vipWithdrawalTypeIds);
        //     });

        //     // 覆蓋代付最大金額
        //     foreach ($list as $key => $row) {
        //         $row->max_amount = floor(((float) $vipMaxWithdrawalAmount / (float) $row->rate) * 100) / 100;
        //     }
        // }

        $resource = BankResource::collection($list);

        return ApiResponse::success($resource);
    }

    /**
     * 確認會員支付密碼
     */
    public function confirmPaymentPassword(ConfirmPaymentPasswordRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();
        $member = $userData->member;

        if (!Hash::check($input['payment_password'], $member->payment_password)) {
            return $this->handlePaymentPasswordFailure($member);
        }

        $statistics = $member->statistics()->firstOrCreate([]);
        if ($statistics->payment_pwd_fail_count > 0) {
            $statistics->payment_pwd_fail_count = 0;
            $statistics->save();
        }

        return ApiResponse::success();
    }

    /**
     * 支付密碼錯誤處理：累計次數，達上限時鎖定或導向找回支付密碼（上限 -1 僅累計不鎖帳）
     *
     * 計次在會員鎖內完成，併發失敗不會少算（見 MemberFailCountService）。鎖帳刻意沿用 lockMemberAndRevoke()：
     * 作廢 token 失敗時鎖定一起回滾，不改成「作廢失敗仍鎖住」。呼叫端一定持有 token，作廢 token 比其他三條重要，
     * 要先有保證會重試作廢的機制才能改。
     */
    private function handlePaymentPasswordFailure(Member $member)
    {
        $failCount = $this->memberFailCountService->increase($member, MemberStatusLogTypeEnum::PAYMENT_PASSWORD_LIMIT);

        $agentSettings = $this->agentService->getAgentSettings($member->agent);
        $paymentPwdFailCountLimit = (int) ($agentSettings[AgentCustomizedSettingKeyEnum::PAYMENT_PWD_FAIL_COUNT_LIMIT] ?? -1);
        $enableRecoverPaymentPassword = [REDACTED] ($agentSettings[AgentCustomizedSettingKeyEnum::ENABLE_RECOVER_PAYMENT_PASSWORD] ?? false);

        if ($paymentPwdFailCountLimit === -1) {
            return ApiResponse::error(__('error_message.90052'));
        }

        if ($failCount >= $paymentPwdFailCountLimit) {
            if ($enableRecoverPaymentPassword) {
                if (
                    (!empty($member->mobile) && $member->mobile_validate == 1)
                    || (!empty($member->email) && $member->email_validate == 1)
                ) {
                    return ApiResponse::error(
                        message: __('error_message.90052'),
                        code: HttpCode::HTTP_REDIRECT_RESET_PAYMENT_PASSWORD->value,
                    );
                }
            }
            $this->memberAccessTokenService->lockMemberAndRevoke($member, MemberStatusLogTypeEnum::PAYMENT_PASSWORD_LIMIT);

            return ApiResponse::error(
                message: __('error_message.90062'),
                code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
            );
        }

        return ApiResponse::error(__('error_message.90052'));
    }

    /**
     * 建立洗分訂單
     */
    public function createOrder(CreateOrderRequest $request)
    {
        $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;
        $memberId = $member->id;
        $agent = $userData->agent;
        $agentSettings = $this->agentService->getAgentSettings($agent);

        // 鎖分模式中禁止提款
        if ($member->event_mode > 0) {
            return ApiResponse::error(__('error_message.1934'));
        }

        // 檢查提款是否需要綁定手機號
        $checkMobile = (bool) ($agentSettings[AgentCustomizedSettingKeyEnum::WITHDRAWAL_HAS_MOBILE_VALIDATE] ?? false);
        if ($checkMobile) {
            if (!$member->mobile_validate || !$member->mobile) {
                throw new ApiException(__('error_message.9007'), HttpCode::HTTP_REDIRECT_SET_MOBILE->value);
            }
        }

        /** @var array $result */
        $result = DB::transaction(function () use ($memberId, $agent, $request, $agentSettings) {
            $now = Carbon::now()->toDateTimeString();
            /** @var Member $member */
            $member = Member::lockForUpdate()->find($memberId);

            // 檢查風控會員限制:提款鎖定
            $this->withdrawalService->checkMemberRestriction($member);

            // 活動 V2 進行中禁止提款
            $this->withdrawalService->checkEventV2Restriction($member);

            // 檢查流水
            $this->withdrawalService->checkFlowLimit($member, $agentSettings);

            // 檢查近期是否修改過個人資料
            $this->withdrawalService->checkBindingLog($member, $agentSettings);

            // 檢查真實姓名
            $this->withdrawalService->checkRealname($member, $agentSettings);

            // 若輸入真實姓名為空，則使用真實姓名 > 會員帳號
            $account_name = $request->account_name ?? '';
            if (empty($account_name)) {
                $account_name = $member->first_name;
                if (empty($account_name)) {
                    $account_name = $member->username;
                }
            }

            // 檢查提領次數
            $this->withdrawalService->checkWithdrawalTimes($member, $agentSettings);

            $bank = $this->findSelectableBank($agent, (int) $request->bank_id);

            // 檢查當日累計提領金額是否超過 VIP 設定上限
            $this->withdrawalService->checkDailyWithdrawalAmountLimit($member, $bank, $request->amount, $agentSettings);

            // 檢查餘額
            $this->withdrawalService->checkBalance($member, $bank, $request->amount);

            // 檢查剩餘流水需小於提款後錢包餘額
            $this->withdrawalService->checkFlowLimitBalance($member, $bank, $request->amount, $agentSettings);

            // 檢查自助洗分的提款金額限制（帶入 $member：有群組時方法內改走取嚴接管）
            $this->withdrawalService->checkWithdrawalAmountLimit($agent, $bank, $request->amount, $member);

            // 檢查 Fonpay 提款
            $this->withdrawalService->checkFonpay($member, $bank, $request->account_no);

            // 手續費：會員群組平台手續費階梯（依當日第幾筆）優先，未設定則沿用銀行 target/tickets#4353
            // 會員鎖內、同交易計數，同會員並發已由 lockForUpdate 序列化
            $seq = $this->memberService->getTodayWithdrawalCount($member) + 1;
            $fee = $this->withdrawalFeeResolver->resolve($member, $bank, (string) $request->amount, $seq);

            // 計算提款金額
            $point = $request->amount * $bank->rate;
            $before_balance = $member->balance;
            $balance = ($member->balance - $point);
            $member->update(['balance' => $balance]);

            // TODO hardcode memo
            // #2958 LegendPay IFSC 的額外欄位紀錄在 memo
            $memo = null;
            if ($bank->code == 'IFSC') {
                $extraFields = [
                    'mobile' => trim((string) ($request->mobile ?? '')),
                    'ifsc' => trim((string) ($request->ifsc ?? '')),
                ];

                if ($extraFields['mobile'] === '' || $extraFields['ifsc'] === '') {
                    throw new ApiException(__('error_message.1315'));
                }
                $memo = json_encode($extraFields);
            }

            $order = WithdrawalOrder::create([
                'bank_id' => $bank->id,
                'bank_name' => $bank->name ?? $request->bank_name,
                'bank_code' => $bank->code ?? $request->bank_code,
                'account_name' => $account_name,
                'account_no' => trim((string) $request->account_no),
                'agent_group_id' => $agent->cash_flow_group_id,
                'member_id' => $member->id,
                'amount' => $request->amount,
                'point' => $point,
                'rate' => $bank->rate,
                'tax' => $fee->tax,
                'withdraw_seq' => $seq,
                'tax_source' => $fee->source->value,
                'third_party_order_no' => '',
                'status' => 1,
                'change_logs' => '',
                'memo' => $memo,
            ]);

            Log::info('Withdrawal Order Created', [
                'order_id' => $order->id,
                'member_id' => $member->id,
                'amount' => $request->amount,
                'point' => $point,
                'balance_before' => $before_balance,
                'balance_after' => $balance,
                'withdraw_seq' => $seq,
                'tax_source' => $fee->source->value,
            ]);

            TransactionLog::create([
                'transaction_no' => Common::getTransactionNo(),
                'agent_id' => $member->parent,
                'member_id' => $member->id,
                'order_id' => $order->id,
                'point' => $point,
                'vendor_point' => 0,
                'rate' => 0,
                'before_balance' => $before_balance,
                'balance' => $balance,
                'type' => TransactionEnum::WITHDRAWAL->value,
                'operator' => $member->username,
                'status' => TransactionStatusEnum::STATUS_REVIEW->value, // 未審核前 狀態先保持在9
                'admin' => $member->id,
            ]);

            // 訂單審核
            CustomerAuditTime::create([
                'order_id' => $order->id,
                'cash_flow_id' => 6, // 自助洗分
                'type' => 1,
                'operator_time' => $now,
                'operator' => $member->id,
            ]);
            // 成功建立訂單後, redis增加未處理的訂單數
            $this->withdrawalService->setRedisNotice($agent);

            // issue 1136: 取消充值獎勵資格
            SP_cancel_login_event::handle($member, $agent);

            return [
                'point' => $point,
                'balance' => $balance,
            ];
        });

        // 今日累積提款通知
        $this->withdrawalService->notifyDailyWithdrawalOverLimit($member, $agent, $agentSettings, $result['balance']);
        // 單次大額提款通知
        $this->largeAmountNotifyService->notifyWithdrawal($member, $agent, $result['point'], $result['balance']);

        return ApiResponse::success();
    }

    /**
     * 取一家「這個會員本來就看得到」的提款銀行。看不到就拒絕。
     *
     * 提款頁面上，會員只會看到自己代理所屬支付組別（`cash_flow_group_id`）底下、而且還開著的銀行。
     * 這個方法和那個清單（`getBanks()`）共用同一組支付組別與啟用狀態條件：
     * `->where('agent_group_id', $cashFlowGroupId)->where('status', 1)`。清單另外可以依請求帶的
     * 提款類型（type）篩選，這個方法不看類型；所以送得出去的銀行，一定在會員的支付組別內、而且開著。
     *
     * 為什麼要這道檢查：如果建單直接用 `Bank::find($request->bank_id)`，就是「請求送什麼編號就拿什麼」。
     * 拿到的那一列銀行，它的 rate 欄位（入帳比例：提款金額要乘上多少，才是要扣的點數）直接決定
     * 會員被扣多少點：`$point = $request->amount * $bank->rate`；min_amount／max_amount 則決定
     * 單筆上下限。同一種幣別底下，不同銀行的入帳比例差好幾倍（VWorld 唯讀實查：馬幣 0.23～1.0、
     * 新幣 1.0～3.59），所以只要換一列銀行，「實際扣的點數」和「申請的提款金額」就對不起來了。
     *
     * 綁定銀行帳號（bindBankAccount()）也用同一個方法：它本身不會馬上少錢，但那是「先綁、再拿它
     * 去提款」的第一步。兩邊用同一組條件，才不會一邊鬆一邊緊。
     *
     * 找不到時，不管是「這個編號根本不存在」還是「存在但不是你的」，都回同一個錯誤訊息。
     * 這是刻意的：訊息如果分得太細，等於告訴對方「這個編號是別人的」，可以被拿來一個一個
     * 試出別的支付組別有哪些銀行。
     *
     * @throws ApiException error_message.1315 訂單參數錯誤
     */
    private function findSelectableBank(Agent $agent, int $bankId): Bank
    {
        $bank = Bank::query()
            ->where('id', $bankId)
            ->where('agent_group_id', $agent->cash_flow_group_id)
            ->where('status', 1)
            ->first();

        if (!$bank instanceof Bank) {
            throw new ApiException(__('error_message.1315'));
        }

        return $bank;
    }
}
