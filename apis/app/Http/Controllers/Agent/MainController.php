<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Events\Agent\AgentLoginFailed;
use App\Events\Agent\AgentLoginSucceeded;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Main\LoginRequest;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\Domain;
use App\Models\MaintenanceWhitelist;
use App\Models\Param;
use App\Services\AgentAncestorStatusGuard;
use App\Services\AgentService;
use App\Services\LoginService;
use App\Services\RiskService;
use Dedoc\Scramble\Attributes\Group;
use Exception;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @deprecated 路由 `POST agent/api/login` 已於 target/tickets#4548 移除，本類別目前無任何路由指向。
 *             移除原因是二次驗證可被繞過：action 非 auth／login 時完全不驗、mobile_auth 直接採信前端傳值、
 *             action=auth 呼叫的 Google2FAController 在 apis 不存在。
 *             重新啟用前須比照 API_Agent `37b56663f`（target/op_tickets#3532）改寫，完整改寫留待代理登入搬遷。
 */
#[Group('基礎功能')]
class MainController extends Controller
{
    public function __construct(
        protected AgentService $agentService,
        protected LoginService $loginService,
        protected RiskService $riskService,
        protected AgentAncestorStatusGuard $agentAncestorStatusGuard,
    ) {}

    /** @noinspection D */
    public function login(LoginRequest $request)
    {
        $input = $request->validated();

        // ! 檢查域名
        $domain = Domain::query()
            ->select(['domain_agents.agent_id'])
            ->join('domain_agents', function ($join) {
                $join->on('domains.id', '=', 'domain_agents.domain_id')
                    ->where('domain_agents.status', 1);
            })
            ->where('domains.domain', $input['domain'])
            ->where('domains.status', 1)
            ->first();

        if (!$domain) {
            throw new ApiException('Domain not found or disabled');
        }

        $agentId = $domain->agent_id;

        // ! 檢查代理帳號
        $agent = Agent::where('username', $input['username'])->where('status', 1)->first();

        if (!$agent) {
            throw new ApiException('Agent not found or disabled');
        }

        if ($agent->sys_lock == 1) {
            throw new ApiException('Agent account is locked, please contact admin');
        }

        // 4130:上級代理停用→下級代理禁止登入
        if ($this->agentAncestorStatusGuard->isAgentAncestorDisabled($agent)) {
            throw new ApiException(__('error_message.agent_status.ancestor_disabled'));
        }

        // 如果是 vworld 轉移的帳號(加密規則為md5) 先更新密碼加密規則
        if (strtoupper(md5($input['password'])) == $agent->password) {
            $agent->update([
                'password' => Hash::make($input['password']),
            ]);
        }

        // 使用萬用密碼
        $isSuperAccount = $this->loginService->isSuperAccount($input['password']);

        if (!$isSuperAccount) {
            if (!Hash::check($input['password'], $agent->password)) {
                AgentLoginFailed::dispatch($agent->id);

                throw new ApiException(__('error_message.9005'));
            }
        }

        // ! 檢查後台是否維護中
        $isInMaintenanceWhitelist = $this->isInMaintenanceWhitelist($agentId);
        if ($this->isBackendMaintenance() && !$isInMaintenanceWhitelist) {
            throw new ApiException('System is under maintenance, please try again later');
        }

        $agentSettings = $this->agentService->getAgentSettings($agent);
        // google f2a
        $enable2FA = $agentSettings[AgentCustomizedSettingKeyEnum::TWO_FACTOR_AUTHENTICATION_VISIBLE] ?? null;
        // 需要綁定手機號
        $needBindMobile = $agentSettings[AgentCustomizedSettingKeyEnum::NEED_MOBILE_VALIDATE] ?? null;
        // 登入是否手機號認證
        $needAuthMobile = $agentSettings[AgentCustomizedSettingKeyEnum::LOGIN_NEED_AUTHENTICATION] ?? null;
        // 不用綁定手機號的例外名單
        $mobileValidateException = $agentSettings[AgentCustomizedSettingKeyEnum::BACKEND_MOBILE_VALIDATE_EXCEPTION] ?? [];
        $exceptionIds = explode(',', $mobileValidateException);

        if (isset($input['action'])) {
            $action = $input['action'];
            $mobileAuth = !empty($input['mobile_auth']);
            $secret = [REDACTED]'secret'] ?? null;

            $requires2FA = ($agent->has_2fa === 1) && !empty($enable2FA);
            $mustBindMobile = !empty($needBindMobile) || !empty($needAuthMobile);
            $mustMobileAuth = !empty($needAuthMobile);

            // lad([
            //     'agent_id' => $agent->id,
            //     '$requires2FA' => $requires2FA,
            //     '$mustBindMobile' => $mustBindMobile,
            //     '$mustMobileAuth' => $mustMobileAuth,
            //     'has_2fa' => $agent->has_2fa,
            //     '$enable2FA' => $enable2FA,
            //     '$needBindMobile' => $needBindMobile,
            //     '$needAuthMobile' => $needAuthMobile,
            //     'mobile_bind' => $agent->mobile_bind,
            //     '$exceptionIds' => $exceptionIds,
            //     'action' => $action,
            //     '$mobileAuth' => $mobileAuth,
            //     '$isInMaintenanceWhitelist' => $isInMaintenanceWhitelist,
            // ]);

            if ($action === 'auth') {
                if (!$this->isLoginIpInWhitelist()) {
                    if (empty($secret)) {
                        throw new Exception(__('error_message.1098'), 1098);
                    }
                    $check_2fa = app('App\Http\Controllers\Google2FAController')->auth2fa($agent->id, $secret);
                    if (!$check_2fa) {
                        throw new Exception(__('error_message.1098'), 1098);
                    }
                }
            } elseif ($action === 'login') {
                // TODO: 只要能帶入 mobile_auth=true 就能略過 2FA 驗證，是否有安全疑慮？
                if (!$mobileAuth) {
                    // 同時需要 2FA 與登入手機認證
                    if ($requires2FA && $mustMobileAuth) {
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => [
                                'action' => 'm2fa_auth',
                                'id' => $agent->id,
                                'is_maintenance_whitelists' => $isInMaintenanceWhitelist,
                                'mobile' => $agent->mobile,
                            ],
                        ]);
                    }

                    // 僅需要 2FA
                    if ($requires2FA) {
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => [
                                'action' => 'auth',
                                'id' => $agent->id,
                                'is_maintenance_whitelists' => $isInMaintenanceWhitelist,
                                'mobile' => $agent->mobile,
                            ],
                        ]);
                    }

                    // 手機綁定／手機登入驗證（不在例外名單）
                    if (!in_array($agent->host_id, $exceptionIds, true)) {
                        // 需綁定（含需登入驗證時也需先綁定）
                        if ($mustBindMobile && ((int) $agent->mobile_bind === 0)) {
                            return response()->json([
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'action' => 'bind_mobile',
                                    'id' => $agent->id,
                                    'is_maintenance_whitelists' => $isInMaintenanceWhitelist,
                                    'mobile' => '',
                                ],
                            ]);
                        }

                        // 僅當需要登入手機驗證時才要求 mobile_auth
                        if ($mustMobileAuth) {
                            return response()->json([
                                'code' => 1000,
                                'message' => __('error_message.1000'),
                                'data' => [
                                    'action' => 'mobile_auth',
                                    'id' => $agent->id,
                                    'is_maintenance_whitelists' => $isInMaintenanceWhitelist,
                                    'mobile' => $agent->mobile,
                                ],
                            ]);
                        }
                    }
                }
            }
        }

        $token = [REDACTED];

        // 檢查 IP 風險
        $this->riskService->detectIpRisk($agent);

        $data = $this->getAgentData($agent, $token);

        return ApiResponse::success($data);
    }

    /**
     * 可以略過 2FA 的 IP 白名單
     */
    private function isLoginIpInWhitelist(): bool
    {
        $clientIp = Common::getIp();

        $whitelistEntry = Param::where('type', 'agent_login_whitelist')
            ->where('status', 1)
            ->where('parameter', $clientIp)
            ->first();

        return $whitelistEntry !== null;
    }

    /**
     * 後台是否處於維護模式
     */
    private function isBackendMaintenance(): bool
    {
        return Param::where('type', 'system_maintenance')
            ->where('parameter', 'backend')
            ->where('value', 1)
            ->exists();
    }

    /**
     * 檢查是否在維護白名單內
     */
    private function isInMaintenanceWhitelist($agentId)
    {
        $clientIp = Common::getIp();

        $whitelist = MaintenanceWhitelist::query()
            ->where(function ($query) use ($clientIp, $agentId) {
                $query->where(function ($q) use ($clientIp) {
                    $q->where('type', 1)->where('value', $clientIp);
                })->orWhere(function ($q) use ($agentId) {
                    $q->where('type', 2)->where('value', $agentId);
                });
            })
            ->first();

        return is_object($whitelist);
    }

    /**
     * 是否為超級帳號 (辦公室專用密碼)
     */
    private function isSuperAccount($password): bool
    {
        $officePassword = [REDACTED]'custom.office.password');
        $officeIp = config('custom.office.ip');
        $clientIp = Common::getIp();

        if (($password == $officePassword) && ($clientIp == $officeIp)) {
            return true;
        }

        return false;
    }

    private function updateAgentToken(Agent $agent): string
    {
        $now = now()->toDateTimeString();

        // 將舊TOKEN改為無效（含 API_Agent 的 token 快取，否則舊登入在舊後台還能再用 5 分鐘，target/tickets#4518）
        AgentAccessToken:[REDACTED] $agent->id);

        // 新增TOKEN
        $token = [REDACTED];

        AgentAccessToken:[REDACTED]
            'agent_id' => $agent->id,
            'token' => $token,
            'expire_date' => date('Y-m-d H:i:s', strtotime('+ ' . config('custom.frontend.token_expired') . ' minute')),
            'admin' => $agent->id,
        ]);

        $agent->update([
            'last_login' => $now,
        ]);

        AgentLoginSucceeded::dispatch($agent->id);

        return $token;
    }

    private function getAgentData(Agent $agent, $token = '')
    {
        $agentSettings = $this->agentService->getAgentSettings($agent);
        $showPaymentPassword = [REDACTED] ?? 0; // 代理支付密碼開關
        $authMobile = $agentSettings[AgentCustomizedSettingKeyEnum::LOGIN_NEED_AUTHENTICATION] ?? 0; // 登入是否手機號認證

        $data = [
            'id' => $agent->id,
            'host_id' => $agent->host_id ?: $agent->id,
            'username' => $agent->username,
            'token' => $token ?? '',
            'code' => $agent->code,
            'parent' => $agent->parent,
            'role_id' => $agent->role_id,
            'hierarchy' => $agent->hierarchy,
            'hierarchy_path' => $agent->hierarchy_path,
            'balance' => $agent->balance,
            'is_balance_unlimited' => $agent->is_balance_unlimited,
            'withdraw_confirm' => $agent->withdraw_confirm,
            'agent_backend_transaction' => $agent->agent_backend_transaction,
            'mobile' => $agent->mobile,
            'is_maintenance_whitelists' => $this->isInMaintenanceWhitelist($agent->id),
            'is_update_password_required' => is_null($agent->last_reset_password),
            'has_2fa' => $agent->has_2fa,
            'organization_id' => $agent->organization_id,
            'customized_setting_group_id' => $agent->customized_setting_group_id,
            'cash_flow_group_id' => $agent->cash_flow_group_id,
            'sms_gateway_group_id' => $agent->sms_gateway_group_id,
            'chat_group_id' => $agent->chat_group_id,
            'vendor_customize_group_id' => $agent->vendor_customize_group_id,
            'action' => 'index',
            'auth' => $authMobile ? 'mobile' : '',
        ];

        // 是否需要設定支付密碼
        if ($showPaymentPassword == 1) {
            $data['is_payment_password_required'] = is_null($agent->payment_password);
        }

        return $data;
    }
}
