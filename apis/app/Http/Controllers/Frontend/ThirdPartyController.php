<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Data\SmsGatewayInfoData;
use App\Data\TransactionLogOptionalData;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Agent\AgentPersonalSettingKeyEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\MemberTransferLogStatusEnum;
use App\Enums\SmsGatewayEnum;
use App\Enums\Statistics\MemberHourlySourceEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Events\Agent\AgentLoginFailed;
use App\Events\Agent\AgentLoginSucceeded;
use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\ThirdParty\AgentInfoRequest;
use App\Http\Requests\Frontend\ThirdParty\AgentLoginReportRequest;
use App\Http\Requests\Frontend\ThirdParty\AgentTransactionRequest;
use App\Http\Requests\Frontend\ThirdParty\CheckAgentTokenRequest;
use App\Http\Requests\Frontend\ThirdParty\CheckCodeRequest;
use App\Http\Requests\Frontend\ThirdParty\CheckFlowLimitRequest;
use App\Http\Requests\Frontend\ThirdParty\CheckPasswordRequest;
use App\Http\Requests\Frontend\ThirdParty\CsSettingRequest;
use App\Http\Requests\Frontend\ThirdParty\GetBankListRequest;
use App\Http\Requests\Frontend\ThirdParty\GetLoginSettingRequest;
use App\Http\Requests\Frontend\ThirdParty\GoogleAuthRequest;
use App\Http\Requests\Frontend\ThirdParty\InfoRequest;
use App\Http\Requests\Frontend\ThirdParty\LiveChatRequest;
use App\Http\Requests\Frontend\ThirdParty\MemberTransferRequest;
use App\Http\Requests\Frontend\ThirdParty\NewAgentTransactionRequest;
use App\Http\Requests\Frontend\ThirdParty\SendSmsRequest;
use App\Http\Requests\Frontend\ThirdParty\TransactionRequest;
use App\Jobs\UpdateMemberHourSummary;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentAuthentication;
use App\Models\AgentGroup;
use App\Models\Bank;
use App\Models\FlowLimit;
use App\Models\LiveChatAccount;
use App\Models\Member;
use App\Models\MemberBindingLog;
use App\Models\MemberTransferLog;
use App\Models\SmsGatewayGroupDetail;
use App\Models\SmsGatewayInfo;
use App\Models\SmsText;
use App\Models\TransactionLog;
use App\Models\TransactionLogAgent;
use App\Models\ValidateToken;
use App\Models\VipFlowLimit;
use App\Models\WalletTransactionLog;
use App\Services\AgentService;
use App\Services\LiveChatService;
use App\Services\MemberLimitResolver;
use App\Services\MemberService;
use App\Services\RealnameValidationService;
use App\Services\SmsGatewayService;
use App\Services\TransactionLogCreateService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

#[Group('ThirdParty (第三方 - 內部使用)')]
class ThirdPartyController extends Controller
{
    public function __construct(
        private AgentService $agentService,
        private MemberService $memberService,
        private LiveChatService $liveChatService,
        private MemberLimitResolver $memberLimitResolver,
    ) {}

    public function info(InfoRequest $request)
    {
        $member = DB::table('members as m')
            ->join('agents as a', 'a.id', 'm.parent')
            ->where('m.username', $request->username)
            ->select(
                'm.id',
                'm.username',
                'm.nickname',
                'm.mobile',
                'm.image',
                'm.balance',
                'm.device_id',
                DB::raw('CASE WHEN m.event_mode = 0 THEN 0 ELSE 1 END AS event_mode'),
                'm.realname_validate',
                'a.transaction_all',
                'a.customized_setting_group_id',
                'a.hierarchy_path'
            )
            ->first();

        if (!is_object($member)) {
            throw new ApiException(__('error_message.1007'), 1007);
        }

        // $member 是 DB::table 查出來的 stdClass，沒有 Member model 的 is_event_v2 accessor，
        // 這裡比照 Member::isEventV2() 的邏輯（eventV2Joins 是否有 status=JOIN 的紀錄）自行查一次
        $isEventV2 = DB::table('event_v2_user_joins')
            ->where('member_id', $member->id)
            ->where('status', EventV2UserJoinStatusEnum::JOIN->value)
            ->exists();

        $settingKeys = [
            AgentCustomizedSettingKeyEnum::FINANCIAL_RED_ENVELOPE_TAX,
            AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_BUTTON_VISIBLE,
            AgentCustomizedSettingKeyEnum::MEMBER_SEND_RED_ENVELOPE_BUTTON_VISIBLE,
            AgentCustomizedSettingKeyEnum::MEMBER_RECEIVE_RED_ENVELOPE_BUTTON_VISIBLE,
            AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE,
            AgentCustomizedSettingKeyEnum::DEPOSIT_FLOW_LIMIT_VISIBLE,
            AgentCustomizedSettingKeyEnum::EVENT_FLOW_LIMIT_VISIBLE,
            AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT,
            AgentCustomizedSettingKeyEnum::TRANSFER_WITHDRAW_REALNAME_REQUIRED,
        ];

        $settings = DB::table('agent_customized_settings')
            ->where('agent_group_id', $member->customized_setting_group_id)
            ->whereIn('setting_key', $settingKeys)
            ->pluck('value', 'setting_key');

        $flowLimitVisible = (int) ($settings[AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE] ?? 0);
        $depositFlowLimitVisible = (int) ($settings[AgentCustomizedSettingKeyEnum::DEPOSIT_FLOW_LIMIT_VISIBLE] ?? 0);
        $eventFlowLimitVisible = (int) ($settings[AgentCustomizedSettingKeyEnum::EVENT_FLOW_LIMIT_VISIBLE] ?? 0);
        $transferWithdrawRealnameRequired = (int) ($settings[AgentCustomizedSettingKeyEnum::TRANSFER_WITHDRAW_REALNAME_REQUIRED] ?? 0);

        $flowLimit = '0';
        if ($flowLimitVisible == 1) {
            $flowLimitQuery = FlowLimit::where('member_id', $member->id)->where('status', 0);

            if ($depositFlowLimitVisible == 1 && $eventFlowLimitVisible == 0) {
                $flowLimitQuery->whereIn('type', [TransactionEnum::DEPOSIT->value, TransactionEnum::ORDER_DEPOSIT->value, TransactionEnum::MEMBER_TRANSFER_IN->value]);
            } elseif ($depositFlowLimitVisible == 0 && $eventFlowLimitVisible == 1) {
                $flowLimitQuery->whereNotIn('type', [TransactionEnum::ORDER_DEPOSIT->value, TransactionEnum::DEPOSIT->value, TransactionEnum::MEMBER_TRANSFER_IN->value]);
            }

            $flowLimitRow = $flowLimitQuery->selectRaw('SUM(flow_limit) AS total_flow_limit, SUM(reach) AS total_reach')->first();

            if ($flowLimitRow && $flowLimitRow->total_flow_limit !== null) {
                $flowLimit = Common::numSub($flowLimitRow->total_flow_limit, $flowLimitRow->total_reach);
            }
            // 註：舊系統 API_Frontend 在這裡另有 transfer_change_flow_limit 分支，但寫入的是拼錯的 `flow_timit`
            // （不是 `flow_limit`），對外回應內容沒有實際影響，屬無害筆誤，這裡不實作。
        }

        return ApiResponse::customCode(1000, data: [
            'id' => $member->id,
            'username' => $member->username,
            'nickname' => $member->nickname,
            'mobile' => $member->mobile,
            'image' => $member->image,
            'balance' => $member->balance,
            'device_id' => $member->device_id,
            'event_mode' => $member->event_mode,
            'is_event_v2' => $isEventV2,
            'realname_validate' => $member->realname_validate,
            'transaction_all' => $member->transaction_all,
            'customized_setting_group_id' => $member->customized_setting_group_id,
            'hierarchy_path' => $member->hierarchy_path,
            'red_envelopes_tax' => (float) ($settings[AgentCustomizedSettingKeyEnum::FINANCIAL_RED_ENVELOPE_TAX] ?? 0),
            'payment_password_visible' => (int) ($settings[AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_BUTTON_VISIBLE] ?? 0),
            'member_send_red_envelope_visible' => (int) ($settings[AgentCustomizedSettingKeyEnum::MEMBER_SEND_RED_ENVELOPE_BUTTON_VISIBLE] ?? 0),
            'member_receive_red_envelope_visible' => (int) ($settings[AgentCustomizedSettingKeyEnum::MEMBER_RECEIVE_RED_ENVELOPE_BUTTON_VISIBLE] ?? 0),
            'flow_limit_visible' => $flowLimitVisible,
            'deposit_flow_limit_visible' => $depositFlowLimitVisible,
            'event_flow_limit_visible' => $eventFlowLimitVisible,
            'transfer_change_flow_limit' => (int) ($settings[AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT] ?? 0),
            'flow_limit' => $flowLimit,
            'realname_validate_passed' => $transferWithdrawRealnameRequired === 1 ? (int) $member->realname_validate : 1,
            'modify_binding' => $this->hasRecentMobileBindingChange((int) $member->id, (int) $member->customized_setting_group_id, days: 1),
            // 登入活動整組已廢除（target/tickets#4005，docs/ticket_4005/ticket_4005.md 的「登入活動」節）。
            // SP_check_login_event 查的未領登入獎勵屬該已廢除功能的殘留檢查，所以不呼叫該 SP，固定回傳 0。
            'has_login_event_reward' => 0,
        ]);
    }

    /**
     * 檢查會員是否在近 N 天內有過手機綁定變更紀錄。判斷邏輯與舊系統 API_Frontend 的
     * `App\Http\Controllers\ValidateTokenController::checkBindModify()` 相同：
     * 依代理設定 `MODIFY_BINDING_LIMIT_WITHDRAWAL` 決定是否啟用該限制。
     */
    private function hasRecentMobileBindingChange(int $memberId, int $customizedSettingGroupId, int $days = 7): bool
    {
        $enabled = DB::table('agent_customized_settings')
            ->where('agent_group_id', $customizedSettingGroupId)
            ->where('setting_key', AgentCustomizedSettingKeyEnum::MODIFY_BINDING_LIMIT_WITHDRAWAL)
            ->value('value');

        if ((int) ($enabled ?? 0) !== 1) {
            return false;
        }

        return MemberBindingLog::where('member_id', $memberId)
            ->where('created_at', '>=', now()->subDays($days))
            ->where('type', 1)
            ->where('status', 1)
            ->exists();
    }

    public function agentInfo(AgentInfoRequest $request)
    {
        $agent = Agent::where('username', $request->from)->first();

        if (!is_object($agent)) {
            throw new ApiException(__('error_message.1005'), 1005);
        }

        return ApiResponse::customCode(1000, data: [
            'balance' => $agent->balance,
            'is_balance_unlimited' => $agent->is_balance_unlimited,
        ]);
    }

    public function getLoginSetting(GetLoginSettingRequest $request)
    {
        $agent = Agent::where('username', $request->from)->first();

        if (!is_object($agent)) {
            throw new ApiException(__('error_message.1005'), 1005);
        }

        $settings = $this->agentService->getAgentSettings($agent);
        $exceptionAgentId = $agent->host_id == 0 ? $agent->id : $agent->host_id;
        $mobileValidateException = (string) ($settings[AgentCustomizedSettingKeyEnum::BACKEND_MOBILE_VALIDATE_EXCEPTION] ?? '');
        $exceptionAgentIds = explode(',', $mobileValidateException);

        return ApiResponse::customCode(1000, data: [
            'id' => $agent->id,
            'username' => $agent->username,
            'mobile' => $agent->mobile,
            'f2a_auth' => $settings[AgentCustomizedSettingKeyEnum::TWO_FACTOR_AUTHENTICATION_VISIBLE] ?? 0,
            'has_2fa' => $agent->has_2fa,
            'need_mobile_validate' => $settings[AgentCustomizedSettingKeyEnum::NEED_MOBILE_VALIDATE] ?? 0,
            'login_need_auth' => $settings[AgentCustomizedSettingKeyEnum::LOGIN_NEED_AUTHENTICATION] ?? 0,
            'mobile_validate_exception' => in_array((string) $exceptionAgentId, $exceptionAgentIds),
            'agent_pay_password' => $settings[AgentCustomizedSettingKeyEnum::AGENT_PAYMENT_PASSWORD_BUTTON_VISIBLE] ?? 0,
        ]);
    }

    /**
     * 客服後台回報代理登入失敗（target/tickets#4535）：客服後台與代理後台共用同一套登入錯誤計數
     * （agents.login_fail_count）與限制登入（agents.sys_lock），鎖定後由代理管理的「解除限制登入」解除。
     *
     * 直接派發代理後台既有的 AgentLoginFailed 事件，累加、達上限鎖定與 status log 皆由
     * AgentLoginRestrictionService::recordLoginFailure() 處理（停用或已限制登入的代理不計數）。
     *
     * 參數用 from（代理帳號）而非 username：third-party 群組的 ThirdPartyAuthMember 會把 username
     * 當會員帳號查，帶 username 會被擋下。路由已排除 CheckAgentMaintain，代理前台維護期間回報照常計數。
     */
    public function agentLoginFailed(AgentLoginReportRequest $request)
    {
        $agent = Agent::where('username', $request->from)->first();

        if (!is_object($agent)) {
            throw new ApiException(__('error_message.1005'), 1005);
        }

        AgentLoginFailed::dispatch($agent->id);

        return ApiResponse::customCode(1000);
    }

    /**
     * 客服後台回報代理登入成功（target/tickets#4535）：歸零與代理後台共用的連續登入錯誤次數。
     *
     * 直接派發代理後台既有的 AgentLoginSucceeded 事件，由 AgentLoginRestrictionService::resetLoginFailCount() 處理。
     *
     * 參數用 from（代理帳號）而非 username：third-party 群組的 ThirdPartyAuthMember 會把 username
     * 當會員帳號查，帶 username 會被擋下。路由已排除 CheckAgentMaintain，代理前台維護期間回報照常歸零。
     */
    public function agentLoginSucceeded(AgentLoginReportRequest $request)
    {
        $agent = Agent::where('username', $request->from)->first();

        if (!is_object($agent)) {
            throw new ApiException(__('error_message.1005'), 1005);
        }

        AgentLoginSucceeded::dispatch($agent->id);

        return ApiResponse::customCode(1000);
    }

    public function checkAgentToken(CheckAgentTokenRequest $request)
    {
        $valid = AgentAccessToken:[REDACTED]'token', $request->agent_token)
            ->where('expire_date', '>', now())
            ->whereHas('agent', function ($query) use ($request) {
                $query->where('username', $request->agent_username);
            })
            ->exists();

        if (!$valid) {
            throw new ApiException(__('error_message.1050'), 1050);
        }

        return ApiResponse::customCode(1000);
    }

    public function check_password(CheckPasswordRequest $request)
    {
        $member = Member::where('username', $request->username)->first();

        if (!is_object($member)) {
            throw new ApiException(__('error_message.1007'), 1007);
        }

        $isValid = $request->password_type == 1
            ? Hash::check($request->password, (string) $member->payment_password)
            : Hash::check($request->password, (string) $member->password);

        if (!$isValid) {
            throw new ApiException(__('error_message.1007'), 1007);
        }

        return ApiResponse::customCode(1000);
    }

    public function checkFlowLimit(CheckFlowLimitRequest $request)
    {
        $member = Member::with('agent')->where('username', $request->username)->first();

        if (!is_object($member)) {
            throw new ApiException(__('error_message.1005'), 1005);
        }

        $settings = $this->agentService->getAgentSettings($member->agent);
        $flowLimitVisible = $settings[AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE] ?? 0;
        $flowLimitData = $this->memberService->getFlowLimit($member);

        return ApiResponse::customCode(1000, data: [
            'reach' => $flowLimitData['reach'],
            'flow_limit' => $flowLimitData['flow_limit'],
            'flow_limit_visable' => $flowLimitVisible,
            'transfer_flow_limit' => $settings[AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT] ?? 0,
        ]);
    }

    public function getBankList(GetBankListRequest $request)
    {
        $agent = Agent::where('username', $request->from)->first();

        if (!is_object($agent)) {
            throw new ApiException(__('error_message.1005'), 1005);
        }

        $languageId = (int) $request->input('lang', 1);

        $banks = Bank::where('agent_group_id', $agent->cash_flow_group_id)
            ->where('status', 1)
            ->with(['names' => function ($query) use ($languageId) {
                $query->where('language_id', $languageId);
            }])
            ->get()
            ->map(fn (Bank $bank) => [
                'id' => $bank->id,
                'name' => $bank->names->first()->name ?? $bank->name,
                'code' => $bank->code,
            ]);

        return ApiResponse::customCode(1000, data: $banks->values()->all());
    }

    public function csSetting(CsSettingRequest $request)
    {
        $agent = Agent::where('username', $request->from)->first();

        if (!is_object($agent)) {
            throw new ApiException(__('error_message.1005'), 1005);
        }

        $rows = DB::table('agent_setting_defines as d')
            ->leftJoin('agent_customized_settings as s', function ($join) use ($agent) {
                $join->on('s.setting_key', 'd.setting_id')
                    ->where('s.agent_group_id', $agent->customized_setting_group_id);
            })
            ->leftJoin('agent_personal_settings as p', function ($join) use ($agent) {
                $join->on('p.setting_id', 'd.setting_id')
                    ->where('p.agent_id', $agent->id);
            })
            ->whereIn('d.parent', [
                AgentCustomizedSettingKeyEnum::CS_BACKEND_SETTING,
                AgentCustomizedSettingKeyEnum::FINANCE_SETTING,
                AgentPersonalSettingKeyEnum::PERSONAL_OTHER,
            ])
            ->select(
                'd.setting_id',
                DB::raw('CASE d.type WHEN 2 THEN IFNULL(p.value, d.default_value) WHEN 3 THEN IFNULL(s.value, d.default_value) END AS value')
            )
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $data[$row->setting_id] = $row->value;
        }

        return ApiResponse::customCode(1000, data: $data);
    }

    public function googleAuth(GoogleAuthRequest $request)
    {
        $agent = Agent::where('username', $request->from)->first();

        if (!is_object($agent)) {
            throw new ApiException(__('error_message.1005'), 1005);
        }

        $auth = AgentAuthentication::where('agent_id', $agent->id)
            ->where('status', 1)
            ->first();

        // 注意：目前唯一寫入來源是舊系統 API_Agent（Google2FAController），寫入的是明文 secret，
        // 非本 Model `google2fa_secret` accessor 期待的加密格式（該 accessor 目前無任何寫入路徑使用，
        // 且 `secret` 欄位 varchar(50) 也裝不下加密後的密文）。所以這裡和舊系統 API_Frontend 的 googleAuth 一樣，
        // 直接讀取原始 secret 屬性。
        $valid = is_object($auth) && (new Google2FA)->verifyKey($auth->secret, $request->secret);

        if (!$valid) {
            throw new ApiException(__('error_message.9992'), 9992);
        }

        return ApiResponse::customCode(1000);
    }

    public function sendSms(SendSmsRequest $request)
    {
        $agent = Agent::where('username', trim((string) $request->from))
            ->where('mobile', $request->mobile)
            ->first();

        if (!is_object($agent)) {
            throw new ApiException(__('error_message.1007'), 2001);
        }

        [$tokenExpired, $maxSend] = $this->getAgentSmsSetting($agent);
        $typeId = $request->type . $request->verify_by;

        $validateToken = [REDACTED]'agent_id', $agent->id)
            ->where('status', 0)
            ->where('type_id', $typeId)
            ->where('expire_date', '>=', now())
            ->first();

        if (!is_object($validateToken) || $validateToken->info != $request->mobile) {
            ValidateToken:[REDACTED]'agent_id', $agent->id)->where('status', 0)->update(['status' => 2]);

            $token = [REDACTED] $request->verify_by);

            $created = ValidateToken:[REDACTED]
                'agent_id' => $agent->id,
                'member_id' => 0,
                'type_id' => $typeId,
                'info' => $request->verify_by == 1 ? $request->mobile : $request->email,
                'info_2' => in_array((int) $request->type, [5, 6]) ? Hash::make((string) $request->payment_password) : '',
                'token' => $token,
                'expire_date' => now()->addMinutes($tokenExpired),
                'status' => 0,
                'admin' => $agent->id,
            ]);

            if (!$created) {
                throw new ApiException(__('error_message.2001'), 2001);
            }

            $times = 1;
        } elseif ($validateToken->times < $maxSend) {
            $validateToken->increment('times');
            $validateToken->update(['expire_date' => now()->addMinutes($tokenExpired)]);
            $token = [REDACTED];
            $times = $validateToken->times;
        } else {
            throw new ApiException(__('error_message.2037', ['values' => $maxSend]), 2037);
        }

        if (!$this->sendAgentSmsValidation($agent, $request, $token, $times)) {
            throw new ApiException(__('error_message.2003'), 2003);
        }

        return ApiResponse::customCode(1000);
    }

    /**
     * 驗證代理手機驗證碼（客服後台登入用）。
     *
     * 驗證碼必須屬於 from 指定的代理（target/tickets#4535）：只比 token＋mobile 時，攻擊者能用自己代理帳號的
     * 手機與驗證碼替別的代理通過驗證。不屬於 from 的驗證碼一律回與驗證碼錯誤相同的 9992，不透露差異。
     */
    public function checkCode(CheckCodeRequest $request)
    {
        $agent = Agent::where('username', trim((string) $request->from))->first();

        if (!is_object($agent)) {
            throw new ApiException(__('error_message.2001'), 2001);
        }

        // 以 agent_id 限定查詢：別的代理的驗證碼查不到，等同驗證碼錯誤；也避免不同代理撞到同一組碼時取錯列
        $validateToken = [REDACTED]'agent_id', $agent->id)
            ->where('token', $request->token)
            ->where('status', 0)
            ->where('expire_date', '>=', now())
            ->first();

        if (!is_object($validateToken) || $validateToken->info != $request->mobile) {
            throw new ApiException(__('error_message.9992'), 9992);
        }

        // 僅「純驗證」（type=1, verify_by=1）才會消耗 token 狀態，其他組合視為驗證通過、token 保持可再用；
        // 與舊系統 API_Frontend 相同
        if ($validateToken->type == 1 && $validateToken->verify_by == 1) {
            $validateToken->update(['status' => 1]);
        }

        return ApiResponse::customCode(1000);
    }

    /**
     * 取得代理所屬簡訊群組的 token 有效時間（分鐘）與最大發送次數
     *
     * @return array{0: int, 1: int}
     */
    private function getAgentSmsSetting(Agent $agent): array
    {
        $default = [
            (int) config('custom.sms.token_expired'),
            (int) config('custom.sms.max_send'),
        ];

        $agentGroup = AgentGroup::where('organization_id', $agent->organization_id)
            ->where('agent_group_type', 3)
            ->first();

        if (!$agentGroup) {
            return $default;
        }

        $smsGatewayGroupDetail = SmsGatewayGroupDetail::where('agent_group_id', $agentGroup->id)->first();

        if (!$smsGatewayGroupDetail) {
            return $default;
        }

        $setting = $smsGatewayGroupDetail->setting;

        return [
            (int) ($setting->token_expired ?? $default[0]),
            (int) ($setting->max_send ?? $default[1]),
        ];
    }

    private function generateAgentSmsToken(int $verifyBy): string
    {
        $token = [REDACTED] == 1
            ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
            : Str::random(32);

        $exists = ValidateToken:[REDACTED]'token', $token)->where('expire_date', '>=', now())->exists();

        return $exists ? $this->generateAgentSmsToken($verifyBy) : $token;
    }

    /**
     * 依代理所屬簡訊組別挑選簡訊商並發送驗證碼。候選簡訊商依 sort 由高到低排序；`$times` 是這組驗證碼的發送次數
     * （新產生時為 1），`$times` ≤ 1 時隨機挑一家，大於 1 時依發送次數輪詢。
     *
     * 會員端的對應實作是 {@see \App\Services\ValidateToken\AbstractValidationCodeService}（主體是 Member）。
     * 會員端沒有指定簡訊商（sms_gateway_info_id）時，輪詢算法與這裡相同，但 `$times` 為 1 時固定取 sort 最高的一家、
     * 不隨機；有指定時直接用指定的簡訊商。
     */
    private function sendAgentSmsValidation(Agent $agent, SendSmsRequest $request, string $token, int $times): bool
    {
        if ($request->verify_by != 1) {
            return true;
        }

        $smsGatewayInfos = SmsGatewayInfo::with(['smsGateway', 'countries'])
            ->where('sms_gateway_infos.status', 1)
            ->where('sms_gateway_infos.sms_gateway_group_id', $agent->sms_gateway_group_id)
            ->whereHas('smsGateway', fn ($query) => $query->where('sms_gateways.status', 1))
            ->whereHas('countries', fn ($query) => $query->where('countries.status', 1)
                ->whereRaw('? like concat(countries.country_code, ?)', [$request->mobile, '%']))
            ->orderByDesc('sms_gateway_infos.sort')
            ->get();

        if ($smsGatewayInfos->isEmpty()) {
            BotNotify::send("【sms發送失敗-查無符合國際電話冠碼】\nagent:{$agent->id}\nmobile:" . Common::maskData((string) $request->mobile, 'mobile') . "\nsms_group:{$agent->sms_gateway_group_id}");

            return false;
        }

        $index = $times <= 1 ? array_rand($smsGatewayInfos->all()) : ($times - 1) % $smsGatewayInfos->count();
        $smsGatewayInfo = $smsGatewayInfos->get($index);
        $country = $smsGatewayInfo->countries->first();

        $smsText = SmsText::where('sms_gateway_group_id', $agent->sms_gateway_group_id)
            ->where('type_id', $request->type)
            ->where('country_id', $country->id)
            ->first();

        if (!is_object($smsText)) {
            BotNotify::send("【sms發送失敗-查無簡訊內容】\nagent:{$agent->id}\nmobile:" . Common::maskData((string) $request->mobile, 'mobile') . "\nsms_group:{$agent->sms_gateway_group_id}");

            return false;
        }

        $formattedToken = [REDACTED], $smsGatewayInfo->info);
        $onlyTokenGateway = [SmsGatewayEnum::CODE_COSMS->value, SmsGatewayEnum::CODE_GROWIX->value];
        $content = in_array($smsGatewayInfo->code, $onlyTokenGateway)
            ? $formattedToken
            : [REDACTED]'{code}', $formattedToken, $smsText->text);

        $smsGatewayInfoData = SmsGatewayInfoData::from([
            'smsGatewayInfo' => $smsGatewayInfo,
            'countryId' => $country->id,
            'countryCode' => $country->country_code,
        ]);

        $result = (new SmsGatewayService)->sendSms(
            smsGatewayInfoData: $smsGatewayInfoData,
            member: $agent,
            mobile: $request->mobile,
            content: $content,
            type: 1, // 1:agent_id, 2:member_id
        );

        return $result->status === 1;
    }

    private function formatAgentSmsToken(string $token, mixed $info): string
    {
        $insertChars = (string) ($info['insert_characters'] ?? '');

        if ($insertChars === '') {
            return $token;
        }

        $characters = str_split($token);
        foreach ($characters as $index => &$character) {
            $character .= ($index !== array_key_last($characters)) ? $insertChars : '';
        }

        return implode($characters);
    }

    /**
     * transaction_type 屬「轉入」的類型：開分(4) / 領紅包(7) / 紅包取消(8) / 紅包退還(9) / 開分贈點(10) / 拒絕洗分(11) / 退還洗分(12) / 補點(14)
     */
    private const IN_TRANSACTION_TYPES = [
        TransactionEnum::DEPOSIT->value,
        TransactionEnum::RECEIVE_RED_ENVELOPE->value,
        TransactionEnum::RED_ENVELOPE_CANCELLATION->value,
        TransactionEnum::RED_ENVELOPE_REFUND->value,
        TransactionEnum::CHECKIN_REWARD->value,
        TransactionEnum::REFUSE_TO_WITHDRAWAL->value,
        TransactionEnum::REFUND_OF_WITHDRAWAL->value,
        TransactionEnum::MAKE_UP->value,
    ];

    /**
     * 鎖分模式中禁止的 transaction_type：發紅包(6) / 領紅包(7)。
     *
     * 只鎖這兩種——本方法同時承載開分(4)/洗分(5)/補點(14) 等 CS 後台手動調帳操作，以及紅包
     * 取消(8)/退還(9) 這類系統自動沖正，均不受鎖分狀態影響（調帳是管理端修正、沖正是把已發生的
     * 紅包收回，兩者都不該被會員自己的鎖分狀態擋下）。
     */
    private const EVENT_MODE_GUARDED_TRANSACTION_TYPES = [
        TransactionEnum::SEND_RED_ENVELOPE->value,
        TransactionEnum::RECEIVE_RED_ENVELOPE->value,
    ];

    /**
     * 活動 V2 進行中禁止的 transaction_type：開分(4)／洗分(5)／發紅包(6)／領紅包(7)／
     * 開分贈點(10)／補點(14)，即真正由人主動發起、會異動會員可用餘額的加減分動作。
     *
     * 與 `target/tickets#4333`（客服開分／洗分計入會員小時匯總）的關係：`newTransaction()` 開分／洗分
     * 不論 `is_event_wallet` 是否為真，都直接寫 `members.balance`（一般錢包）；`is_event_wallet` 只決定帳變流水寫進
     * `wallet_transaction_logs` 還是 `transaction_logs`（避免污染小時匯總報表）。
     * `is_event_wallet` 在活動 V2 參加中、或新人專享挑戰處於挑戰流水階段時為真，其中活動 V2 參加中的開分
     * 被本檢查擋下（target/tickets#4221）。所以
     * `ThirdPartyTransactionTest::test_new_transaction_deposit_for_event_wallet_member_does_not_touch_member_hourly_statistics`
     * 用新人專享挑戰（`NewbieChallengeProgress` stage=CHALLENGING）觸發 `is_event_wallet`，
     * 驗證「活動錢包會員開分不進小時匯總」。
     *
     * 刻意不含紅包取消(8)／紅包退還(9)／拒絕洗分(11)／退還洗分(12)——這 4 種是系統把已扣的錢
     * 沖正退回會員，擋下沖正只會讓錢卡在系統裡出不去，比不擋更危險，理由同上方
     * EVENT_MODE_GUARDED_TRANSACTION_TYPES 的沖正不受鎖分狀態影響。
     */
    private const EVENT_V2_GUARDED_TRANSACTION_TYPES = [
        TransactionEnum::DEPOSIT->value,
        TransactionEnum::WITHDRAWAL->value,
        TransactionEnum::SEND_RED_ENVELOPE->value,
        TransactionEnum::RECEIVE_RED_ENVELOPE->value,
        TransactionEnum::CHECKIN_REWARD->value,
        TransactionEnum::MAKE_UP->value,
    ];

    /**
     * 拒絕洗分／退還洗分：分數還沒加到代理身上，newTransaction 不扣代理的錢。
     *
     * 補點(14) 自 target/tickets#4535 起不在此列，比照開分扣操作代理的餘額，代理餘額不足回 1408；
     * 原本補點不扣代理，客服後台帳號可無中生有加點。
     */
    private const AGENT_DEDUCT_SKIP_TYPES = [
        TransactionEnum::REFUSE_TO_WITHDRAWAL->value,
        TransactionEnum::REFUND_OF_WITHDRAWAL->value,
    ];

    /**
     * 開分／領紅包／開分贈點／補點：audit_amount > 0 時才寫入稽核流水
     */
    private const FLOW_LIMIT_TRANSACTION_TYPES = [
        TransactionEnum::DEPOSIT->value,
        TransactionEnum::RECEIVE_RED_ENVELOPE->value,
        TransactionEnum::CHECKIN_REWARD->value,
        TransactionEnum::MAKE_UP->value,
    ];

    /**
     * flow_limits.reach 是 decimal(18,4)，稽核明細的 after 數字須與欄位精度一致；
     * 若用 numAdd 預設的 scale 10，會寫出 after:6055.0835000000 這種與實存值對不上的長字串
     */
    private const FLOW_LIMIT_SCALE = 4;

    /**
     * flow_limits.remark 是 varchar(100)。舊系統 API_Frontend 的資料庫連線 strict=false，靠 MySQL 靜默截斷；
     * 本系統 strict=true，超長時會直接拋 SQLSTATE[22001] 1406（資料超過欄位長度），所以寫入前自行收斂長度
     */
    private const FLOW_LIMIT_REMARK_MAX_LENGTH = 100;

    /**
     * transaction()／newTransaction() 共用的鎖分模式／活動 V2 准入判斷，抽出以降低兩個方法的圈複雜度。
     *
     * @throws ApiException error_message.1934／error_message.event_mode.transaction_event_v2_not_allowed
     */
    private function assertEventStateAllowsTransaction(Member $member, int $transactionType): void
    {
        // 鎖分模式中禁止發紅包／領紅包
        if (in_array($transactionType, self::EVENT_MODE_GUARDED_TRANSACTION_TYPES, true) && $member->event_mode > 0) {
            throw new ApiException(__('error_message.1934'));
        }

        // 活動 V2 進行中禁止後台手動加減分
        if (in_array($transactionType, self::EVENT_V2_GUARDED_TRANSACTION_TYPES, true) && $member->is_event_v2) {
            throw new ApiException(__('error_message.event_mode.transaction_event_v2_not_allowed'));
        }
    }

    public function transaction(TransactionRequest $request)
    {
        $transactionType = (int) $request->transaction_type;
        // 外部數值入境即正規化：呼叫端的 amount / tax 可能是 JSON float 或 strval() 產生的字串
        $amount = Common::numAdd('0', (string) $request->amount, 4);
        $tax = Common::numAdd('0', (string) ($request->tax ?? 0), 4);

        try {
            DB::beginTransaction();

            $member = Member::with('agent')->lockForUpdate()->where('username', $request->username)->first();
            $agent = Agent::where('username', $request->agent_username)->first();

            if (!is_object($member) || !is_object($agent)) {
                throw new ApiException(__('error_message.1007'), 1007);
            }

            $this->assertEventStateAllowsTransaction($member, $transactionType);

            $beforeBalance = (string) $member->balance;

            if (in_array($transactionType, self::IN_TRANSACTION_TYPES)) {
                $afterBalance = Common::numAdd($beforeBalance, Common::numAdd($amount, $tax));
                $member->update(['balance' => $afterBalance]);

                $transactionLog = $this->writeCsTransactionLog($member, $agent, $transactionType, $amount, $tax, $beforeBalance, $afterBalance, $request->remark, $request->operator);

                if ($transactionType === TransactionEnum::MAKE_UP->value) {
                    BotNotify::channel('info')->send(implode(PHP_EOL, [
                        '[營運商CS後台手動補點]',
                        '代理:' . $agent->username,
                        '玩家:' . $member->username,
                        '點數:' . $amount . '點',
                        '備註:' . $request->remark,
                        '操作者:' . $request->operator,
                    ]));
                }

                $auditAmount = (string) ($request->audit_amount ?? 0);
                if ($transactionType === TransactionEnum::RECEIVE_RED_ENVELOPE->value) {
                    $auditAmount = $this->calcRedEnvelopeAuditAmount($request, $agent, $member, $transactionLog->id);
                }

                $this->createCsFlowLimit($member, $transactionType, $tax, $amount, $auditAmount, $transactionLog->id);

                DB::commit();

                return ApiResponse::customCode(1000, data: ['balance' => $member->balance]);
            }

            // 轉出：洗分(5) / 發紅包(6)
            if (Common::numCompare($beforeBalance, Common::numAdd($amount, $tax)) < 0) {
                throw new ApiException(__('error_message.1022'), 1022);
            }

            $afterBalance = Common::numSub($beforeBalance, Common::numAdd($amount, $tax));
            $member->update(['balance' => $afterBalance]);

            $this->writeCsTransactionLog($member, $agent, $transactionType, $amount, $tax, $beforeBalance, $afterBalance, $request->remark, $request->operator);

            DB::commit();

            if ($transactionType === TransactionEnum::WITHDRAWAL->value) {
                $this->notifyLargeWashAmount($member);
            }

            return ApiResponse::customCode(1000, data: ['balance' => $member->balance]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function newTransaction(TransactionRequest $request)
    {
        $transactionType = (int) $request->transaction_type;
        // 外部數值入境即正規化：呼叫端的 amount / tax 可能是 JSON float 或 strval() 產生的字串
        $amount = Common::numAdd('0', (string) $request->amount, 4);
        $tax = Common::numAdd('0', (string) ($request->tax ?? 0), 4);

        try {
            DB::beginTransaction();

            $member = Member::with('agent')->lockForUpdate()->where('username', $request->username)->first();
            $agent = Agent::lockForUpdate()->where('username', $request->agent_username)->first();

            if (!is_object($member) || !is_object($agent)) {
                throw new ApiException(__('error_message.1007'), 1007);
            }

            $this->assertEventStateAllowsTransaction($member, $transactionType);

            $beforeBalance = (string) $member->balance;

            if (in_array($transactionType, self::IN_TRANSACTION_TYPES)) {
                // 補點(14) 代理只扣 amount、會員卻加 amount + tax，帶非零 tax 等於繞過代理扣款加點，
                // 在任何寫入前直接拒絕（target/tickets#4535）；開分等其他類型的 tax 語意不變
                if ($transactionType === TransactionEnum::MAKE_UP->value && Common::numCompare($tax, '0') !== 0) {
                    throw new ApiException(__('error_message.9997'), 9997);
                }

                // 拒絕洗分(11)／退還洗分(12)：分數還沒加到代理身上，不扣代理的錢；
                // 補點(14) 自 target/tickets#4535 起扣操作代理的餘額
                $agentDeductNeeded = !in_array($transactionType, self::AGENT_DEDUCT_SKIP_TYPES);

                if ($agentDeductNeeded && !$this->updateAgentBalance($agent, $amount)) {
                    // 代理餘額不足回 code 1408，訊息用餘額不足（error_message.1022）的文字，與舊系統 API_Frontend 相同。
                    // 1408 不是合法的 HTTP 狀態碼，ApiException 會把 HTTP 狀態碼收斂成 400，但回應內容的 code 仍是 1408；
                    // 呼叫端 WEB_CustomerServiceSocket（客服系統）依這個 code 分岔處理。
                    throw new ApiException(__('error_message.1022'), 1408);
                }

                $afterBalance = Common::numAdd($beforeBalance, Common::numAdd($amount, $tax));
                $member->update(['balance' => $afterBalance]);

                $transactionLog = $this->writeCsTransactionLog($member, $agent, $transactionType, $amount, $tax, $beforeBalance, $afterBalance, $request->remark, $request->operator);

                $this->dispatchCsHourSummary($member, $transactionType, $transactionLog, $amount);

                if ($transactionType === TransactionEnum::MAKE_UP->value) {
                    BotNotify::channel('info')->send(implode(PHP_EOL, [
                        '[營運商CS後台手動補點]',
                        '代理:' . $agent->username,
                        '玩家:' . $member->username,
                        '點數:' . $amount . '點',
                        '備註:' . $request->remark,
                        '操作者:' . $request->operator,
                    ]));
                }

                $auditAmount = (string) ($request->audit_amount ?? 0);
                $this->createCsFlowLimit($member, $transactionType, $tax, $amount, $auditAmount, $transactionLog->id);

                if ($transactionType === TransactionEnum::DEPOSIT->value && in_array((int) $request->role_id, [1, 3])) {
                    $this->createVipFlowLimitForDeposit($member, $amount);
                }

                DB::commit();

                return ApiResponse::customCode(1000, data: ['balance' => $member->balance]);
            }

            // 轉出：洗分(5) / 發紅包(6)
            if (Common::numCompare($beforeBalance, Common::numAdd($amount, $tax)) < 0) {
                throw new ApiException(__('error_message.1022'), 1022);
            }

            $afterBalance = Common::numSub($beforeBalance, Common::numAdd($amount, $tax));
            $member->update(['balance' => $afterBalance]);

            // 確認沒問題後（withdraw_confirm=0 或 role_id=1），把錢加回代理錢包
            if ($agent->withdraw_confirm == 0 || (int) $request->role_id === 1) {
                $this->updateAgentBalance($agent, Common::numMul($amount, -1));
            }

            $transactionLog = $this->writeCsTransactionLog($member, $agent, $transactionType, $amount, $tax, $beforeBalance, $afterBalance, $request->remark, $request->operator);

            if ($transactionType === TransactionEnum::WITHDRAWAL->value) {
                $this->dispatchCsHourSummary($member, $transactionType, $transactionLog, $amount);

                // 註：舊系統 API_Frontend 的設定鍵 WITHDRAWAL_CLEAR_FLOW_LIMIT（6065，提現是否清除稽核流水），
                // 在本系統找不到 setting_id 完全相同的鍵。語意最接近的既有鍵是 WITHDRAWAL_FLOW_LIMIT_SETTING_001
                // （6605，洗分是否需扣除稽核流水），暫以此對應。兩者目前在各站點都沒有實際設定值（都吃預設值 1，
                // 也就是扣除）。是否需要新增對齊的鍵值，仍待與 PM／後台確認。
                $isDeductFlowLimit = $this->agentService->getSpecificAgentSetting($member->agent, AgentCustomizedSettingKeyEnum::WITHDRAWAL_FLOW_LIMIT_SETTING_001);
                if ($isDeductFlowLimit === null || (int) $isDeductFlowLimit === 1) {
                    $this->deductFlowLimitOnWithdrawal($member, $amount);
                }
            }

            DB::commit();

            if ($transactionType === TransactionEnum::WITHDRAWAL->value) {
                $this->notifyLargeWashAmount($member);
            }

            return ApiResponse::customCode(1000, data: ['balance' => $member->balance]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 寫入 CS 轉帳的 transaction_log：用既有的 {@see TransactionLogCreateService}，
     * 並以 setAgentId() 指定本次呼叫 agent_username 解析出的代理（實際被扣款／入帳的那一方），
     * 與舊系統 API_Frontend 的 `$log['agent_id'] = $agent->id` 相同。`transaction_logs.agent_id` 的欄位註解寫明
     * 「同一個玩家可能由不同代理開洗分」，所以不可一律取會員自身所屬代理（member.parent）。
     */
    private function writeCsTransactionLog(Member $member, Agent $agent, int $transactionType, string $amount, string $tax, string $beforeBalance, string $afterBalance, ?string $remark, ?string $operator)
    {
        $optionalData = new TransactionLogOptionalData(
            tax: $tax,
            remark: (string) $remark,
            result: $transactionType === TransactionEnum::MAKE_UP->value ? 'CS補點' : '',
            operator: (string) $operator,
            admin: $member->id,
        );

        return (new TransactionLogCreateService($member))
            ->setType($transactionType)
            ->setAgentId($agent->id)
            ->setOptionalData($optionalData)
            ->create($amount, $beforeBalance, $afterBalance);
    }

    /**
     * 客服開分(4)／洗分(5) 即時累加會員小時匯總（target/tickets#4333）。
     *
     * 不經金流也不建訂單，Order／WithdrawalOrder Observer 收不到，只能在這裡送；dispatchSafely 走 DB::afterCommit，
     * rollback 不會送出；金額口徑同數據分析（transaction_logs.point）。
     *
     * 只有落在 transaction_logs 的帳變才累加：活動錢包／多錢包會員的帳變寫 wallet_transaction_logs，
     * 每小時排程重算只讀 transaction_logs（數據分析亦不算 wallet 的手動帳變），這裡若累加會在下一次整桶覆蓋時被抹掉，
     * 變成「同桶有無其他訂單決定留不留」的飄移錯帳——即時管道與重算來源必須同一組。
     */
    private function dispatchCsHourSummary(Member $member, int $transactionType, TransactionLog|WalletTransactionLog $transactionLog, string $amount): void
    {
        if (!$transactionLog instanceof TransactionLog) {
            return;
        }

        // 客服開洗分歸「後台開分／後台洗分」來源（與代理後台同一類，不分 platform）
        $source = match ($transactionType) {
            TransactionEnum::DEPOSIT->value => MemberHourlySourceEnum::ADMIN_DEPOSIT,
            TransactionEnum::WITHDRAWAL->value => MemberHourlySourceEnum::ADMIN_WITHDRAWAL,
            default => null,
        };

        if ($source === null) {
            return;
        }

        UpdateMemberHourSummary::dispatchSafely($member->id, $source->summaryType(), $amount, (string) $transactionLog->created_at, $source->value);
    }

    /**
     * 開分(4) / 領紅包(7) / 開分贈點(10) / 補點(14) 且 audit_amount > 0 時寫入稽核流水
     */
    private function createCsFlowLimit(Member $member, int $transactionType, string $tax, string $amount, string $auditAmount, ?int $transactionLogId): void
    {
        if (!in_array($transactionType, self::FLOW_LIMIT_TRANSACTION_TYPES) || Common::numCompare($auditAmount, '0') <= 0) {
            return;
        }

        FlowLimit::create([
            'member_id' => $member->id,
            'type' => $transactionType,
            'event_id' => 0,
            'reference_id' => $transactionLogId ?? 0,
            'flow_limit' => Common::numCompare($tax, '0') > 0 ? Common::numAdd($amount, $tax) : $auditAmount,
            'admin' => 0,
        ]);
    }

    /**
     * 領紅包(7) 專屬：依發紅包方（$request->operator，為會員 username）剩餘的稽核流水，
     * 決定收方（$member）這筆紅包的稽核量（audit amount），邏輯與舊系統 API_Frontend 相同。
     *
     * - 代理沒開 TRANSFER_CHANGE_FLOW_LIMIT（轉帳會轉移稽核流水），或找不到發紅包方：直接用請求帶的 audit_amount。
     * - 雙方真實姓名相同：稽核量是從發紅包方轉移過來的流水量。
     * - 雙方真實姓名不同：稽核量是紅包全額。
     *
     * 真實姓名比對的是 first_name，不分大小寫；雙方都沒填也算相同。
     *
     * @throws ApiException
     */
    private function calcRedEnvelopeAuditAmount(TransactionRequest $request, Agent $agent, Member $member, int $transactionLogId): string
    {
        $changeFlowLimit = $this->agentService->getSpecificAgentSetting($agent, AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT);
        $defaultAuditAmount = (string) ($request->audit_amount ?? 0);

        if (!$changeFlowLimit) {
            return $defaultAuditAmount;
        }

        $operator = Member::where('username', $request->operator)->first();

        if (!is_object($operator)) {
            return $defaultAuditAmount;
        }

        $totals = FlowLimit::where('member_id', $operator->id)
            ->where('type', '!=', TransactionEnum::PROMOTION_WALLET_REWARD->value)
            ->where('status', 0)
            ->selectRaw('IFNULL(SUM(flow_limit), 0) AS total_flow_limit, IFNULL(SUM(reach), 0) AS total_reach')
            ->first();

        $hasFlowLimit = Common::numSub($totals->total_flow_limit, $totals->total_reach);

        // $transferFlowLimit（要從發紅包方轉移的流水量）預設為 0，與舊系統 API_Frontend 相同。
        // 發紅包方沒有未達成的稽核流水時就維持 0，最後仍照常比對真實姓名，不會跳過比對、直接把紅包全額綁成流水。
        // 所以雙方真實姓名相同、發紅包方又沒有未達成流水時，稽核量是 0，不會因這筆紅包新增稽核流水；
        // 收方能不能提款，仍看提款時的流水與實名等其他檢查。
        $transferFlowLimit = '0';

        if (Common::numCompare($hasFlowLimit, '0') > 0) {
            $currentBalance = (string) ($request->current_balance ?? 0);
            $originalBalance = Common::numAdd($currentBalance, (string) $request->amount);

            if (Common::numCompare($originalBalance, $hasFlowLimit) < 0) {
                // 原始金額小於稽核流水，代表有問題（不應發生），擋下這次領取
                throw new ApiException(__('error_message.2004'), 2004);
            }

            $transferFlowLimit = Common::numCompare($currentBalance, $hasFlowLimit) >= 0
                ? '0'
                : Common::numSub($hasFlowLimit, $currentBalance);

            if (Common::numCompare($transferFlowLimit, '0') > 0) {
                $this->transferOperatorFlowLimit($operator, $transferFlowLimit, $transactionLogId);
            }
        }

        return strtoupper((string) $operator->first_name) === strtoupper((string) $member->first_name)
            ? $transferFlowLimit
            : (string) $request->amount;
    }

    /**
     * 將轉出方（$operator）名下未達成的稽核流水依序扣抵、轉移，扣抵的迴圈邏輯與舊系統 API_Frontend 相同。
     * 轉出方是領紅包時的發紅包方，或會員轉帳的付款方。
     *
     * 整列轉移掉的稽核流水：remark 改寫成 transfer，status 改為 9（取消稽核）。
     * 只轉移一部分的那一列：reach 加上轉移量，remark 在原內容後疊加一行 after／by 稽核明細
     * （轉移後的 reach、觸發這次轉移的帳變流水 id），供客服與風控事後追查。
     */
    private function transferOperatorFlowLimit(Member $operator, string $transferFlowLimit, int $transactionLogId): void
    {
        $remaining = $transferFlowLimit;

        $flowLimits = FlowLimit::where('member_id', $operator->id)->where('status', 0)->get();

        foreach ($flowLimits as $row) {
            $rowFlowLimit = Common::numSub($row->flow_limit, $row->reach);

            if (Common::numCompare($remaining, $rowFlowLimit) < 0) {
                $afterReach = Common::numAdd($row->reach, $remaining, self::FLOW_LIMIT_SCALE);
                $detail = "transfer,after:{$afterReach},by:{$transactionLogId}";
                $row->update([
                    'reach' => $afterReach,
                    'remark' => $this->appendFlowLimitRemark($row->remark, $detail),
                ]);
                break;
            }

            $remaining = Common::numSub($remaining, $rowFlowLimit);
            $row->update(['remark' => 'transfer', 'status' => 9]);
        }
    }

    /**
     * 把新的稽核明細疊加到 remark 上，並收斂到 varchar(100) 以內。
     *
     * 空間不足時從最舊的明細開始丟棄，因為最新一次轉移對客服與風控追查最有價值。
     * 舊系統 API_Frontend 的資料庫連線是 strict=false，它寫入的 remark 超長時會被 MySQL 截斷成
     * 100 字元的殘缺內容。這類 remark 也照同樣方式從最舊的明細開始丟棄，追加新明細時不會因超長而寫入失敗。
     */
    private function appendFlowLimitRemark(?string $currentRemark, string $detail): string
    {
        $lines = Common::isNotEmptyString($currentRemark) ? explode(PHP_EOL, (string) $currentRemark) : [];
        $lines[] = $detail;

        while (count($lines) > 1 && mb_strlen(implode(PHP_EOL, $lines)) > self::FLOW_LIMIT_REMARK_MAX_LENGTH) {
            array_shift($lines);
        }

        return mb_substr(implode(PHP_EOL, $lines), 0, self::FLOW_LIMIT_REMARK_MAX_LENGTH);
    }

    /**
     * newTransaction 專屬：開分(4)且 role_id 為 1 或 3、代理開啟 VIP 時，寫入 VIP 稽核流水。
     */
    private function createVipFlowLimitForDeposit(Member $member, string $amount): void
    {
        $hasVip = $this->agentService->getSpecificAgentSetting($member->agent, AgentCustomizedSettingKeyEnum::VIP_VISIBLE);

        if ((int) $hasVip !== 1) {
            return;
        }

        $vipFlowLimitMultiple = (string) ($this->agentService->getSpecificAgentSetting($member->agent, AgentCustomizedSettingKeyEnum::VIP_DEPOSIT_FLOW_LIMIT_SETTING) ?? '1');

        $vipFlowLimit = VipFlowLimit::create([
            'member_id' => $member->id,
            'type' => TransactionEnum::DEPOSIT->value,
            'deposit_amount' => $amount,
            'flow_limit' => Common::numMul($amount, $vipFlowLimitMultiple),
            'status' => Common::numCompare($vipFlowLimitMultiple, '0') === 0 ? 1 : 0,
            'admin' => 0,
        ]);

        if (Common::numCompare($vipFlowLimitMultiple, '0') === 0) {
            // 倍數為 0 時直接加進 VIP 充值分數
            DB::statement('CALL SP_calc_member_vip_deposit_amount(?, ?, ?)', [$member->id, $amount, $vipFlowLimit->id]);
        }
    }

    /**
     * 洗分成功後，依代理設定 DEPOSIT_FLOW_LIMIT_RATE 扣抵「訂單儲值(3)」類型的稽核流水，
     * 扣抵量是洗分金額乘上這個設定值。邏輯與舊系統 API_Frontend 的 deductFlowLimit() 相同。
     */
    private function deductFlowLimitOnWithdrawal(Member $member, string $amount): void
    {
        $flowLimitRate = (string) ($this->agentService->getSpecificAgentSetting($member->agent, AgentCustomizedSettingKeyEnum::DEPOSIT_FLOW_LIMIT_RATE) ?? '0');

        if (Common::numCompare($flowLimitRate, '0') <= 0) {
            return;
        }

        $deductAmount = Common::numMul($amount, $flowLimitRate);

        $flowLimits = FlowLimit::where('member_id', $member->id)
            ->where('type', TransactionEnum::ORDER_DEPOSIT->value)
            ->where('status', 0)
            ->get();

        foreach ($flowLimits as $flowLimit) {
            $hasFlowLimit = Common::numSub($flowLimit->flow_limit, $flowLimit->reach);

            if (Common::numCompare($hasFlowLimit, $deductAmount) >= 0) {
                $flowLimit->update([
                    'reach' => Common::numAdd($flowLimit->reach, $deductAmount),
                    'status' => Common::numCompare($hasFlowLimit, $deductAmount) === 0 ? 1 : $flowLimit->status,
                ]);
                break;
            }

            $deductAmount = Common::numSub($deductAmount, $hasFlowLimit);
            $flowLimit->update(['reach' => Common::numAdd($flowLimit->reach, $hasFlowLimit), 'status' => 1]);
        }
    }

    /**
     * 從代理錢包扣掉 $amount（傳入負數即為入款），與舊系統 API_Frontend 的 updateAgentBalance() 相同。
     * 無限額代理（is_balance_unlimited）只略過餘額不足檢查，仍會寫入扣款後的餘額，可能為負值。
     */
    private function updateAgentBalance(Agent $agent, string $amount): bool
    {
        $afterBalance = Common::numSub((string) $agent->balance, $amount);

        if ($agent->is_balance_unlimited != 1 && Common::numCompare($afterBalance, '0') < 0) {
            return false;
        }

        $agent->update(['balance' => $afterBalance]);

        return true;
    }

    /**
     * 會員當日洗分(5) 的累積金額達代理設定的門檻時，發送大額洗分 TG（Telegram）通知到代理設定的風控頻道，
     * 與舊系統 API_Frontend 相同。舊系統另外會查 params 表，再發第二則通知到「technology」站點頻道；
     * 那是次要的重複告警，這裡不實作。
     */
    private function notifyLargeWashAmount(Member $member): void
    {
        $today = now()->format('Y-m-d');

        $totalAmount = (string) TransactionLog::where('member_id', $member->id)
            ->where('type', TransactionEnum::WITHDRAWAL->value)
            ->whereBetween('created_at', ["{$today} 00:00:00", "{$today} 23:59:59"])
            ->whereIn('status', [9, 1000])
            ->sum('point');

        $settings = $this->agentService->getAgentSettings($member->agent);
        $threshold = (string) ($settings[AgentCustomizedSettingKeyEnum::OVER_LIMIT_NOTICE_AMOUNT] ?? 0);

        if (Common::numCompare($totalAmount, $threshold) < 0) {
            return;
        }

        $chatId = (string) ($settings[AgentCustomizedSettingKeyEnum::RISK_CONTROL_TG_CHAT_ID] ?? '');

        if ($chatId === '') {
            return;
        }

        BotNotify::chatId($chatId)->send(implode(PHP_EOL, [
            '【大額洗分通知】',
            '帳號:' . $member->username,
            '洗分累積數目：' . $totalAmount,
        ]));
    }

    /**
     * 代理間轉帳記錄：只寫紀錄，不異動任何 agents.balance，與舊系統 API_Frontend 相同。
     */
    public function agentTransaction(AgentTransactionRequest $request)
    {
        $fromAgent = Agent::where('username', $request->from)->first();
        $toAgent = Agent::where('username', $request->to)->first();

        if (!is_object($fromAgent) || !is_object($toAgent)) {
            throw new ApiException(__('error_message.1005'), 1005);
        }

        TransactionLogAgent::create([
            'from_agent_id' => $fromAgent->id,
            'to_agent_id' => $toAgent->id,
            'type' => $request->type,
            'amount' => $request->amount,
            'from_limit_of_credit' => $request->from_limit_of_credit,
            'from_used_credit' => $request->from_used_credit,
            'to_limit_of_credit' => $request->to_limit_of_credit,
            'to_used_credit' => $request->to_used_credit,
            'status' => $request->status ?? 0,
            'remark' => $request->remark,
            'operator' => $request->operator,
        ]);

        return ApiResponse::customCode(1000);
    }

    /**
     * 代理間轉帳並異動餘額（agentTransaction() 只寫紀錄、不動餘額）：type=1(開分) from 扣款、to 入款；
     * type=2(洗分) 方向相反。餘額增減走 updateAgentBalance()，語意與舊系統 API_Frontend 相同：
     * 無限額代理略過餘額不足檢查。from 是無限額代理時，這裡完全不異動它的餘額。
     */
    public function newAgentTransaction(NewAgentTransactionRequest $request)
    {
        try {
            DB::beginTransaction();

            // 依 id 排序後再上鎖，避免 from/to 兩支併發互轉時鎖序相反造成 deadlock
            $agents = Agent::whereIn('username', [$request->from, $request->to])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('username');

            $fromAgent = $agents->get($request->from);
            $toAgent = $agents->get($request->to);

            if (!is_object($fromAgent) || !is_object($toAgent)) {
                throw new ApiException(__('error_message.1005'), 1005);
            }

            $amount = (string) $request->amount;
            $type = (int) $request->type;

            if ($fromAgent->is_balance_unlimited != 1) {
                $fromDelta = $type === 1 ? $amount : Common::numMul($amount, -1);

                if (!$this->updateAgentBalance($fromAgent, $fromDelta)) {
                    throw new ApiException(__('error_message.1022'), 1408);
                }
            }

            $toDelta = $type === 1 ? Common::numMul($amount, -1) : $amount;

            if (!$this->updateAgentBalance($toAgent, $toDelta)) {
                throw new ApiException(__('error_message.2001'), 2001);
            }

            TransactionLogAgent::create([
                'from_agent_id' => $fromAgent->id,
                'to_agent_id' => $toAgent->id,
                'type' => $type,
                'amount' => $amount,
                'from_limit_of_credit' => $fromAgent->balance,
                'from_used_credit' => 0,
                'to_limit_of_credit' => $toAgent->balance,
                'to_used_credit' => 0,
                'status' => $request->status ?? 0,
                'remark' => $request->remark,
                'operator' => $request->operator,
            ]);

            DB::commit();

            return ApiResponse::customCode(1000);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function memberTransfer(MemberTransferRequest $request)
    {
        // 外部數值入境即正規化：呼叫端（CS 後台）的 tax 由 strval(rate * amount) 算出，
        // 稅率非 0 時會是 "3.5" 這種帶小數的**字串**，直接沿用會在下游型別與精度上出事
        $payoutAmount = Common::numAdd('0', (string) $request->amount, 4);
        $tax = Common::numAdd('0', (string) ($request->tax ?? 0), 4);
        $receiveAmount = Common::numCompare($tax, '0', 4) > 0
            ? Common::numSub($payoutAmount, $tax)
            : $payoutAmount;

        try {
            DB::beginTransaction();

            // ? Be aware, $request->username is the user who receive money, operator is the user who payout money.
            // ? But Middleware/ThirdPartyAuthMember will use $request->username to auth.
            // ? We trust that this request is from our internal system by verifying the AES key.
            // ? So we don't need to check the operator's realname.
            // 付錢方
            $operator = Member::with('agent')->lockForUpdate()->where('username', $request->operator)->first();
            // 收錢方
            $member = Member::with('agent')->lockForUpdate()->where('username', $request->username)->first();

            if (!is_object($operator) || !is_object($member)) {
                throw new ApiException(__('error_message.1005'), 1005);
            }

            if ($operator->id == $member->id) {
                throw new ApiException(__('error_message.9997'), 9997);
            }

            $operatorAgentSettings = $this->agentService->getAgentSettings($operator->agent);

            $this->assertMemberTransferAllowed($operator, $member, $operatorAgentSettings);

            // 實名驗證判斷 - 收錢方
            $memberAgentSettings = $this->agentService->getAgentSettings($member->agent);
            if (!RealnameValidationService::isWithdrawRealnameValidated($memberAgentSettings, $member)) {
                // 收款方未實名回獨立的 code 20471，讓呼叫端可依 code 分辨是付款方還是收款方未通過。
                // 舊系統 API_Frontend 兩者共用 code 2047、只有訊息文字不同；這裡是刻意加強的區分。
                DB::rollBack();

                return ApiResponse::customCode(20471, __('error_message.realname_invalid.transfer_target'));
            }

            // 會員群組轉帳限額：單筆上下限、每日金額與每日次數，一律綁轉出方。會員沒有群組或群組停用時全部不限制；
            // 欄位值為負數（例如 -1）或未設定時，該項不限制。限額規則與舊系統 API_Frontend 的
            // ThirdPartyController::enforceMemberGroupTransferLimit() 相同；錯誤訊息用 error_message.2074～2077，
            // 與舊系統的 2050～2053 不同
            $this->memberLimitResolver->checkMemberTransfer($operator, $payoutAmount);
            $this->memberLimitResolver->checkMemberTransferDailyAmount($operator, $payoutAmount);
            $this->memberLimitResolver->checkMemberTransferDailyCount($operator);

            // Update Balance
            $operatorBeforeBalance = $operator->balance;
            $operatorAfterBalance = Common::numSub($operatorBeforeBalance, $payoutAmount);
            if ($operatorAfterBalance < 0) {
                throw new ApiException(__('error_message.1022'), 1022);
            }
            $operator->update(['balance' => $operatorAfterBalance]);

            $memberBeforeBalance = $member->balance;
            $memberAfterBalance = Common::numAdd($memberBeforeBalance, $receiveAmount);
            $member->update(['balance' => $memberAfterBalance]);

            $optionalData = new TransactionLogOptionalData(
                tax: $tax,
                operator: $operator->username,
                admin: $operator->id,
            );

            // Write Logs
            $transactionLogOperator = (new TransactionLogCreateService($operator))
                ->setType(TransactionEnum::MEMBER_TRANSFER_OUT->value)
                ->setOptionalData($optionalData)
                ->create($payoutAmount, $operatorBeforeBalance, $operatorAfterBalance);

            $transactionLogMember = (new TransactionLogCreateService($member))
                ->setType(TransactionEnum::MEMBER_TRANSFER_IN->value)
                ->setOptionalData($optionalData)
                ->create($receiveAmount, $memberBeforeBalance, $memberAfterBalance);

            MemberTransferLog::create([
                'member_id' => $member->id,
                'transaction_log_id' => $transactionLogMember->id,
                'amount' => $payoutAmount,
                'tax' => $tax,
                'operator' => $operator->id,
                'operator_transaction_log_id' => $transactionLogOperator->id,
                'remark' => $request->remark,
                // 寫入成功狀態，與舊系統 API_Frontend 相同。本表 status 的意思是 0:失敗 / 1000:成功，
                // 成功值與 transaction_logs 相同（見 MemberTransferLogStatusEnum）
                'status' => MemberTransferLogStatusEnum::SUCCESS->value,
            ]);

            $this->applyMemberTransferFlowLimit(
                operator: $operator,
                member: $member,
                operatorAgentSettings: $operatorAgentSettings,
                payoutAmount: $payoutAmount,
                operatorBeforeBalance: $operatorBeforeBalance,
                operatorAfterBalance: $operatorAfterBalance,
                operatorTransactionLogId: $transactionLogOperator->id,
                memberTransactionLogId: $transactionLogMember->id,
            );

            DB::commit();

            return ApiResponse::customCode(1000);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 會員轉帳的准入判斷（轉出方稽核流水、轉出方鎖分、收款方鎖分、轉出方實名）。
     *
     * 收款方實名未過走的是「rollback + 自訂 code 20471」而非例外，故留在 memberTransfer() 內。
     *
     * @param  array<int, mixed>  $operatorAgentSettings
     *
     * @throws ApiException
     */
    private function assertMemberTransferAllowed(Member $operator, Member $member, array $operatorAgentSettings): void
    {
        // 稽核准入與舊系統 API_Frontend 的 `FlowLimitController::checkFlowLimit()` 相同：
        // 代理開啟稽核開關**且**轉出方尚有未達成的稽核流水時，一律不允許轉帳，防止以會員轉帳繞過流水；
        // 開關關閉就不受稽核限制、不擋。MemberService::getFlowLimit() 依代理設定 DEPOSIT_FLOW_LIMIT_VISIBLE／
        // EVENT_FLOW_LIMIT_VISIBLE（檢查儲值／活動稽核流水開關）篩選流水類型，篩選條件與舊系統完全相同。
        $flowLimitVisible = (int) ($operatorAgentSettings[AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE] ?? 0);
        if ($flowLimitVisible === 1 && !$this->memberService->getFlowLimit($operator)['pass']) {
            throw new ApiException(__('error_message.1980'), 1980);
        }

        // 付款會員鎖分判斷：轉帳會直接異動餘額，繞過鎖分期間「禁止增減分」的前提，與下方收款方鎖分判斷對稱
        if ($operator->event_mode != 0) {
            throw new ApiException(__('error_message.1934'));
        }

        // 付款方活動V2進行中禁止轉出：V2 活動有自己的 balance_limit/flow_limit 門檻，轉帳異動餘額會繞過活動判斷
        if ($operator->is_event_v2) {
            throw new ApiException(__('error_message.event_mode.transfer_out_event_v2_not_allowed'), 99991);
        }

        // 收款會員鎖分判斷：event_mode 非 0 不允許轉入，與舊系統 API_Frontend 相同
        if ($member->event_mode != 0) {
            throw new ApiException(__('error_message.event_mode.transfer_in_not_allowed'), 9999);
        }

        // 收款方活動V2進行中禁止轉入：同上理由
        if ($member->is_event_v2) {
            throw new ApiException(__('error_message.event_mode.transfer_in_event_v2_not_allowed'), 99992);
        }

        // 實名驗證判斷 - 付錢方
        if (!RealnameValidationService::isWithdrawRealnameValidated($operatorAgentSettings, $operator)) {
            throw new ApiException(__('error_message.realname_invalid.transfer'), 2047);
        }
    }

    /**
     * 會員轉帳完成後的稽核流水處理，邏輯與舊系統 API_Frontend 相同。代理設定 TRANSFER_CHANGE_FLOW_LIMIT
     * （轉帳會轉移稽核流水）沒開時，整段不做任何事。
     *
     * 先把轉出方因這次轉帳而「無法再以餘額覆蓋」的那段流水轉移掉，再依真實姓名是否相同，
     * 決定綁多少流水到收款方身上。真實姓名比對的是 first_name，不分大小寫；雙方都沒填也算相同。
     *
     * @param  array<int, mixed>  $operatorAgentSettings
     *
     * @throws ApiException 轉出方轉帳前的餘額小於未達成的稽核流水總額（資料異常）
     */
    private function applyMemberTransferFlowLimit(
        Member $operator,
        Member $member,
        array $operatorAgentSettings,
        string $payoutAmount,
        string $operatorBeforeBalance,
        string $operatorAfterBalance,
        int $operatorTransactionLogId,
        int $memberTransactionLogId,
    ): void {
        if (!($operatorAgentSettings[AgentCustomizedSettingKeyEnum::TRANSFER_CHANGE_FLOW_LIMIT] ?? 0)) {
            return;
        }

        // 流水轉移的基準是「排除優惠錢包」的全部未達成流水，與舊系統 API_Frontend 相同，
        // 不套 DEPOSIT_FLOW_LIMIT_VISIBLE／EVENT_FLOW_LIMIT_VISIBLE 的流水類型篩選。這與 calcRedEnvelopeAuditAmount
        // 的計算基準一致，所以不重用准入判斷時 getFlowLimit() 的結果。
        $totals = FlowLimit::where('member_id', $operator->id)
            ->where('type', '!=', TransactionEnum::PROMOTION_WALLET_REWARD->value)
            ->where('status', 0)
            ->selectRaw('IFNULL(SUM(flow_limit), 0) AS total_flow_limit, IFNULL(SUM(reach), 0) AS total_reach')
            ->first();

        $hasFlowLimit = Common::numSub($totals->total_flow_limit, $totals->total_reach);
        $transferFlowLimit = '0';

        if (Common::numCompare($hasFlowLimit, '0') > 0) {
            // 轉出方轉帳前的餘額小於未達成的稽核流水總額，代表資料異常（不應發生），擋下這次轉帳，
            // 與舊系統 API_Frontend 相同
            if (Common::numCompare($operatorBeforeBalance, $hasFlowLimit) < 0) {
                throw new ApiException(__('error_message.2004'), 2004);
            }

            $transferFlowLimit = Common::numCompare($operatorAfterBalance, $hasFlowLimit) >= 0
                ? '0'
                : Common::numSub($hasFlowLimit, $operatorAfterBalance);

            if (Common::numCompare($transferFlowLimit, '0') > 0) {
                $this->transferOperatorFlowLimit($operator, $transferFlowLimit, $operatorTransactionLogId);
            }
        }

        $auditAmount = strtoupper((string) $operator->first_name) === strtoupper((string) $member->first_name)
            ? $transferFlowLimit
            : $payoutAmount;

        if (Common::numCompare($auditAmount, '0') <= 0) {
            return;
        }

        FlowLimit::create([
            'member_id' => $member->id,
            'type' => TransactionEnum::MEMBER_TRANSFER_IN->value,
            'event_id' => 0,
            'reference_id' => $memberTransactionLogId,
            'flow_limit' => $auditAmount,
            'reach' => 0,
            'admin' => 0,
        ]);
    }

    public function liveChatGetToken(LiveChatRequest $request)
    {
        $member = Member::where('username', $request->username)->first();

        if (!is_object($member)) {
            throw new ApiException(__('error_message.1007'), 1007);
        }

        $account = $this->liveChatService->getLiveChatAccount($member) ?? $this->liveChatService->getFreshToken($member);

        return ApiResponse::customCode(1000, data: $this->formatLiveChatAccount($account, $member));
    }

    public function liveChatGetFreshToken(LiveChatRequest $request)
    {
        $member = Member::where('username', $request->username)->first();

        if (!is_object($member)) {
            throw new ApiException(__('error_message.1007'), 1007);
        }

        $account = $this->liveChatService->getFreshToken($member);

        return ApiResponse::customCode(1000, data: $this->formatLiveChatAccount($account, $member));
    }

    public function liveChatHasToken(LiveChatRequest $request)
    {
        $member = Member::where('username', $request->username)->first();

        if (!is_object($member)) {
            throw new ApiException(__('error_message.1007'), 1007);
        }

        return ApiResponse::customCode(1000, data: ['hasToken' => $this->liveChatService->hasToken($member)]);
    }

    public function liveChatInvalidate(LiveChatRequest $request)
    {
        $member = Member::where('username', $request->username)->first();

        if (!is_object($member)) {
            throw new ApiException(__('error_message.1007'), 1007);
        }

        return ApiResponse::customCode(1000, data: ['invalidated' => $this->liveChatService->invalidate($member)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatLiveChatAccount(?LiveChatAccount $account, Member $member): ?array
    {
        if (!$account) {
            return null;
        }

        return [
            'username' => $member->username,
            'creationDate' => $account->creation_date?->timestamp,
            'licenseId' => $account->license_id,
            'entityId' => $account->entity_id,
            'expiresIn' => $account->expires_in,
            'tokenType' => $account->token_type,
            'accessToken' => $account->access_token,
            '__lc_cid' => $account->lc_cid,
            '__lc_cst' => $account->lc_cst,
        ];
    }
}
