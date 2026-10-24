<?php

declare(strict_types=1);

namespace App\Services;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\MemberStatusLogTypeEnum;
use App\Enums\ValidationCodeTypeEnum;
use App\Facades\BotNotify;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\LoginEvent;
use App\Models\Member;
use App\Models\MemberAuthentication;
use App\Models\MemberLoginIpLog;
use App\Models\MemberLoginLog;
use App\Models\Param;
use App\Models\ValidateToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class LoginService
{
    /** Google 2FA 錯誤次數上限預設值（代理未設定時，工單4146） */
    private const int GOOGLE_AUTH_FAIL_COUNT_LIMIT_DEFAULT = 6;

    public function __construct(
        private MemberAccessTokenService $memberAccessTokenService,
        private MemberCodeService $memberCodeService,
        private AgentService $agentService,
        private MemberFailCountService $memberFailCountService,
    ) {}

    /**
     * 依登入識別方式查會員。
     *
     * 3969:$loginFields 為 login config 啟用的識別欄位(username/mobile)。
     *  - config 有 → 依 config 決定認帳號/手機/兩者
     *  - config 空(升級失敗/查不到/舊站無設定) → fallback 原邏輯(帳號 OR 手機皆認),不擋登入
     *
     * 手機號登入的範圍＝同組織＋同 binding_scope_id（4309：同組織下以 scope 分站的品牌，
     * 同一支已驗證手機可各站各一個會員，登入時必須只認「本站」那位，否則 first() 會撈到
     * 別站的會員而密碼不符）。$bindingScopeId 為 null 時維持只看組織（舊呼叫端相容）。
     * 帳號登入不套 scope：username 全站唯一，且既有行為允許同組織跨站以帳號登入。
     *
     * 已知限制(既有行為,非 3969 引入):同 scope 內手機號綁定上限 > 1 時同一號碼仍可對應
     * 多個會員,first() 只會取到其中一筆(通常是 id 最小者),其餘會員以手機號登入會查到他人
     * 帳號而密碼不符、登入失敗。密碼為各自 bcrypt 雜湊,不會誤登入他人帳號。
     * 正式環境實查:此情形集中於 TPower(上限 30),多為玩家共用同一號碼註冊所致。
     *
     * @param  array<int, string>  $loginFields
     */
    public function getMember(int $organizationId, string $username, array $loginFields = [], ?int $bindingScopeId = null): ?Member
    {
        // 空 config → 兩者皆認(fallback 原始行為)
        $allowUsername = $loginFields === [] || in_array('username', $loginFields, true);
        $allowMobile = $loginFields === [] || in_array('mobile', $loginFields, true);

        // 防呆:兩者皆不允許(config 異常)時不查(避免空 where 撈出全部會員)
        if (!$allowUsername && !$allowMobile) {
            return null;
        }

        // 4130:此處不過濾代理 status——直屬代理停用時仍須查得會員,
        // 由 LoginController::validateMember 的上級停用檢查回明確訊息(而非 9005 帳密錯誤)
        return Member::query()
            ->with(['agent'])
            ->whereHas(
                'agent',
                function (Builder $query) use ($organizationId) {
                    $query->where('organization_id', $organizationId);
                }
            )
            ->where(function (Builder $query) use ($username, $allowUsername, $allowMobile, $bindingScopeId) {
                if ($allowUsername) {
                    $query->orWhere('username', $username);
                }
                // 手機號登入(需已驗證)，且限本站 binding_scope
                if ($allowMobile) {
                    $query->orWhere(function (Builder $query) use ($username, $bindingScopeId) {
                        $query->where('mobile', $username);
                        $query->where('mobile_validate', 1);
                        if ($bindingScopeId !== null) {
                            $query->whereHas('agent', fn (Builder $agent) => $agent->where('binding_scope_id', $bindingScopeId));
                        }
                    });
                }
            })
            ->first();
    }

    /**
     * 核發會員專屬的客服聊天 key，取代代理共用的 CS_API_KEY（客服聊天會員身分驗證修正）。
     *
     * CS 故障不影響登入：任何失敗（呼叫異常、回應格式不對、key 是空的）一律退回代理共用值，
     * 不拋例外、不中斷登入。
     *
     * @return array{api_user: string, api_key: [REDACTED]
     */
    public function issueMemberChatKey(Member $member, Agent $agent): array
    {
        $fallback = [
            'api_user' => (string) $agent->CS_API_USER,
            'api_key' => (string) $agent->CS_API_KEY,
        ];

        if (empty($agent->CS_API_USER) || empty($agent->CS_API_KEY)) {
            // 這個代理根本沒接 CS
            return $fallback;
        }

        $result = Common::postToSocket('issueMemberApiKey', [
            'API_USER' => $agent->CS_API_USER,
            'API_KEY' => $agent->CS_API_KEY,
            'THIRD_PARTY_ID' => $member->username,
            'EXPIRE_MINUTES' => config('custom.frontend.token_expired'),
        ]);

        if (!is_array($result) || ($result['code'] ?? null) != 1000 || empty($result['data']['key'])) {
            Log::error('CS 會員 key 申請失敗，退回代理共用 key', ['member_id' => $member->id]);

            return $fallback;
        }

        return [
            'api_user' => (string) $agent->CS_API_USER,
            'api_key' => (string) $result['data']['key'],
        ];
    }

    /**
     * @param  string  $failedMessage  客服登入失敗時回的訊息，由呼叫端依組別是否為關閉模式決定
     */
    public function hasDeviceId($input, string $failedMessage): JsonResponse
    {
        // 直接呼叫客服 api 登入
        $result = Common::postToSocket('third_party_login', $input);
        if (is_array($result) && $result['code'] == 1000) {
            // 客服登入成功回傳 token
            return ApiResponse::success($result['data']);
        } else {
            // 帳號密碼錯誤
            return ApiResponse::error($failedMessage);
        }
    }

    /**
     * 使用萬用密碼帳號且為辦公室IP
     */
    public function isSuperAccount($password): bool
    {
        return false;
        /*
        $officePassword = [REDACTED]'custom.office.password');
        $officeIp = config('custom.office.ip');
        $clientIp = Common::getIp();

        if (($password == $officePassword) && ($clientIp == $officeIp)) {
            return true;
        }

        return false;
        */
    }

    /**
     * 驗證 Google 2FA
     */
    public function auth2FA($memberId, $secret): bool|int
    {
        $auth = MemberAuthentication::where('member_id', $memberId)
            ->where('status', 1)
            ->first();

        // 未啟用 2FA 的會員沒有 secret，視同驗證失敗（原本直接取屬性會 500）
        if ($auth === null) {
            return false;
        }

        /** @noinspection PhpUnhandledExceptionInspection */
        return (new Google2FA)->verifyKey($auth->secret, $secret);
    }

    /**
     * 登入密碼錯誤處理：累計失敗次數，達上限且不能導向找回密碼時鎖定帳號並作廢 token。
     *
     * 計次、鎖帳與作廢 token 的順序和交易邊界見 MemberFailCountService::increaseAndLock()：
     * 作廢 token 失敗時帳號仍維持鎖定，因此不可在外層交易內呼叫本方法。
     * 達上限要導向找回密碼時，訪客驗證 token（寫快取）由呼叫端在本方法之後發放。
     *
     * @param  int  $limit  代理設定的登入失敗次數上限
     * @param  bool  $canRecoverPassword  達上限時能否導向找回密碼（啟用找回密碼，且綁定已驗證的手機或信箱）
     * @return int 累計後的失敗次數
     */
    public function increaseLoginFailCount(Member $member, int $limit, bool $canRecoverPassword): int
    {
        return $this->memberFailCountService->increaseAndLock(
            $member,
            MemberStatusLogTypeEnum::LOGIN_PASSWORD_LIMIT,
            static fn (int $failCount): bool => $failCount >= $limit && !$canRecoverPassword,
        );
    }

    /**
     * Google 2FA 驗證失敗處理（工單4146）：累計次數，達代理設定的上限即鎖定帳號並登出。
     *
     * 次數記於 member_statistics.google_auth_fail_count。兩個呼叫入口（登入流程的 2FA、google-auth/auth）
     * 都不用先登入就能打，跟登入密碼一樣：作廢 token 失敗時帳號仍維持鎖定，
     * 計次與交易邊界見 MemberFailCountService::increaseAndLock()，因此不可在外層交易內呼叫本方法。
     *
     * @return bool 是否已達上限（本次鎖定，或帳號原本就已鎖定）
     */
    public function increaseGoogleAuthFailCount(Member $member): bool
    {
        $agentSettings = $this->agentService->getAgentSettings($member->agent);
        $limit = (int) ($agentSettings[AgentCustomizedSettingKeyEnum::GOOGLE_AUTH_FAIL_COUNT_LIMIT] ?? self::GOOGLE_AUTH_FAIL_COUNT_LIMIT_DEFAULT);

        $failCount = $this->memberFailCountService->increaseAndLock(
            $member,
            MemberStatusLogTypeEnum::GOOGLE_AUTH_LIMIT,
            static fn (int $failCount): bool => $failCount >= $limit,
        );

        return $failCount >= $limit;
    }

    /**
     * Google 2FA 驗證通過：歸零錯誤次數（工單4146）
     */
    public function resetGoogleAuthFailCount(Member $member): void
    {
        $statistics = $member->statistics()->firstOrCreate([]);

        if ((int) $statistics->google_auth_fail_count === 0) {
            return;
        }

        $statistics->google_auth_fail_count = 0;
        $statistics->save();
    }

    /**
     * 紀錄用戶登入
     */
    public function logMemberLogin($memberId, $status): void
    {
        // 新增登入記錄
        $log = [
            'member_id' => $memberId,
            'ip' => Common::getIp(),
            'status' => $status,
            'admin' => $memberId,
        ];

        MemberLoginLog::create($log);
    }

    /**
     * 添加獎勵前, 檢查封禁條件
     *
     * @deprecated
     */
    public function checkBlock($member, $event): bool
    {
        if ($event->ip_block) {
            $members = DB::table('members')->where('register_ip', $member->register_ip)->where('id', '!=', $member->id)->get();
            if (is_null($member->register_ip) || count($members) > 1) {
                return false;
            }
        }

        if ($event->phone_block) {
            $members = DB::table('members')->where('mobile', $member->mobile)->where('id', '!=', $member->id)->get();
            if (is_null($member->mobile) || count($members) > 1) {
                return false;
            }
        }

        if ($event->account_block) {
            $members = DB::table('members')->where('invitation_code', $member->first_name)->where('id', '!=', $member->id)->get();
            if (is_null($member->first_name) || count($members) > 1) {
                return false;
            }
        }

        return true;
    }

    public function genToken(int $memberId): string
    {
        return $this->memberAccessTokenService->issue($memberId);
    }

    /**
     * 解綁 Device ID
     */
    public function unbindDeviceId(string $deviceId, int $memberId): void
    {
        // 解綁其他會員的 device_id
        Member::query()
            ->where('device_id', $deviceId)
            ->where('id', '!=', $memberId)
            ->update(['device_id' => null]);
    }

    /**
     * 取得 IP 變更驗證可用的驗證方式
     *
     * @return list<string>
     */
    public function getIpChangeAuthMethods(Member $member): array
    {
        $methods = [];

        if ($member->ip_change_mobile_verify == 1 && $member->mobile_validate == 1 && $member->mobile) {
            $methods[] = 'mobile';
        }

        if ($member->ip_change_email_verify == 1 && $member->email_validate == 1 && $member->email) {
            $methods[] = 'email';
        }

        return $methods;
    }

    /**
     * 驗證登入的 OTP 驗證碼
     *
     * @param  'mobile'|'email'  $do2fa
     */
    public function verifyLoginCode(Member $member, string $do2fa, string $secret): bool
    {
        $verifyBy = match ($do2fa) {
            'mobile' => 1,
            'email' => 2,
            default => 0,
        };

        if ($verifyBy === 0) {
            return false;
        }

        $typeId = ValidationCodeTypeEnum::LOGIN_PHONE_EMAIL->value . $verifyBy;

        $validateToken = [REDACTED]'member_id', $member->id)
            ->where('type_id', $typeId)
            ->where('token', $secret)
            ->where('status', 0)
            ->where('expire_date', '>=', now())
            ->first();

        if (!$validateToken) {
            return false;
        }

        $validateToken->update(['status' => 1]);

        return true;
    }

    /**
     * 更新會員登入數據
     */
    public function updateMemberLoginData(Member $member, array $input): void
    {
        $deviceId = $input['device_id'] ?? '';
        // ! 同一組 Device token 允許一位會員及一位代理帳號同時綁定，需解除其他會員的 device_id 綁定
        if ($deviceId) {
            Member::where('device_id', $deviceId)
                ->where('id', '!=', $member->id)
                ->update(['device_id' => null]);
        }

        $updateData = [
            'last_login' => now(),
            'last_login_ip' => ip2long(Common::getIp()),
            'device_type' => $input['platform'] ?? '',
            'device_id' => $deviceId,
        ];

        // 若會員的註冊網址為空，則更新為登入網址
        if (empty($member->register_domain) && !empty($input['domain'])) {
            $updateData['register_domain'] = $input['domain'];
        }

        $member->update($updateData);

        // 登入成功，重置登入/驗證碼失敗次數（存於 member_statistics）
        $member->statistics()->firstOrCreate([])->update([
            'login_fail_count' => 0,
            'validation_code_fail_count' => 0,
        ]);

        $this->clearCache($member);
    }

    /**
     * 準備返回數據
     *
     * @param  array{api_user: string, api_key: [REDACTED]  $csChatCredentials  見 issueMemberChatKey()
     */
    public function prepareResponseData(Member $member, Agent $agent, string $token, int $organizationId, array $agentSettings, array $csChatCredentials): array
    {
        $eventMode = ($member->event_mode > 0) ? 1 : 0;
        // 如果目前在活動鎖定中，就查詢登入活動是否過期
        $unlockEventMode = 0;
        if ($eventMode == 1) {
            $unlockEventMode = LoginEvent::where('id', $member->event_mode)
                ->where(function ($query) {
                    $query->where('end_time', '<=', now())
                        ->orWhere('status', 0);
                })
                ->exists();
            // TODO 應該直接將 event_mode 更新為 0 而不是在返回 is_force_unlock_event_mode
        }

        // ! wintoto / T-Power 分享碼改用玩家的分享碼
        $shareCode = $agent->code;
        if (in_array($organizationId, [11, 21])) {
            if ($agentSettings[AgentCustomizedSettingKeyEnum::INVITATION_CODE_SAME_ACCOUNT] == 1) {
                $shareCode = $member['username'];
            } else {
                // 查詢會員分享碼，若無則新建（集中由 MemberCodeService 原子化處理）
                $shareCode = $this->memberCodeService->get($member)->agent_code;
            }
        }

        return [
            'id' => $member->id,
            'username' => $member->username,
            'email' => $member->email,
            'nickname' => $member->nickname,
            'balance' => $member->balance,
            'image' => $member->image,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'mobile' => $member->mobile,
            'token' => $token,
            'event_mode' => $eventMode,
            'is_force_unlock_event_mode' => $unlockEventMode,
            'last_login' => $member->last_login,
            // 加上進聊天室要用的欄位
            'CS_API_USER' => $csChatCredentials['api_user'],
            'CS_API_KEY' => $csChatCredentials['api_key'],
            'SHARE_CODE' => $shareCode,
        ];
    }

    /**
     * 紀錄用戶的登入IP
     * 針對馬來, 假如有代理帳號使用相同IP登入，就發送telegram通知
     */
    public function logMemberIp($memberId, $hierarchyPath, string $ip): void
    {
        // 排除公司IP
        $excludeIps = ['0.0.0.0', '0.0.0.0', '0.0.0.0'];

        // 登入成功的話 寫進IP查詢的表
        $this->logIpIfNotExcluded($memberId, $ip, $excludeIps);

        // 登入限定馬來
        // $MalayHierarchyPath = '/1/33893/66829';
        // if (Str::startsWith($hierarchyPath, $MalayHierarchyPath)) {
        //     $this->handleMalayLogin($memberId, $ip, $MalayHierarchyPath, $excludeIps);
        // }
    }

    private function logIpIfNotExcluded($memberId, string $ip, array $excludeIps): void
    {
        // 合法 IP（IPv4 或 IPv6）才記錄
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }

        if (in_array($ip, $excludeIps)) {
            return;
        }

        // 四段僅供 IPv4（NOT NULL）：IPv4 照舊拆四段，IPv6 四段填 0；完整字串一律存 ip_address
        $isIpv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $myIp = $isIpv4 ? explode('.', $ip) : [0, 0, 0, 0];

        MemberLoginIpLog::create([
            'member_id' => $memberId,
            'ip_1' => $myIp[0],
            'ip_2' => $myIp[1],
            'ip_3' => $myIp[2],
            'ip_4' => $myIp[3],
            'ip_address' => $ip,
            'admin' => $memberId,
        ]);
    }

    private function handleMalayLogin($memberId, string $ip, string $malayHierarchyPath, array $excludeIps): void
    {
        // agent_login_ip_logs / member_login_ip_logs 採四段式欄位，僅支援 IPv4
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return;
        }

        $myIp = explode('.', $ip);
        $searchTime = date('Y-m-d H:i:s', strtotime('-7day'));

        $agentIpLog = $this->getAgentIpLog($myIp, $malayHierarchyPath, $memberId, $searchTime);
        $memberIpLog = $this->getMemberIpLog($myIp, $malayHierarchyPath, $searchTime);

        if (count($agentIpLog) > 0 || count($memberIpLog) > 1) {
            $this->sendIpNotification($myIp, $agentIpLog, $memberIpLog, $excludeIps);
        }
    }

    private function getAgentIpLog(array $myIp, string $MalayHierarchyPath, $memberId, string $search_time): Collection
    {
        return DB::table('agent_login_ip_logs as il')
            ->join('agents as a', function ($join) use ($MalayHierarchyPath) {
                $join->on('a.id', 'il.agent_id')
                    ->where('a.hierarchy_path', 'like', $MalayHierarchyPath . '%');
            })
            ->where('il.ip_1', $myIp[0])
            ->where('il.ip_2', $myIp[1])
            ->where('il.ip_3', $myIp[2])
            ->where('il.ip_4', $myIp[3])
            ->where('il.agent_id', '!=', $memberId)
            ->where('il.created_at', '>=', $search_time)
            ->groupBy(['a.username'])
            ->orderBy(DB::raw('MAX(il.created_at)'), 'DESC')
            ->select(
                'a.username as name',
                DB::raw('DATE_ADD(il.created_at,INTERVAL 8 HOUR) as created_at')
            )
            ->get();
    }

    private function getMemberIpLog(array $myIp, string $MalayHierarchyPath, string $search_time): Collection
    {
        return DB::table('member_login_ip_logs as il')
            ->join('members as m', 'm.id', 'il.member_id')
            ->join('agents as a', function ($join) use ($MalayHierarchyPath) {
                $join->on('a.id', 'm.parent')
                    ->where('a.hierarchy_path', 'like', $MalayHierarchyPath . '%');
            })
            ->where('il.ip_1', $myIp[0])
            ->where('il.ip_2', $myIp[1])
            ->where('il.ip_3', $myIp[2])
            ->where('il.ip_4', $myIp[3])
            ->where('il.created_at', '>=', $search_time)
            ->groupBy(['m.username'])
            ->orderBy(DB::raw('MAX(il.created_at)'), 'DESC')
            ->select(
                'm.username as name',
                DB::raw('MAX(DATE_ADD(il.created_at,INTERVAL 8 HOUR)) as created_at')
            )
            ->get();
    }

    private function sendIpNotification(array $myIp, $agentIpLog, $memberIpLog, array $excludeIps): void
    {
        $ip = implode('.', $myIp);

        if (in_array($ip, $excludeIps, true)) {
            return;
        }

        $lines = [];
        $lines[] = '偵測到相同 IP 登入';
        $lines[] = 'IP：' . $ip;

        if (count($agentIpLog) > 0) {
            $lines[] = '--- 代理登入紀錄 ---';
            foreach ($agentIpLog as $log) {
                $lines[] = sprintf('%s 代理：%s', $log->created_at, $log->name);
            }
        }

        if (count($memberIpLog) > 0) {
            $lines[] = '--- 會員登入紀錄 ---';
            foreach ($memberIpLog as $log) {
                $lines[] = sprintf('%s 會員：%s', $log->created_at, $log->name);
            }
        }

        BotNotify::send(implode(PHP_EOL, $lines));
    }

    /**
     * 檢查單日是否有相同IP不同帳號的登入
     */
    public function warningSameIpMember(Member $member, string $ip): void
    {
        $today = date('Y-m-d');

        if ($this->isIpWhitelisted($ip)) {
            return;
        }

        // 如果不在白名單內才要檢查
        // 先去查params裡面存的今天第一筆id
        $idInfo = $this->getTodayFirstLoginId($today);
        if (!$idInfo) {
            return;
        }

        // 如果日期是昨天就查新的,並更新成新的id
        $firstId = $this->updateFirstLoginIdIfNeeded($idInfo, $today);

        $ipLogins = $this->getIpLogins($firstId, $today, $ip);
        if ($ipLogins->count() < 2) {
            return;
        }

        // 如果超過一筆就傳TG
        $this->sendIpLoginWarnings($ipLogins, $member, $ip);
    }

    private function isIpWhitelisted(string $ip): bool
    {
        return DB::table('params')
            ->where('type', 'tg_login_ip_whitelist')
            ->where('parameter', $ip)
            ->where('status', 1)
            ->exists();
    }

    private function getTodayFirstLoginId(string $today): ?Param
    {
        return Param::where('type', 'member_login_logs_id')
            ->where('status', 1)
            ->first();
    }

    private function updateFirstLoginIdIfNeeded(Param $idInfo, string $today): int
    {
        $firstId = $idInfo->value;

        if ($today > $idInfo->parameter) {
            $newIdInfo = DB::table('member_login_logs')
                ->where('id', '>=', $idInfo->id)
                ->where('created_at', '>=', $today . ' 00:00:00')
                ->orderBy('id', 'ASC')
                ->first();

            if ($newIdInfo) {
                $firstId = $newIdInfo->id;
                $idInfo->update(['parameter' => $today, 'value' => $newIdInfo->id]);
            }
        }

        return $firstId;
    }

    private function getIpLogins(int $firstId, string $today, string $ip): Collection
    {
        $subQuery = DB::table('member_login_logs')
            ->where('id', '>=', $firstId)
            ->where('created_at', '>=', $today . ' 00:00:00')
            ->where('ip', 'like', $ip . ',%')
            ->where('status', 1);

        return DB::table(DB::raw("({$subQuery->toSql()}) as sub"))
            ->mergeBindings($subQuery)
            ->select('member_id', DB::raw('count(*) as count'))
            ->groupBy('member_id')
            ->get();
    }

    private function sendIpLoginWarnings($ipLogins, Member $member, string $ip): void
    {
        foreach ($ipLogins as $row) {
            if ($row->member_id == $member->id && $row->count < 2) {
                $message = '【IP登入多帳號】 ' . PHP_EOL .
                    'IP: ' . $ip . PHP_EOL .
                    '用戶名：' . $member->username . PHP_EOL .
                    '登入時間：' . date('Y-m-d H:i:s', strtotime('+8hour')) . PHP_EOL;

                BotNotify::send($message);
                break;
            }
        }
    }

    /**
     * 清除相關緩存
     */
    public function clearCache(Member $member): void
    {
        Redis::del(CacheKeys::moblieEmailMatchFailCount($member->id));
    }
}
