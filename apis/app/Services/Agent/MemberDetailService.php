<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\WalletEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCustomizedSetting;
use App\Models\Country;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\MemberCode;
use App\Models\MemberWallet;
use App\Models\RealnameValidationRequest;
use App\Services\Agent\Concerns\ChecksMemberScope;

/**
 * 會員詳情抽屜——會員卡片＋基本資料（4274，接口自 API_Agent member/view 移植並補全）。
 *
 * 與舊版的刻意差異：
 *  - 補「註冊日期 created_at」（舊版缺漏，工單待確認 3 的結論）
 *  - 聯絡電話依 reveal_phone_number 於服務端遮蔽（舊版原文回傳；C-T4 要求不可洩漏真實號碼）
 */
class MemberDetailService
{
    use ChecksMemberScope;

    public function __construct(
        private readonly AgentMenuPermissionService $menuPermission,
    ) {}

    /**
     * member_id 與 username 擇一（username 供聊天表頭等只有帳號字串的入口，
     * 回應帶 member_id 供前端後續區塊查詢）。
     *
     * @return array<string, mixed>
     */
    public function view(Agent $agent, ?int $memberId, ?string $username = null): array
    {
        $member = $memberId
            ? $this->findMemberInScope($agent, $memberId)
            : $this->findMemberInScopeByUsername($agent, (string) $username);

        $parentAgent = Agent::query()->find($member->parent);

        // 獎勵錢包（禮碼）；無錢包視為 0
        $rollingChip = MemberWallet::query()
            ->where('member_id', $member->id)
            ->where('wallet_type_id', WalletEnum::CODE_ROLLING_CHIP->value)
            ->value('balance');

        $referralCode = MemberCode::query()
            ->where('member_id', $member->id)
            ->value('agent_code');

        // 國家兩用：country_id → 國碼（電話國碼）、register_country_id → 註冊國家名
        $countries = Country::query()
            ->whereIn('id', array_filter([$member->country_id, $member->register_country_id]))
            ->get()
            ->keyBy('id');

        // 實名驗證審核（KYC）最新一筆申請：狀態供「身份認證狀態」列顯示，
        // 待審核時前端以 id 跳「實名驗證審核」頁直接開審核彈窗；從未申請則兩者皆 null
        $realnameRequest = RealnameValidationRequest::query()
            ->where('member_id', $member->id)
            ->orderByDesc('id')
            ->first(['id', 'status']);

        // 稽核流水差額：未通過（status 0）流水限制的 SUM(限制) − SUM(達成)，下限 0；
        // 與帳號管理「稽核流水」彈窗同一張表，不套前台可見性設定（後台要看全部）
        $flow = FlowLimit::query()
            ->where('member_id', $member->id)
            ->where('status', 0)
            ->selectRaw('IFNULL(SUM(flow_limit), 0) AS flow_limit, IFNULL(SUM(reach), 0) AS reach')
            ->first();
        $flowLimitTotal = Common::numAdd('0', (string) $flow->flow_limit, 4);
        $flowLimitReach = Common::numAdd('0', (string) $flow->reach, 4);
        $flowLimitGap = Common::numSub($flowLimitTotal, $flowLimitReach, 4);
        if (Common::numCompare($flowLimitGap, '0', 4) < 0) {
            $flowLimitGap = '0.0000';
        }

        return [
            'member_id' => (int) $member->id,
            'username' => $member->username,
            'nickname' => (string) $member->nickname,
            'referral_code' => (string) $referralCode,
            'agent_username' => (string) ($parentAgent->username ?? ''),
            // 錢包卡：主錢包＋獎勵錢包（禮碼）
            'balance' => Common::numAdd('0', (string) $member->balance, 4),
            'rolling_chip' => Common::numAdd('0', (string) ($rollingChip ?? 0), 4),
            // 稽核流水：總限制／已達成／差額（基本資料列只顯示差額）
            'flow_limit_total' => $flowLimitTotal,
            'flow_limit_reach' => $flowLimitReach,
            'flow_limit_gap' => $flowLimitGap,
            // —— 基本資料 14 欄 ——
            'realname' => $this->realname($member),
            'email' => $this->maskedEmail($agent, $member),
            'birthday' => (string) $member->birthday,
            'mobile' => $this->maskedMobile($agent, $member),
            'country_calling_code' => (string) ($countries[$member->country_id]->country_code ?? ''),
            'register_country' => (string) ($countries[$member->register_country_id]->name ?? ''),
            'created_at' => (string) $member->created_at,
            'register_ip' => $this->registerIp($member),
            // 最後登錄 IP（4438：抽屜「最後登錄IP」列）；members.last_login_ip 以 ip2long 整數儲存
            'last_login_ip' => $this->lastLoginIp($member),
            'register_domain' => (string) $member->register_domain,
            // 上級代理 id（4438：抽屜「網域」按鈕開上級代理的網域清單）
            'agent_id' => (int) $member->parent,
            // 會員類型與分桶可見開關（4438：抽屜「帳號類型」彈窗；文字由前端依型別對應，規則同帳號管理列表：
            // type 0 且上級代理開「分桶開關」6100 時顯示為份桶會員）
            'type' => (int) $member->type,
            'shareholder_visible' => $this->shareholderVisible($parentAgent),
            'device_type' => (string) $member->device_type,
            'status' => (int) $member->status,
            'is_validate' => (int) $member->is_validate,
            'mobile_validate' => (int) $member->mobile_validate,
            'email_validate' => (int) $member->email_validate,
            'realname_validate' => (int) $member->realname_validate,
            'realname_request_id' => $realnameRequest ? (int) $realnameRequest->id : null,
            'realname_request_status' => $realnameRequest ? (int) $realnameRequest->status : null,
            'last_login' => (string) $member->last_login,
        ];
    }

    /**
     * 聯絡電話：無「顯示電話」權限者服務端直接回「-」（C-T4：不可洩漏真實號碼，
     * 不能只靠前端遮）。沿用 agent_menus 既有 reveal_phone_number 功能型節點；
     * 權限判定為個人∪角色（4080），agent_menus 未建該碼的白標依 fail-open 放行（與現況一致）。
     */
    private function maskedMobile(Agent $agent, Member $member): string
    {
        if (blank($member->mobile)) {
            return '';
        }

        if (!$this->menuPermission->hasAny($agent, ['reveal_phone_number'])) {
            return '-';
        }

        return (string) $member->mobile;
    }

    /**
     * 電子郵件（4495）：獨立的 reveal_email 功能型節點（新節點只補給 admin，其餘帳號預設關閉），
     * 無權限者服務端回「-」，不洩漏真實信箱。
     */
    private function maskedEmail(Agent $agent, Member $member): string
    {
        if (blank($member->email)) {
            return '';
        }

        if (!$this->menuPermission->hasAny($agent, ['reveal_email'])) {
            return '-';
        }

        return (string) $member->email;
    }

    /** 真實姓名：正牌欄位 first_name，空才 fallback last_name（舊資料），不串接避免黏字 */
    private function realname(Member $member): string
    {
        $firstName = trim((string) $member->first_name);

        return $firstName !== '' ? $firstName : trim((string) $member->last_name);
    }

    /** 註冊 IP：優先取完整字串欄位（IPv6 相容），否則由 bigint 轉回 IPv4 */
    /** 最後登錄 IP：整數轉回點分字串；沒登入過（0／null）回空字串 */
    private function lastLoginIp(Member $member): string
    {
        $ip = (int) $member->last_login_ip;

        return $ip > 0 ? (string) long2ip($ip) : '';
    }

    /** 上級代理的「分桶開關」（6100）；沒設回 0。同帳號管理列表：只看自訂值、不看預設定義 */
    private function shareholderVisible(?Agent $parentAgent): int
    {
        if ($parentAgent === null) {
            return 0;
        }

        $value = AgentCustomizedSetting::query()
            ->where('agent_group_id', $parentAgent->customized_setting_group_id)
            ->where('setting_key', AgentCustomizedSettingKeyEnum::POINT_BARREL_SETTING)
            ->value('value');

        return (int) $value;
    }

    private function registerIp(Member $member): string
    {
        if (filled($member->register_ip_detail)) {
            return (string) $member->register_ip_detail;
        }

        if (filled($member->register_ip) && (int) $member->register_ip > 0) {
            return (string) long2ip((int) $member->register_ip);
        }

        return '';
    }
}
