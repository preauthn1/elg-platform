<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\CacheTtl;
use App\Enums\General\HttpCode;
use App\Enums\IpRestrictionBlockItemEnum;
use App\Enums\MemberStatusEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\LoginRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Services\AgentAncestorStatusGuard;
use App\Services\AgentService;
use App\Services\EventService;
use App\Services\IpRestrictionGuard;
use App\Services\LoginService;
use App\Services\MemberAccessTokenService;
use App\Services\MemberFieldSettingService;
use App\Services\MemberRestrictionGuard;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Group('Login (登入)')]
class LoginController extends Controller
{
    public function __construct(
        protected LoginService $loginService,
        protected AgentService $agentService,
        protected EventService $eventService,
        protected MemberAccessTokenService $memberAccessTokenService,
        protected MemberFieldSettingService $memberFieldSettingService,
        protected MemberRestrictionGuard $memberRestrictionGuard,
        protected IpRestrictionGuard $ipRestrictionGuard,
        protected AgentAncestorStatusGuard $agentAncestorStatusGuard,
    ) {}

    /**
     * 登入會員
     *
     * TODO 透過 device_id 登入應該設計在其他路由, 避免業務邏輯過於複雜
     */
    public function __invoke(LoginRequest $request)
    {
        $input = $request->validated();

        // 風控IP限制(4096)：來源IP有勾選「登入」的啟用規則 → 擋登入並累計重複次數。
        // 不依賴 member,放最前面連帳號不存在的嘗試也擋;已在線者由 AuthMember middleware 踢線
        if ($this->ipRestrictionGuard->blockAttempt(Common::getIp(), IpRestrictionBlockItemEnum::LOGIN)) {
            throw new ApiException(
                IpRestrictionBlockItemEnum::LOGIN->message(),
                IpRestrictionBlockItemEnum::LOGIN->errorCode(),
            );
        }

        $userData = $this->getUserContext();
        $agent = $userData->agent;

        // 查出域名綁定的組織
        $organizationId = $agent->organization_id;

        // 3969:登入識別方式依 domain agent 的 login config(帳號/手機/兩者);查不到 config → 空陣列 → getMember fallback 原邏輯(兩者都認)
        $loginFields = $this->memberFieldSettingService->presentation($agent)['login'] ?? [];

        // 玩家帳號是否存在(依 config 決定認 帳號/手機/兩者；手機登入限本站 binding_scope，4309)
        $member = $this->getMember($organizationId, $input['username'], $loginFields, (int) $agent->binding_scope_id);

        // 玩家帳號不存在
        if (!$member) {
            return $this->handleInvalidMember($input, $agent);
        }

        // 檢查代理鏈網域
        if (!$this->agentService->checkAgentDomain($member->agent, $input['domain'])) {
            return ApiResponse::error($this->loginFailedMessage($agent));
        }

        // 會員帳號存在, 檢查代理與會員資料是否合法
        // 注意這個 $agent 跟網域的 $agent 不一定相同；登入失敗的預設訊息要用網域代理，先留住
        $domainAgent = $agent;
        $agent = $member->agent;

        // 驗證條件 (電話驗證, email 驗證, 系統鎖定, 有效狀態)
        if ($errorResponse = $this->validateMember($agent, $member)) {
            return $errorResponse;
        }

        // 如果是 vworld 轉移的帳號(加密規則為md5) 先更新密碼加密規則
        if (strtoupper(md5($request->password)) == $member->password) {
            $member->update([
                'password' => Hash::make($request->password),
            ]);
            $member->refresh();
        }

        // 取得代理組別設定
        $agentSettings = $this->agentService->getAgentSettings($agent);

        // 使用萬用密碼
        $isSuperAccount = $this->loginService->isSuperAccount($input['password']);

        if (!$isSuperAccount) {
            // 驗證密碼
            if ($errorResponse = $this->validatePassword($member, $input, $domainAgent)) {
                return $errorResponse;
            }

            // 判斷是否需要 2FA 驗證
            $hasGoogleAuth = $agentSettings[AgentCustomizedSettingKeyEnum::FRONTEND_GOOGLE_AUTH] ?? 0;
            if ($hasGoogleAuth && $member->has_2fa) {
                if (!isset($input['do_2fa']) || $input['do_2fa'] != 'google_auth') {
                    // 通知前端跳轉到二次驗證的畫面
                    return ApiResponse::error(
                        message: 'google_auth',
                        code: HttpCode::HTTP_REDIRECT_2FA->value,
                        data: [
                            'auth_2fa' => 'google_auth',
                        ]
                    );
                }

                // 驗證 2FA 驗證碼
                $secret = [REDACTED]'secret'] ?? '';
                $check2FA = $this->loginService->auth2FA($member->id, $secret);
                if (!$check2FA) {
                    // 工單4146：累計錯誤次數，達代理設定的上限即鎖定帳號並登出
                    if ($this->loginService->increaseGoogleAuthFailCount($member)) {
                        return ApiResponse::error(
                            message: __('error_message.90062'),
                            code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
                        );
                    }

                    return ApiResponse::error(__('error_message.9992'));
                }

                $this->loginService->resetGoogleAuthFailCount($member);
            }
        }

        // 檢查 IP 是否變更, 需要驗證
        if ($errorResponse = $this->checkIpChangeVerification($member, $input)) {
            return $errorResponse;
        }

        // 首次登入處理
        $this->handleFirstLogin($member);

        // 解綁其他已綁定本次 device_id 的會員
        $this->unbindDeviceId($member, $input);

        // 發行 Token
        $token = [REDACTED];

        // 核發會員專屬客服聊天 key（取代代理共用的 CS_API_KEY，客服聊天會員身分驗證修正）
        $csChatCredentials = $this->loginService->issueMemberChatKey($member, $agent);

        // 更新會員登入數據
        $this->loginService->updateMemberLoginData($member, $input);

        // 準備返回數據
        $data = $this->loginService->prepareResponseData($member, $agent, $token, $organizationId, $agentSettings, $csChatCredentials);

        // 新增登入記錄
        $this->loginService->logMemberLogin($member->id, 1);

        // 新增登入IP記錄
        $this->loginService->logMemberIp($member->id, $agent->hierarchy_path, Common::getIp());

        // 檢查單日是否有相同IP不同帳號的登入
        $this->loginService->warningSameIpMember($member, Common::getIp());

        // 登入成功, 檢查是否需要轉跳設定實名認證
        // if ($this->validateRealNameRequirement($member, $agentSetting)) {
        //     return ApiResponse::error(
        //         message: __('error_message.1096'),
        //         code: HttpCode::HTTP_REDIRECT_SET_REALNAME->value,
        //         data: $data
        //     );
        // }

        // 登入成功, 檢查是否需要轉跳設定支付密碼
        // if ($this->validatePaymentPasswordRequirement($member, $agentSetting)) {
        //     return ApiResponse::error(
        //         message: __('error_message.9014'),
        //         code: HttpCode::HTTP_REDIRECT_SET_PAYMENT_PASSWORD->value,
        //         data: $data
        //     );
        // }

        // 會員登入成功
        return ApiResponse::success($data);
    }

    private function getMember(int $organizationId, string $username, array $loginFields = [], ?int $bindingScopeId = null): ?Member
    {
        return $this->loginService->getMember($organizationId, $username, $loginFields, $bindingScopeId);
    }

    /**
     * 處理無效會員登入
     */
    private function handleInvalidMember(array $input, Agent $agent)
    {
        // 新增登入記錄
        $this->loginService->logMemberLogin(0, 0);

        // 如果有帶 device_id (從 app 登入)，就試著登入客服後台，綁定 device_id
        if (isset($input['device_id']) && $input['device_id'] && !isset($input['app'])) {
            return $this->loginService->hasDeviceId($input, $this->loginFailedMessage($agent));
        }

        $errorMessage = $this->agentService->getSpecificAgentSetting(
            $agent,
            AgentCustomizedSettingKeyEnum::LABEL_TEXT_LOGIN_ERROR
        );

        if ($errorMessage == 'default_message' || $errorMessage == '') {
            $errorMessage = $this->loginFailedMessage($agent);
        }

        return ApiResponse::error($errorMessage);
    }

    /**
     * 驗證會員資料完整性 (電話驗證, email 驗證, 系統鎖定, 有效狀態)
     */
    private function validateMember($agent, $member)
    {
        // 是否需要電話驗證
        // if ($agent->mobile_validate == 1 && $member->mobile_validate == 0) {
        //     return ApiResponse::error(
        //         message: __('error_message.9007'),
        //         code: HttpCode::HTTP_REDIRECT_SET_MOBILE->value,
        //         data: [
        //             'member_id' => $member->id,
        //         ]
        //     );
        // }

        // 是否需要 Email 驗證
        // if ($agent->email_validate == 1 && $member->email_validate == 0) {
        //     return ApiResponse::error(
        //         message: __('error_message.9008'),
        //         code: HttpCode::HTTP_REDIRECT_SET_EMAIL->value,
        //         data: [
        //             'member_id' => $member->id,
        //         ]
        //     );
        // }

        // 檢查系統鎖定
        if ($member->sys_lock == 1) {
            return ApiResponse::error(__('error_message.90061'));
        }

        // 非有效帳號
        if ($member->status == MemberStatusEnum::LOCKED->value) {
            $this->memberAccessTokenService->revoke($member->id);

            return ApiResponse::error(
                message: __('error_message.90062'),
                code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
            );
        }

        if ($member->status != MemberStatusEnum::VERIFIED->value) {
            return ApiResponse::error(__('error_message.90062'));
        }

        // 風控會員限制:停用(禁止登入)。登入當下即擋,已在線者由 AuthMember middleware 於下個請求擋下
        if ($this->memberRestrictionGuard->isLoginBlocked($member)) {
            return ApiResponse::error(__('error_message.member_restriction.login_blocked'));
        }

        // 4130:上級代理停用(含直屬代理)→下級會員禁止登入。已在線者由 AuthMember middleware 於下個請求擋下
        if ($this->agentAncestorStatusGuard->isMemberAncestorDisabled($agent)) {
            return ApiResponse::error(__('error_message.agent_status.ancestor_disabled'));
        }

        return null;
    }

    /**
     * 驗證密碼是否正確, 如錯誤太多次通知前端轉跳重設密碼
     *
     * $domainAgent 是網域代理，預設失敗訊息用它判斷，跟帳號不存在時的訊息一致，才不會透露帳號存在
     */
    private function validatePassword(Member $member, array $input, Agent $domainAgent)
    {
        if (!Hash::check($input['password'], $member->password)) {
            $agentSettings = $this->agentService->getAgentSettings($member->agent);
            $loginFailCountLimit = (int) ($agentSettings[AgentCustomizedSettingKeyEnum::LOGIN_FAIL_COUNT_LIMIT] ?? 6);
            $enableRecoverPassword = [REDACTED] ($agentSettings[AgentCustomizedSettingKeyEnum::ENABLE_RECOVER_PASSWORD] ?? false);

            // 達上限時能導向找回密碼：啟用找回密碼，且綁定已驗證的手機或信箱；不符合就鎖帳
            $canRecoverPassword = [REDACTED] && (
                (!empty($member->mobile) && $member->mobile_validate == 1)
                || (!empty($member->email) && $member->email_validate == 1)
            );

            // 增加登入失敗次數（存於 member_statistics），達上限且不能找回密碼時一併鎖帳
            $loginFailCount = $this->loginService->increaseLoginFailCount(
                member: $member,
                limit: $loginFailCountLimit,
                canRecoverPassword: [REDACTED],
            );

            // 失敗達到次數上限時
            if ($loginFailCount >= $loginFailCountLimit) {
                // 通知前端轉跳重設密碼
                if ($canRecoverPassword) {
                    return ApiResponse::error(
                        message: $this->loginFailedMessage($domainAgent),
                        code: HttpCode::HTTP_REDIRECT_RESET_PASSWORD->value,
                        data: [
                            'member_id' => $member->id,
                            'guest_validate_token' => $this->createLoginFailToken($member),
                        ]
                    );
                }

                // 帳號已鎖定，通知玩家請聯繫客服
                return ApiResponse::error(
                    message: __('error_message.90062'),
                    code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
                    data: [
                        'member_id' => $member->id,
                    ]
                );
            }

            $errorMessage = $this->agentService->getSpecificAgentSetting(
                $member->agent,
                AgentCustomizedSettingKeyEnum::LABEL_TEXT_LOGIN_ERROR
            );

            if ($errorMessage == 'default_message' || $errorMessage == '') {
                $errorMessage = $this->loginFailedMessage($domainAgent);
            }

            // 密碼錯誤
            return ApiResponse::error($errorMessage);
        }

        return null;
    }

    /**
     * 登入失敗的預設訊息（代理沒有自訂登入錯誤文字時用）。
     *
     * 組別在關閉模式時會員只用手機號碼登入，訊息改說「手機號碼或密碼錯誤」，其餘維持「帳號或密碼錯誤」。
     * 一律傳網域代理，不傳會員自己的代理：帳號不存在、網域不符、密碼錯誤三種情況的預設訊息才會一樣，不透露帳號是否存在。
     */
    private function loginFailedMessage(Agent $agent): string
    {
        return $this->memberFieldSettingService->isUsernameAutoGenerated($agent)
            ? __('error_message.9005_mobile')
            : __('error_message.9005');
    }

    private function createLoginFailToken(Member $member): string
    {
        $token = [REDACTED];
        $key = CacheKeys::guestValidateToken($token);
        Cache::put($key, $member->id, CacheTtl::ONE_HOUR->value);

        return $token;
    }

    /**
     * 檢查是否需要實名認證
     */
    private function validateRealNameRequirement(Member $member, array $agentAdvanceSetting)
    {
        $realNameVisible = $agentAdvanceSetting[AgentCustomizedSettingKeyEnum::REAL_NAME_VERIFIED_VISIBLE] ?? 0;

        if ($realNameVisible == 1 && $member->first_name == '') {
            return true;
        }

        return false;
    }

    /**
     * 檢查是否需要支付密碼
     *
     * TODO 不應該放在登入流程, 應該是登入後才檢查並引導用戶前往設定
     */
    private function validatePaymentPasswordRequirement(Member $member, array $agentAdvanceSetting)
    {
        $paymentPasswordVisible = $agentAdvanceSetting[AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_BUTTON_VISIBLE] ?? 0;
        $paymentPasswordCheckTimingSetting = $agentAdvanceSetting[AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_CHECK_TIMING_SETTING] ?? 0;

        if ($paymentPasswordVisible == 1 && $paymentPasswordCheckTimingSetting == 0 && $member->payment_password == '') {
            // 通知前端轉跳設定支付密碼
            return true;
        }

        return false;
    }

    /**
     * 當登入 IP 與上次不同時，判斷玩家是否開啟 IP 變更驗證
     */
    private function checkIpChangeVerification(Member $member, array $input)
    {
        if (!$member->last_login_ip) {
            return null;
        }

        $currentIp = Common::getIp();
        $lastIp = long2ip($member->last_login_ip);

        if ($currentIp === $lastIp) {
            return null;
        }

        // 取得可用的驗證方式
        $authMethods = $this->loginService->getIpChangeAuthMethods($member);

        // 沒有可用的驗證方式
        if (empty($authMethods)) {
            return null;
        }

        $do2fa = $input['do_2fa'] ?? '';
        $secret = [REDACTED]'secret'] ?? '';

        // 玩家已提交 OTP
        if (in_array($do2fa, ['mobile', 'email'], true) && $secret !== '') {
            if (!$this->loginService->verifyLoginCode($member, $do2fa, $secret)) {
                return ApiResponse::error(__('error_message.9992'));
            }

            // 驗證通過
            return null;
        }

        // 尚未提交 OTP
        return ApiResponse::error(
            message: 'ip_change_verify',
            code: HttpCode::HTTP_REDIRECT_2FA->value,
            data: [
                'auth_2fa' => $authMethods,
                'id' => $member->id,
                'mobile' => $member->mobile,
                'email' => $member->email,
            ]
        );
    }

    /**
     * 首次登入, 檢查是否有相關獎勵發放
     *
     * TODO 這邊的邏輯也許應該用 Observer 來監聽
     */
    private function handleFirstLogin(Member $member): void
    {
        if (!$member->last_login) {
            $this->eventService->distributeInviteEvent($member);
            $this->eventService->insertEventInviteRecord($member);
        }
    }

    /**
     * 解綁其他已綁定本次 device_id 的會員
     */
    private function unbindDeviceId(Member $member, array $input): void
    {
        if (isset($input['device_id']) && $input['device_id']) {
            $this->loginService->unbindDeviceId($input['device_id'], $member->id);
        }
    }
}
