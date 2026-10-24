<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Event\EventActionIdEnum;
use App\Enums\MemberBindingLogTypeEnum;
use App\Enums\MemberTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentInviteCode;
use App\Models\Country;
use App\Models\EventCode;
use App\Models\Member;
use App\Models\MemberAgent;
use App\Models\MemberBindingLog;
use App\Models\MemberCode;
use App\Models\PrizeContent;
use App\Models\ValidateToken;
use App\Services\Frontend\EventInvite\EventInviteService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class RegisterService
{
    /** 自動產生帳號撞到既有帳號時的最多嘗試次數 */
    private const int USERNAME_GENERATE_ATTEMPTS = 5;

    public function __construct(
        protected EventService $eventService,
        protected MemberService $memberService,
        protected MemberCodeService $memberCodeService,
        protected EventInviteService $eventInviteService,
    ) {}

    /**
     * 同步會員資料到 CS
     */
    public function triggerCsSyncData($agent, $username)
    {
        try {
            $data = [
                'API_USER' => $agent->CS_API_USER,
                'API_KEY' => $agent->CS_API_KEY,
                'THIRD_PARTY_ID' => $username,
            ];

            Log::debug('triggerCsSyncData', $data);

            Common::postToSocket('sync/members', $data);

            return;
        } catch (Throwable $e) {
            Log::error('同步會員資料到 CS 失敗', ['error' => $e->getMessage()]);

            return;
        }
    }

    /**
     * 註冊完成後觸發活動相關 stored procedures
     */
    public function runRegisterEventStoredProcedures(Member $member, Agent $agent): void
    {
        DB::statement('CALL SP_event_gift_point(?, ?, ?)', [1, $member->id, $agent->id]);
        DB::statement('CALL SP_event_register(?, ?, ?)', [$member->id, $agent->id, 'regi']);
    }

    public function getNickname(): string
    {
        $array = [
            // '我來比有錢', '博彩首富', '富豪之星', '比你更有錢', '我來贏的'
            // 以下方五組為命名的組數已達98XXX, 所以會造成創建玩家緩慢
            // 'BOSS', 'BIG BOSS', 'MEMBER', 'LUCKY STAR', 'WINNER'
            'echo ', 'Blaze', 'Nova', 'Orion', 'Rogue', 'Frost', 'Onyx', 'Zenith', 'Storm', 'Luna',
            'Rex', 'Vortex', 'Phantom', 'Solstice', 'Zephyr', 'Neon', 'Seraph', 'Obsidian',
            'Inferno', 'Nyx', 'Havoc', 'Valkyrie', 'Dragoon', 'Celestia', 'Maelstrom', 'Thunder',
            'Basilisk', 'Scorpio', 'Aegis', 'Eclipse', 'Tempest', 'Nemesis', 'Valor', 'Mirage',
            'Aurora', 'Hydra', 'Talon', 'Cipher', 'Astral', 'Hades', 'Banshee', 'Phoenix', 'Cascade',
            'Eon', 'Chimera', 'Viper', 'Sable', 'Paragon', 'Ghost',
        ];
        $nickname = $array[rand(0, (count($array) - 1))] . str_pad((string) rand(0, 99999), 5, '0', STR_PAD_LEFT);

        // 驗證不重覆
        $isExists = Member::where('nickname', $nickname)->exists();
        if ($isExists) {
            return $this->getNickname();
        }

        return $nickname;
    }

    public function getCountryIdByCode($country_code): int
    {
        $country = Country::where('country_code', $country_code)->first();

        return (is_object($country))
            ? $country->id
            : 0;
    }

    /**
     * 透過邀請碼 invitation_code 判斷所屬代理商跟上線會員
     */
    public function detectAgentAndSponsor(string $inviteCode, $inviteCodeType, Agent $domainAgent): array
    {
        // member type 邏輯參考這個 issue
        // http://example.invalid:1688/target/tickets/-/issues/3313
        if ($inviteCodeType) {
            ['agent' => $agent, 'sponsor' => $sponsor, 'eventSummary' => $eventSummary, 'memberType' => $memberType] =
                $this->resolveAgentByAccountInviteCode(inviteCode: $inviteCode, domainAgent: $domainAgent);
        } elseif ($this->isEventMemberInviteCodePattern(inviteCode: $inviteCode)) {
            ['agent' => $agent, 'sponsor' => $sponsor, 'eventSummary' => $eventSummary, 'memberType' => $memberType] =
                $this->resolveAgentByEventCode(inviteCode: $inviteCode);
        } else {
            ['agent' => $agent, 'sponsor' => $sponsor, 'eventSummary' => $eventSummary, 'memberType' => $memberType] =
                $this->resolveAgentByCodeOrMemberInvite(inviteCode: $inviteCode);
        }

        if (!$agent) {
            // 查無此營運商(推薦碼錯誤)
            throw new ApiException(__('error_message.1050'));
        }

        if (!str_contains($agent->hierarchy_path, $domainAgent->hierarchy_path)) {
            // 代理邀請碼不在代理鏈內
            throw new ApiException(__('error_message.1050'));
        }

        if ($agent->frontend_register != 1) {
            // 營運商不開放註冊
            throw new ApiException(__('error_message.1051'));
        }

        return [$agent, $sponsor, $eventSummary, $memberType];
    }

    /**
     * 使用帳號作為邀請碼（issue#1802）
     *
     * @return array{agent: ?Agent, sponsor: ?Member, eventSummary: null, memberType: int}
     */
    private function resolveAgentByAccountInviteCode(string $inviteCode, Agent $domainAgent): array
    {
        $memberType = MemberTypeEnum::NORMAL->value;
        $sponsor = null;

        $agent = Agent::query()
            ->where('username', $inviteCode)
            ->where('hierarchy_path', 'like', $domainAgent->hierarchy_path . '%')
            ->where('status', 1)
            ->first();

        if ($agent) {
            return [
                'agent' => $agent,
                'sponsor' => null,
                'eventSummary' => null,
                'memberType' => $memberType,
            ];
        }

        $sponsor = Member::query()
            ->where('username', $inviteCode)
            ->whereHas('agent', function ($query) use ($domainAgent) {
                $query->where('hierarchy_path', 'like', $domainAgent->hierarchy_path . '%');
                $query->where('status', 1);
            })
            ->where('status', 1)
            ->first();

        if (!$sponsor) {
            return [
                'agent' => null,
                'sponsor' => null,
                'eventSummary' => null,
                'memberType' => $memberType,
            ];
        }

        $memberType = $sponsor->type;
        $agent = $sponsor->agent ?? null;

        if ($agent && $agent->agent_mode != 1) {
            $agent = null;
        }

        return [
            'agent' => $agent,
            'sponsor' => $sponsor,
            'eventSummary' => null,
            'memberType' => $memberType,
        ];
    }

    private function isEventMemberInviteCodePattern(string $inviteCode): bool
    {
        return strlen($inviteCode) === 10
            && strtoupper(substr($inviteCode, 0, 2)) === config('custom.event_code_prefix');
    }

    /**
     * 會員邀請活動 EventCode.code
     *
     * @return array{agent: ?Agent, sponsor: ?Member, eventSummary: mixed, memberType: int}
     */
    private function resolveAgentByEventCode(string $inviteCode): array
    {
        $eventCode = EventCode::query()
            ->where('code', $inviteCode)
            ->whereHas('member') // 確認上線存在
            ->whereHas('event')  // 確認活動有效
            ->first();

        if (!$eventCode) {
            throw new ApiException(__('error_message.1945'));
        }

        $sponsor = $eventCode->member;
        $memberType = $sponsor->type;
        $agent = $sponsor->agent ?? null;
        $eventSummary = $this->eventService->getEventSummary($eventCode->event->id, $agent);

        // 關閉邀請時是否綁定全民代理時, 會員上級設為null, 不成為邀請者的下線
        if (($eventSummary->event_bind_unlimited ?? 0) == 0) {
            $sponsor = null;
        }

        return [
            'agent' => $agent,
            'sponsor' => $sponsor,
            'eventSummary' => $eventSummary,
            'memberType' => $memberType,
        ];
    }

    /**
     * 代理邀請碼或會員邀請碼
     *
     * @return array{agent: ?Agent, sponsor: ?Member, eventSummary: mixed|null, memberType: int}
     */
    private function resolveAgentByCodeOrMemberInvite(string $inviteCode): array
    {
        $eventSummary = null;
        $memberType = MemberTypeEnum::NORMAL->value;

        $agent = Agent::query()
            ->where('code', $inviteCode)
            ->where('status', 1)
            ->first();

        if (!$agent) {
            $agentInviteCode = AgentInviteCode::query()
                ->where('code', $inviteCode)
                ->where('type', 1) // 1 佔成
                ->first();
            if ($agentInviteCode) {
                $agent = $agentInviteCode->agent;
                $memberType = MemberTypeEnum::SHARE->value;
            }
        }

        if ($agent) {
            return [
                'agent' => $agent,
                'sponsor' => null,
                'eventSummary' => null,
                'memberType' => $memberType,
            ];
        }

        $memberCode = MemberCode::query()
            ->where('agent_code', $inviteCode)
            ->first();

        $sponsor = $memberCode?->member;

        if (!$sponsor) {
            throw new ApiException(__('error_message.1050'));
        }

        $memberType = $sponsor->type;
        $agent = $sponsor->agent ?? null;

        if ($agent->agent_mode != 1) {
            $agent = null;
        }

        // 從全民代理反查邀請活動
        $eventId = $this->eventService->getEventIdByMember($memberCode->member, EventActionIdEnum::INVITE_PLAYER_OFFERS->value);
        if ($eventId) {
            $eventSummary = $this->eventService->getEventSummary($eventId, $agent);
        }

        return [
            'agent' => $agent,
            'sponsor' => $sponsor,
            'eventSummary' => $eventSummary,
            'memberType' => $memberType,
        ];
    }

    /**
     * 註冊時如果帶入 mobile & token 則檢查手機驗證碼（3969 v2 專用）。
     *
     * 回傳驗證通過的 token（供呼叫端於同一 transaction 內核銷）；未帶驗證碼回 null。
     * 必須核銷：否則同一組驗證碼可在有效期內反覆註冊（換 username 即可），繞過綁定上限。
     */
    public function findValidMobileToken(array $input): ?ValidateToken
    {
        // 註冊包含手機號, 需檢查驗證碼(3969 v2 專用;錯誤碼 2058 明確指手機驗證碼)
        if (!empty($input['mobile']) && !empty($input['token'])) {
            $token = [REDACTED]
                ->where('token', $input['token'])
                ->where('status', 0)
                ->where('expire_date', '>=', date('Y-m-d H:i:s'))
                ->first();

            if (!$token || $token->info != $input['mobile']) {
                throw new ApiException(__('error_message.2058'), 2058);
            }

            return $token;
        }

        return null;
    }

    /**
     * 註冊時如果帶入 email & token 則檢查信箱驗證碼（3969：填寫與驗證分離,有 token 才驗證;錯誤碼 2059 明確指郵件驗證碼）。
     *
     * 回傳驗證通過的 token（供呼叫端核銷）；未帶驗證碼回 null。核銷理由同 findValidMobileToken。
     */
    public function findValidEmailToken(array $input): ?ValidateToken
    {
        if (!empty($input['email']) && !empty($input['token'])) {
            $token = [REDACTED]
                ->where('token', $input['token'])
                ->where('status', 0)
                ->where('expire_date', '>=', date('Y-m-d H:i:s'))
                ->first();

            if (!$token || $token->info != $input['email']) {
                throw new ApiException(__('error_message.2059'), 2059);
            }

            return $token;
        }

        return null;
    }

    /**
     * 檢查 username & nickname 是否包含禁用字
     */
    public function validateBannedWords(array $input, Agent $agent): void
    {
        // 取得代理配置的禁用字
        $invalidNames = $this->memberService->nicknameProhibitedWords($agent);

        // 檢查暱稱是否包含不允許的字彙
        foreach ($invalidNames as $word) {
            // 關閉模式的帳號由系統產生，v2 request 已把輸入的帳號排除，這裡沒有帳號可檢查
            if (str_contains(strtolower((string) ($input['username'] ?? '')), $word)) {
                throw new ApiException(__('error_message.1093', ['value' => $word]));
            }

            if (isset($input['nickname'])) {
                if (str_contains(strtolower($input['nickname']), $word)) {
                    throw new ApiException(__('error_message.1093', ['value' => $word]));
                }
            }
        }
    }

    /**
     * 建立會員代理關係
     */
    public function createMemberAgent(Member $member, Agent $agent, ?Member $sponsor = null): MemberAgent
    {
        // 生成新會員的邀請碼（集中由 MemberCodeService 原子化處理）
        $this->memberCodeService->get($member);

        $memberAgent = null;

        if ($sponsor) {
            // 查詢上級會員代理資訊
            $memberAgent = MemberAgent::query()->where('member_id', $sponsor->id)->first();
        }

        $topperMemberId = $memberAgent ? $memberAgent->member_id : 0;
        $agentLevel = $memberAgent ? $memberAgent->agent_level + 1 : 1;
        $agentRelation = $memberAgent ? $memberAgent->agent_relation : '0,';
        $agentRelation = $agentRelation . $member->id . ',';

        // 綁定上級會員
        $memberAgent = MemberAgent::create([
            'member_id' => $member->id,
            'parent_id' => $agent->id,
            'topper_member_id' => $topperMemberId,
            'agent_mode' => 1,
            'agent_level' => $agentLevel,
            'agent_relation' => $agentRelation,
        ]);

        return $memberAgent;
    }

    /**
     * 建立會員核心（v1/v2 共用）：Member::create + 事件預派 + 無限級代理 + 手機綁定紀錄。
     *
     * 驗證與限制檢查由呼叫端負責（v2 走 config 動態驗證 + MemberRegisterLimitService）；
     * 呼叫端須自行包 DB transaction，並於 commit 後呼叫 triggerCsSyncData / runRegisterEventStoredProcedures。
     *
     * @param  array<string, mixed>  $profile  已組妥的 members 建立欄位
     */
    public function createMember(
        array $profile,
        Agent $agent,
        ?Member $sponsor,
        mixed $eventSummary,
        bool $mobileValidate,
    ): Member {
        $member = Member::create($profile);

        $this->createEventInviteJoin($member, $sponsor);

        // 邀請玩家獎勵預派紀錄
        if ($eventSummary) {
            // TODO: 不應耦合在此，放在 SP_event_register 較合適（沿用 v1 現況）
            if ($prizeContent = PrizeContent::find($eventSummary->prize_content_id)) {
                $prizeContent->increment('distribute');
            }
        }

        // 無限級代理
        if ($agent->agent_mode == 1) {
            $this->createMemberAgent($member, $agent, $sponsor);
        }

        // 手機綁定紀錄
        if ($mobileValidate && !empty($profile['mobile'])) {
            MemberBindingLog::create([
                'type' => MemberBindingLogTypeEnum::BINDING_PHONE->value,
                'member_id' => $member->id,
                'value' => $profile['mobile'],
                'ip' => Common::getIp(),
                'validate_type' => 1,
                'status' => 1,
            ]);
        }

        return $member;
    }

    /**
     * 邀請好友活動：記錄邀請關係並檢查IP/手機/真實姓名重複（v1/v2 共用，$sponsor 為 null 時不建立）
     */
    public function createEventInviteJoin(Member $member, ?Member $sponsor): void
    {
        if ($sponsor) {
            $this->eventInviteService->registerInvite($member, $sponsor);
        }
    }

    /**
     * 關閉模式自動產生的帳號：「前綴-uniqid」（13 碼 hex，前綴最多 16 字，總長最多 30）；前綴為空時只有 uniqid。
     *
     * 跟代理或會員的帳號重複時重產，最多 5 次，還是重複就拋錯。
     * 產生的帳號不經過帳號格式規則（{@see \App\Rules\Username}）與字數設定檢查，那兩道只檢查使用者輸入的帳號。
     *
     * @throws ApiException
     */
    public function generateUsername(string $prefix): string
    {
        for ($attempt = 0; $attempt < self::USERNAME_GENERATE_ATTEMPTS; $attempt++) {
            $username = $this->usernameCandidate($prefix);

            if (!Agent::where('username', $username)->exists()
                && !Member::where('username', $username)->exists()
            ) {
                return $username;
            }
        }

        throw new ApiException(__('error_message.9999'), 9999);
    }

    protected function usernameCandidate(string $prefix): string
    {
        return $prefix === '' ? uniqid() : $prefix . '-' . uniqid();
    }

    /**
     * v2 完整註冊流程（3969）：transaction + 手機驗證 + username 查重 + 組 profile
     * + createMember + commit 後 CS 同步 / 活動 SP。呼叫端（controller）只負責 config 動態驗證與限制檢查。
     *
     * $usernamePrefix 不是 null 代表關閉模式：忽略輸入的帳號，改用 {@see generateUsername()} 產生。
     *
     * @param  array<string, mixed>  $input  已通過 config 動態驗證與註冊限制的輸入
     */
    public function registerMemberV2(
        array $input,
        Agent $agent,
        ?Member $sponsor,
        mixed $eventSummary,
        int $memberType,
        string $inviteCode,
        int $countryId,
        ?string $usernamePrefix = null,
    ): Member {
        try {
            DB::beginTransaction();

            // 手機/信箱驗證（3969 填寫與驗證分離：有帶對應驗證碼才驗證,否則只存值 validate=0）
            $mobileToken = [REDACTED]
                'mobile' => $input['mobile'] ?? '',
                'token' => $input['mobile_token'] ?? '',
            ]);
            $emailToken = [REDACTED]
                'email' => $input['email'] ?? '',
                'token' => $input['email_token'] ?? '',
            ]);
            $mobileValidate = $mobileToken !== null;
            $emailValidate = $emailToken !== null;

            if ($usernamePrefix !== null) {
                $username = $this->generateUsername($usernamePrefix);
            } else {
                $username = (string) ($input['username'] ?? '');
                if ($username === '') {
                    throw new ApiException(__('error_message.2053', ['field' => '玩家帳號']), 2053);
                }
                // username 不可與代理或既有會員重複(前置友善檢查,避免撞 DB 唯一鍵拋 500)
                if (Agent::where('username', $username)->exists()
                    || Member::where('username', $username)->exists()
                ) {
                    throw new ApiException(__('error_message.1002'), 1002);
                }
            }

            $profile = [
                'parent' => $agent->id,
                'username' => $username,
                'password' => Hash::make($input['password']),
                // 風控比對用(會員追蹤查同密碼帳號),不參與登入驗證
                'password_md5' => md5($input['password']),
                'email' => $input['email'] ?? null,
                'nickname' => $this->getNickname(),
                'invitation_code' => $inviteCode,
                'device_id' => $input['device_id'] ?? null,
                'device_type' => $input['platform'] ?? null,
                // 真實姓名:config field_key 為 last_name,但全站真實姓名一律存 first_name
                // (對齊既有 set-realname MemberController:270 與 isFieldFilled 判讀),避免補資料誤判缺實名
                'first_name' => $input['last_name'] ?? null,
                // birthday 為 date 欄位,空字串會觸發 MySQL Incorrect date value;空值一律轉 null
                'birthday' => empty($input['birthday']) ? null : $input['birthday'],
                'mobile' => $input['mobile'] ?? null,
                'country_id' => $countryId,
                'status' => 1,
                'mobile_validate' => $mobileValidate,
                'email_validate' => $emailValidate,
                'admin' => 0,
                'register_ip' => ip2long(Common::getIp()),
                'register_domain' => $input['domain'] ?? null,
                'register_country_id' => $countryId,
                'type' => $memberType,
                'previous_day_type' => $memberType,
            ];

            $member = $this->createMember($profile, $agent, $sponsor, $eventSummary, $mobileValidate);

            // 核銷驗證碼（與舊 register 一致）：不核銷則同一組碼可反覆註冊,繞過綁定上限
            foreach ([$mobileToken, $emailToken] as $usedToken) {
                $usedToken?->update([
                    'member_id' => $member->id,
                    'status' => 1,
                ]);
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }

        // commit 後同步 CS + 活動 SP（與 __invoke 一致）
        $this->triggerCsSyncData($agent, $member->username);
        $this->runRegisterEventStoredProcedures($member, $agent);

        return $member;
    }
}
