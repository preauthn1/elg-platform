<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\CacheTtl;
use App\Enums\MemberFieldKeyEnum;
use App\Enums\MemberFieldPageTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentMemberFieldSetting;
use App\Models\Member;
use App\Services\Agent\MemberFieldSettingManageService;
use Illuminate\Support\Facades\Cache;

/**
 * 會員欄位設定（agent_groups type=6）讀取與規則判定。
 *
 * 3969：把 3970 後台設定的註冊/登入欄位規則接到會員端。
 * 全部為「新增規則」，不異動現有 agent-setting 轉跳流程（MemberController 既有邏輯保持原樣）。
 *
 * 職責僅限「讀取/呈現規則、算 missing」；註冊限制計數查 DB 屬另一職責，見 MemberRegisterLimitService。
 */
class MemberFieldSettingService
{
    /** 支付密碼：不在 3970 欄位 enum，屬固定必補欄位（TG 補資料流程寫死必收） */
    public const FIELD_PAYMENT_PASSWORD = [REDACTED]';

    /**
     * TG 補資料不列入的欄位：建會員時已具備或僅屬註冊表單。
     * username=uniqid、password=[REDACTED]
     * confirm_password 與驗證碼僅為註冊表單驗證用途，非會員資料本體。
     */
    private const PROFILE_COMPLETION_EXCLUDED_FIELDS = [
        'username',
        'password',
        'confirm_password',
        'referral_code',
        'sms_verify_code',
        'email_verify_code',
    ];

    /**
     * 登入「識別方式」欄位:presentation 的 login 只回這些(用什麼辨識身分)。
     * password 等憑證欄位排除——它是登入必備但非識別方式,回出來易誤解。
     */
    private const LOGIN_IDENTIFIER_FIELDS = [
        'username',
        'mobile',
    ];

    /**
     * 玩家帳號已下架的舊選項 key（4313）：「手機號碼作為用戶名」「小寫用戶名」。
     * 讀取時一律剔除——前台仍依這兩個 key 鎖欄位／自動填手機號，殘留 true 會撞
     * Username rule（須含英文字母）讓該代理無法註冊。DB 殘留 key 不主動清（值皆 false、讀取端已免疫），
     * 後台重存該組別時由 agent-backend 只送 schema key 自然洗掉。
     */
    public const LEGACY_USERNAME_CONFIG_KEYS = [
        'mobile_as_username',
        'lowercase_username',
    ];

    public function __construct(
        protected AgentService $agentService,
    ) {}

    /**
     * 取代理綁定的 type=6 組別 id；未綁定回 null。
     */
    public function resolveGroupId(Agent $agent): ?int
    {
        return $agent->member_field_setting_group_id ?: null;
    }

    /**
     * 取某頁面（註冊/登入）啟用中的欄位設定，依 field_group（分步）再依 sort 排序。
     * field_group 為分步註冊的步驟（1=第一步、2=第二步…），供前端分步渲染。
     *
     * @return array<int, array{field_key:string, is_required:int, field_group:int, sort:int, extra_config:array|null}>
     */
    public function getEnabledFields(Agent $agent, MemberFieldPageTypeEnum $pageType): array
    {
        $groupId = $this->resolveGroupId($agent);
        if ($groupId === null) {
            return [];
        }

        return Cache::safeRemember(
            $this->cacheKey($groupId, $pageType->value),
            CacheTtl::ONE_MINUTE->value,
            fn () => AgentMemberFieldSetting::query()
                ->where('agent_group_id', $groupId)
                ->where('page_type', $pageType->value)
                ->where('status', 1)
                ->orderBy('field_group')
                ->orderBy('sort')
                ->get(['field_key', 'is_required', 'field_group', 'sort', 'extra_config'])
                ->map(fn (AgentMemberFieldSetting $row) => [
                    'field_key' => $row->field_key,
                    'is_required' => (int) $row->is_required,
                    'field_group' => (int) $row->field_group,
                    'sort' => (int) $row->sort,
                    'extra_config' => $row->field_key === MemberFieldKeyEnum::PASSWORD->value
                        ? MemberPasswordPolicy::fromExtraConfig($row->extra_config)->toExtraConfig()
                        : $this->stripLegacyConfigKeys($row->extra_config),
                ])
                ->all()
        );
    }

    /**
     * 會員設定新密碼的規則，依註冊欄位設定的 password；代理沒有該設定時回舊版規則（只限 6～12 碼）。
     */
    public function passwordPolicy(Agent $agent): MemberPasswordPolicy
    {
        foreach ($this->getEnabledFields($agent, MemberFieldPageTypeEnum::REGISTER) as $field) {
            if ($field['field_key'] === MemberFieldKeyEnum::PASSWORD->value) {
                return MemberPasswordPolicy::fromExtraConfig($field['extra_config']);
            }
        }

        return MemberPasswordPolicy::legacy();
    }

    /**
     * 剔除 extra_config 內已下架的舊選項 key（見 LEGACY_USERNAME_CONFIG_KEYS），驗證端與呈現端同時免疫。
     *
     * @param  array<string, mixed>|null  $extraConfig
     * @return array<string, mixed>|null
     */
    private function stripLegacyConfigKeys(?array $extraConfig): ?array
    {
        if ($extraConfig === null) {
            return null;
        }

        return array_diff_key($extraConfig, array_flip(self::LEGACY_USERNAME_CONFIG_KEYS));
    }

    /**
     * 實名驗證欄位規則：field_key => ['show' => bool, 'required' => bool]。
     * 查無設定回空陣列，呼叫端據此維持原本只收真實姓名與銀行卡的流程。
     *
     * @return array<string, array{show:bool, required:bool}>
     */
    public function verificationRules(Agent $agent): array
    {
        $rules = [];

        foreach ($this->getEnabledFields($agent, MemberFieldPageTypeEnum::VERIFICATION) as $field) {
            $rules[$field['field_key']] = [
                'show' => true,
                'required' => $field['is_required'] === 1,
            ];
        }

        return $rules;
    }

    /**
     * 前端渲染用：註冊 + 登入欄位規則（不含限制值——限制純後端）。
     *
     * login 僅回「登入識別方式」欄位（username/mobile），排除 password 等憑證欄位：
     * login 陣列語義是「用什麼辨識身分」,密碼是登入必備憑證、非識別方式,回出來易誤解;
     * 前後端判斷登入識別皆只看 username/mobile（LoginForm 提示、getMember 查詢限縮）。
     *
     * @return array{register:array, login:array<int,string>}
     */
    public function presentation(Agent $agent): array
    {
        $register = $this->getEnabledFields($agent, MemberFieldPageTypeEnum::REGISTER);
        $login = array_values(array_filter(
            array_map(
                fn (array $f) => $f['field_key'],
                $this->getEnabledFields($agent, MemberFieldPageTypeEnum::LOGIN)
            ),
            fn (string $key) => in_array($key, self::LOGIN_IDENTIFIER_FIELDS, true)
        ));

        return [
            'register' => $register,
            'login' => $login,
        ];
    }

    /**
     * 依 3970 註冊必填設定，算出該會員「還缺哪些必填欄位」（TG 補資料用）。
     *
     * 支付密碼不在 3970 欄位 enum，改依舊個性化 #6051「支付密碼開關」判定
     * （與 MemberController::checkProfile 同一把尺）——未開啟該功能的站不應被判缺。
     *
     * 注意：此為「新增規則排查」，與 MemberController 既有 agent-setting 轉跳並行、互不取代。
     *
     * @return array<int, string> 欄位 key 清單（含 payment_password）
     */
    public function getMissingRequiredFields(Member $member, Agent $agent): array
    {
        $missing = [];

        // 支付密碼：僅在該站開啟支付密碼功能（#6051）且會員未設定時才算缺
        $hasPaymentPassword = [REDACTED]
            $agent,
            AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_BUTTON_VISIBLE
        );
        if ($hasPaymentPassword == 1 && (string) $member->payment_password === '') {
            $missing[] = self::FIELD_PAYMENT_PASSWORD;
        }

        foreach ($this->getEnabledFields($agent, MemberFieldPageTypeEnum::REGISTER) as $field) {
            if ($field['is_required'] !== 1) {
                continue;
            }
            // TG 建會員時已具備（username=uniqid、password=[REDACTED]
            // 及僅屬註冊表單的驗證欄位（confirm_password、驗證碼），皆不列入補資料
            if (in_array($field['field_key'], self::PROFILE_COMPLETION_EXCLUDED_FIELDS, true)) {
                continue;
            }
            if ($this->isFieldFilled($member, $field['field_key'])) {
                continue;
            }
            $missing[] = $field['field_key'];
        }

        return array_values(array_unique($missing));
    }

    /**
     * 某註冊欄位是否為必填（依 3970 config；欄位未啟用視為非必填）。
     */
    public function isRegisterFieldRequired(Agent $agent, string $fieldKey): bool
    {
        foreach ($this->getEnabledFields($agent, MemberFieldPageTypeEnum::REGISTER) as $field) {
            if ($field['field_key'] === $fieldKey) {
                return $field['is_required'] === 1;
            }
        }

        return false;
    }

    /**
     * 註冊/建號是否必須推薦碼（邀請碼）。
     * 依 3970 config 的註冊欄位 referral_code 的 is_required 判定（欄位未啟用視為可空）。
     */
    public function isReferralCodeRequired(Agent $agent): bool
    {
        return $this->isRegisterFieldRequired($agent, MemberFieldKeyEnum::REFERRAL_CODE->value);
    }

    /**
     * 某註冊欄位是否啟用（status=1，依 3970 config）。
     * 供「僅在欄位啟用時才做的專屬檢查」使用（如 confirm_password 一致性）。
     */
    public function isRegisterFieldEnabled(Agent $agent, string $fieldKey): bool
    {
        foreach ($this->getEnabledFields($agent, MemberFieldPageTypeEnum::REGISTER) as $field) {
            if ($field['field_key'] === $fieldKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * 組別是否為「關閉模式」：玩家帳號關閉，帳號由系統以前綴自動產生，一個手機號碼只能有一個帳號。
     *
     * 必須同時成立：組別有啟用中的註冊欄位，而且裡面沒有玩家帳號。
     * 不能只看「玩家帳號沒啟用」：組別完全沒設定時啟用欄位也是空陣列，會把正常走 v1 的站一起當成關閉模式。
     *
     * 傳入的代理：v1、v2 註冊與登入傳網域代理，TG 建帳傳 bot 綁定的代理，綁定上限傳該檢查原本用的代理。
     */
    public function isUsernameAutoGenerated(Agent $agent): bool
    {
        $fields = $this->getEnabledFields($agent, MemberFieldPageTypeEnum::REGISTER);

        return $fields !== []
            && !in_array(MemberFieldKeyEnum::USERNAME->value, array_column($fields, 'field_key'), true);
    }

    /**
     * 關閉模式的帳號前綴（註冊頁玩家帳號那一列的 extra_config.username_prefix）；非關閉模式回 null。
     *
     * 關閉模式卻沒有前綴（後台存檔時必填，正常不會發生）回空字串，由呼叫端產生不帶前綴的帳號，不擋註冊。
     * 玩家帳號那一列 status=0，不在 {@see getEnabledFields()} 的結果裡，所以另外查，不走快取：
     * 只在建帳時讀一次，存檔改前綴後下一筆註冊就會用新前綴。
     */
    public function usernamePrefix(Agent $agent): ?string
    {
        if (!$this->isUsernameAutoGenerated($agent)) {
            return null;
        }

        $extraConfig = AgentMemberFieldSetting::query()
            ->where('agent_group_id', $this->resolveGroupId($agent))
            ->where('page_type', MemberFieldPageTypeEnum::REGISTER->value)
            ->where('field_key', MemberFieldKeyEnum::USERNAME->value)
            ->value('extra_config');

        return is_array($extraConfig)
            ? (string) ($extraConfig[MemberFieldSettingManageService::USERNAME_PREFIX_KEY] ?? '')
            : '';
    }

    /**
     * v2 註冊動態驗證：依代理的 3970 註冊欄位設定，逐欄檢查「必填」與「字數上下限」。
     * 不通過即拋 ApiException（帶欄位名）。僅檢查啟用中的欄位。
     *
     * @param  array<string, mixed>  $input  已通過基礎格式驗證的註冊輸入
     *
     * @throws ApiException
     */
    public function validateRegisterInput(Agent $agent, array $input): void
    {
        foreach ($this->getEnabledFields($agent, MemberFieldPageTypeEnum::REGISTER) as $field) {
            $key = $field['field_key'];

            // 以下欄位非同名 input,由專屬邏輯處理,此處跳過避免誤報必填:
            //  referral_code → invitation_code(controller 空碼段以 isRegisterFieldRequired 判)
            //  sms_verify_code / email_verify_code → token / email_token(isMobile/EmailValidate 驗證)
            if (in_array($key, [
                MemberFieldKeyEnum::REFERRAL_CODE->value,
                MemberFieldKeyEnum::SMS_VERIFY_CODE->value,
                MemberFieldKeyEnum::EMAIL_VERIFY_CODE->value,
            ], true)) {
                continue;
            }

            $value = $input[$key] ?? null;
            $filled = $value !== null && $value !== '';

            // 必填檢查
            if ($field['is_required'] === 1 && !$filled) {
                throw new ApiException(
                    __('error_message.2053', ['field' => $this->fieldLabel($key)]),
                    2053,
                );
            }

            if (!$filled) {
                continue;
            }

            // 密碼的字數與字元規則一併由 MemberPasswordPolicy 檢查
            if ($key === MemberFieldKeyEnum::PASSWORD->value) {
                MemberPasswordPolicy::fromExtraConfig($field['extra_config'])->assert((string) $value);

                continue;
            }

            // 字數上下限（僅對 extra_config 有 min/max 的欄位）
            $lengthValue = $key === MemberFieldKeyEnum::MOBILE->value
                ? $this->stripCountryCode((string) $value, $input)
                : (string) $value;
            $this->assertLength($key, $lengthValue, $field['extra_config']);
        }
    }

    /**
     * 檢查單一欄位字數是否落在 extra_config 的 min_length ~ max_length。
     *
     *
     * @throws ApiException
     */
    private function assertLength(string $key, string $value, ?array $extraConfig): void
    {
        if ($extraConfig === null) {
            return;
        }
        $min = $extraConfig['min_length'] ?? null;
        $max = $extraConfig['max_length'] ?? null;
        if ($min === null && $max === null) {
            return;
        }

        $len = mb_strlen($value);
        if (($min !== null && $len < (int) $min) || ($max !== null && $len > (int) $max)) {
            throw new ApiException(
                __('error_message.2054', [
                    'field' => $this->fieldLabel($key),
                    'min' => (int) ($min ?? 0),
                    'max' => (int) ($max ?? 0),
                ]),
                2054,
            );
        }
    }

    /**
     * 手機號碼字數只算號碼本體，不含國碼（target/tickets#4332）。
     *
     * 前端送出的 mobile 是「國碼＋號碼」（registerFields.js 的 phoneCode + phone），
     * 同一份 payload 另有 country_code 帶純國碼。後台字數設定與前端提示講的都是「號碼幾位」，
     * 若連國碼一起量，代理設 1~20、玩家填 20 位號碼會被擋（口徑對不上）。
     *
     * 保守處理：僅在 country_code 有值且確實是 mobile 的前綴時才扣除；
     * 沒送 country_code、或前綴對不上（前端未串國碼）時退回量整串，行為與先前一致。
     *
     * @param  array<string, mixed>  $input
     */
    private function stripCountryCode(string $mobile, array $input): string
    {
        $countryCode = trim((string) ($input['country_code'] ?? ''));
        if ($countryCode === '' || !str_starts_with($mobile, $countryCode)) {
            return $mobile;
        }

        return substr($mobile, strlen($countryCode));
    }

    /**
     * 欄位顯示名（錯誤訊息用）：對齊後台 / 前端情境 label。
     */
    private function fieldLabel(string $key): string
    {
        return match ($key) {
            MemberFieldKeyEnum::USERNAME->value => '玩家帳號',
            MemberFieldKeyEnum::PASSWORD->value => '密碼',
            MemberFieldKeyEnum::CONFIRM_PASSWORD->value => '確認密碼',
            MemberFieldKeyEnum::LAST_NAME->value => '真實姓名',
            MemberFieldKeyEnum::MOBILE->value => '手機號碼',
            MemberFieldKeyEnum::EMAIL->value => '電子郵件',
            MemberFieldKeyEnum::BIRTHDAY->value => '出生日期',
            MemberFieldKeyEnum::REFERRAL_CODE->value => '推薦碼',
            MemberFieldKeyEnum::SMS_VERIFY_CODE->value => '手機驗證碼',
            MemberFieldKeyEnum::EMAIL_VERIFY_CODE->value => '郵件驗證碼',
            self::FIELD_PAYMENT_PASSWORD => '支付密碼',
            default => $key,
        };
    }

    /**
     * 清掉某組別的欄位設定快取（註冊、登入、實名驗證三頁），存檔後呼叫，讓註冊馬上套用新設定。
     */
    public function forgetCache(int $groupId): void
    {
        foreach (MemberFieldPageTypeEnum::cases() as $pageType) {
            Cache::forget($this->cacheKey($groupId, $pageType->value));
        }
    }

    /**
     * 判斷會員某欄位是否已填妥（供 missing 判定）。
     */
    private function isFieldFilled(Member $member, string $fieldKey): bool
    {
        return match ($fieldKey) {
            MemberFieldKeyEnum::LAST_NAME->value => (string) $member->first_name !== '', // 實名存 first_name
            MemberFieldKeyEnum::MOBILE->value => (int) $member->mobile_validate === 1,
            MemberFieldKeyEnum::EMAIL->value => (int) $member->email_validate === 1,
            MemberFieldKeyEnum::BIRTHDAY->value => (string) $member->birthday !== '' && (string) $member->birthday !== '0000-00-00',
            // 其餘欄位（username/password 等）建會員時即已具備，不列入補資料
            default => true,
        };
    }

    private function cacheKey(int $groupId, int $pageType): string
    {
        return "member_field_setting:{$groupId}:{$pageType}";
    }
}
