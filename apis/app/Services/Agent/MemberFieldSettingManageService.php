<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\CurrencyEnum;
use App\Enums\MemberFieldKeyEnum;
use App\Enums\MemberFieldPageTypeEnum;
use App\Enums\MemberRegisterLimitFieldEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\AgentGroupPermission;
use App\Models\AgentMemberFieldSetting;
use App\Models\AgentRegisterLimit;
use App\Models\AgentRegisterLimitValue;
use App\Models\Member;
use App\Services\MemberFieldSettingService;
use App\Services\MemberRegisterLimitService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 代理後台「會員欄位設置」的讀取與存檔（agent_groups type=6；target/tickets#4459 自 API_Agent
 * AgentMemberFieldSettingController 搬入）。
 *
 * 存檔與讀取在同一個服務，存完直接清 {@see MemberFieldSettingService}、{@see MemberRegisterLimitService}
 * 的快取，註冊馬上套用新設定。
 *
 * 必須與 API_Agent 版等價（舊後台 WEB_AgentBackend 沒用到這三支，舊接口留著不動）：
 * - 組別檢查：組別存在、類型是 6、平台端（主帳號 id=1）以外要有該組別的組別管理權
 * - 欄位與註冊限制都是「送來的列 upsert」，沒送的列不動；註冊限制的自訂逐值整組重建
 * - 各頁的驗證規則（登入識別方式至少開一個、層級連號、字數設定、驗證碼相依、上限次數）
 *
 * 刻意的差異：
 * - 設定只看組別、不比對幣別：讀取與存檔都以組別找列，跟註冊讀設定的
 *   {@see MemberFieldSettingService}、{@see MemberRegisterLimitService} 一致。
 *   currency_id 欄位還在，只在新建列時寫 MYR（{@see NEW_ROW_CURRENCY_ID}），既有列的值不改。
 * - 組別管理權只認啟用中的（status=1），跟 {@see AgentGroupPermissionService} 一致。
 * - 存檔先鎖組別列當互斥點，同一組別的存檔排隊執行。
 * - 玩家帳號關閉（關閉模式）時的存檔規則，見 {@see assertUsernameOffRules()}、{@see assertLoginMobileOnly()}。
 */
class MemberFieldSettingManageService
{
    /** 各頁可存的欄位鍵 */
    private const array ALLOWED_FIELD_KEYS = [
        MemberFieldPageTypeEnum::REGISTER->value => [
            MemberFieldKeyEnum::USERNAME,
            MemberFieldKeyEnum::PASSWORD,
            MemberFieldKeyEnum::CONFIRM_PASSWORD,
            MemberFieldKeyEnum::MOBILE,
            MemberFieldKeyEnum::EMAIL,
            MemberFieldKeyEnum::LAST_NAME,
            MemberFieldKeyEnum::BIRTHDAY,
            MemberFieldKeyEnum::SMS_VERIFY_CODE,
            MemberFieldKeyEnum::EMAIL_VERIFY_CODE,
            MemberFieldKeyEnum::REFERRAL_CODE,
        ],
        MemberFieldPageTypeEnum::LOGIN->value => [
            MemberFieldKeyEnum::USERNAME,
            MemberFieldKeyEnum::MOBILE,
        ],
        MemberFieldPageTypeEnum::VERIFICATION->value => [
            MemberFieldKeyEnum::LAST_NAME,
            MemberFieldKeyEnum::BANK,
            MemberFieldKeyEnum::BIRTHDAY,
            MemberFieldKeyEnum::ID_NUMBER,
            MemberFieldKeyEnum::ID_PHOTO,
        ],
    ];

    /**
     * 最長字數的上限。
     *
     * 玩家帳號：members.username 為 varchar(32)，上限訂 30 留餘裕；設定密碼比照同一把尺。
     * 手機號碼：多支前台接口對 mobile 寫死 digits_between:1,20，量的是「國碼＋號碼」整串，
     * 最長國碼 3 位，號碼本體上限訂 16。
     */
    private const array FIELD_MAX_LENGTH_LIMITS = [
        'username' => 30,
        'password' => 30,
        'mobile' => 16,
    ];

    /** 註冊限制每個欄位的自訂逐值上限組數 */
    private const int MAX_CUSTOM_LIMIT_VALUES = 10;

    /** 新建列的 currency_id：讀取不看這欄，沿用舊後台與初始化命令寫的 MYR */
    private const int NEW_ROW_CURRENCY_ID = CurrencyEnum::MYR->value;

    /** 帳號前綴：英數字 2～16 字、至少一個英文字母（自動帳號＝前綴-13 碼 uniqid，總長不超過 30） */
    private const string USERNAME_PREFIX_PATTERN = '/^(?=.*[A-Za-z])[A-Za-z0-9]{2,16}$/';

    /** 帳號前綴存在註冊頁玩家帳號那一列的 extra_config 鍵 */
    public const string USERNAME_PREFIX_KEY = 'username_prefix';

    public function __construct(
        private readonly MemberFieldSettingService $fieldSettingService,
        private readonly MemberRegisterLimitService $registerLimitService,
        private readonly AgentPermissionScopeService $scope,
    ) {}

    /**
     * 組別的註冊、登入、實名驗證欄位與註冊限制
     *
     * @return array<string, mixed>
     */
    public function view(Agent $operator, int $groupId): array
    {
        $group = $this->guardGroup($operator, $groupId);

        $fields = AgentMemberFieldSetting::query()
            ->where('agent_group_id', $group->id)
            ->orderBy('page_type')
            ->orderBy('sort')
            ->get(['id', 'agent_group_id', 'currency_id', 'page_type', 'field_key', 'field_group', 'sort', 'is_required', 'status', 'extra_config', 'admin']);

        $byPage = fn (MemberFieldPageTypeEnum $page): Collection => $fields
            ->where('page_type', $page->value)
            ->values();

        $limits = AgentRegisterLimit::query()
            ->with('values:id,register_limit_id,value,max_limit')
            ->where('agent_group_id', $group->id)
            ->get()
            ->map(fn (AgentRegisterLimit $limit): array => [
                'field_key' => $limit->field_key,
                'platform_enabled' => (int) $limit->platform_enabled,
                'platform_max_limit' => $limit->platform_max_limit,
                'custom_enabled' => (int) $limit->custom_enabled,
                'custom_values' => $limit->values
                    ->map(fn ($v): array => ['value' => $v->value, 'max_limit' => (int) $v->max_limit])
                    ->values(),
            ]);

        return [
            'group_id' => $group->id,
            'group_name' => $group->name,
            'register' => $byPage(MemberFieldPageTypeEnum::REGISTER),
            'login' => $byPage(MemberFieldPageTypeEnum::LOGIN),
            'verification' => $byPage(MemberFieldPageTypeEnum::VERIFICATION),
            'limits' => $limits,
        ];
    }

    /**
     * 組別底下是否已有會員（會員的上級代理綁在這個組別）。玩家帳號從開改成關之前，前端據此決定要不要跳確認框。
     */
    public function hasMembers(Agent $operator, int $groupId): bool
    {
        $group = $this->guardGroup($operator, $groupId);

        return Member::query()
            ->whereIn('parent', Agent::query()->select('id')->where('member_field_setting_group_id', $group->id))
            ->exists();
    }

    /**
     * 存某一頁的欄位設定
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    public function saveFields(Agent $operator, int $groupId, MemberFieldPageTypeEnum $pageType, array $fields): void
    {
        $group = $this->guardGroup($operator, $groupId);

        $allowedKeys = array_map(fn (MemberFieldKeyEnum $key): string => $key->value, self::ALLOWED_FIELD_KEYS[$pageType->value]);
        foreach ($fields as $field) {
            if (!in_array($field['field_key'] ?? null, $allowedKeys, true)) {
                throw new ApiException(__('error_message.9999'));
            }
        }

        if ($pageType === MemberFieldPageTypeEnum::REGISTER) {
            $this->assertRegisterFields($fields);
        }

        DB::transaction(function () use ($operator, $group, $pageType, $fields): void {
            // 第一句即鎖定讀：鎖組別列當互斥點。要讀 DB 現值的檢查都放在鎖之後，才不會拿另一個存檔改掉前的狀態判斷
            AgentGroup::query()->whereKey($group->id)->lockForUpdate()->first(['id']);

            if ($pageType === MemberFieldPageTypeEnum::LOGIN) {
                $this->assertLoginFields($group, $fields);
            }

            $usernameOff = false;
            if ($pageType === MemberFieldPageTypeEnum::REGISTER) {
                $effective = $this->effectiveFields($group, MemberFieldPageTypeEnum::REGISTER, $fields);
                $usernameOff = (int) ($effective[MemberFieldKeyEnum::USERNAME->value]['status'] ?? 1) === 0;
                if ($usernameOff) {
                    $this->assertUsernameOffRules($group, $effective);
                }
            }

            foreach ($fields as $field) {
                [$status, $isRequired] = $this->normalizeStatus($pageType, $field);

                AgentMemberFieldSetting::query()
                    ->firstOrNew(
                        [
                            'agent_group_id' => $group->id,
                            'page_type' => $pageType->value,
                            'field_key' => $field['field_key'],
                        ],
                        ['currency_id' => self::NEW_ROW_CURRENCY_ID],
                    )
                    ->fill([
                        'field_group' => (int) ($field['field_group'] ?? 1),
                        'sort' => (int) ($field['sort'] ?? 0),
                        'is_required' => $isRequired,
                        'status' => $status,
                        'extra_config' => $this->normalizeExtraConfig($pageType, $field),
                        'admin' => $operator->id,
                    ])
                    ->save();
            }

            // 玩家帳號關閉：登入方式同步改成只用手機號碼（重新打開時不改回，維持手機號碼）
            if ($usernameOff) {
                $this->syncLoginToMobileOnly($operator, $group->id);
            }
        });

        $this->fieldSettingService->forgetCache($group->id);
    }

    /**
     * 存註冊限制（主檔 upsert，自訂逐值整組重建）
     *
     * @param  array<int, array<string, mixed>>  $limits
     */
    public function saveRegisterLimits(Agent $operator, int $groupId, array $limits): void
    {
        $group = $this->guardGroup($operator, $groupId);

        foreach ($limits as $limit) {
            if (MemberRegisterLimitFieldEnum::tryFrom((string) ($limit['field_key'] ?? '')) === null) {
                throw new ApiException(__('error_message.9999'));
            }
            if ((int) ($limit['platform_enabled'] ?? 0) === 1 && (int) ($limit['platform_max_limit'] ?? 0) < 1) {
                throw new ApiException(__('error_message.member_field_setting.limit_min_one'));
            }
        }

        DB::transaction(function () use ($operator, $group, $limits): void {
            // 第一句即鎖定讀：鎖組別列當互斥點
            AgentGroup::query()->whereKey($group->id)->lockForUpdate()->first(['id']);

            foreach ($limits as $limit) {
                $customEnabled = (int) ($limit['custom_enabled'] ?? 0);

                $record = AgentRegisterLimit::query()->firstOrNew(
                    [
                        'agent_group_id' => $group->id,
                        'field_key' => $limit['field_key'],
                    ],
                    ['currency_id' => self::NEW_ROW_CURRENCY_ID],
                );
                $record->fill([
                    'platform_enabled' => (int) ($limit['platform_enabled'] ?? 0),
                    'platform_max_limit' => $limit['platform_max_limit'] ?? null,
                    'custom_enabled' => $customEnabled,
                    'admin' => $operator->id,
                ])->save();

                AgentRegisterLimitValue::query()->where('register_limit_id', $record->id)->delete();

                $customValues = $limit['custom_values'] ?? [];
                if ($customEnabled !== 1 || !is_array($customValues)) {
                    continue;
                }

                $validValues = array_values(array_filter(
                    $customValues,
                    fn ($cv): bool => is_array($cv) && isset($cv['value'], $cv['max_limit']) && $cv['value'] !== '',
                ));
                if (count($validValues) > self::MAX_CUSTOM_LIMIT_VALUES) {
                    throw new ApiException(__('error_message.member_field_setting.custom_value_max', ['max' => self::MAX_CUSTOM_LIMIT_VALUES]));
                }
                $values = array_column($validValues, 'value');
                if (count($values) !== count(array_unique($values))) {
                    throw new ApiException(__('error_message.member_field_setting.custom_value_duplicate'));
                }

                foreach ($validValues as $cv) {
                    if ((int) $cv['max_limit'] < 1) {
                        throw new ApiException(__('error_message.member_field_setting.limit_min_one'));
                    }
                    AgentRegisterLimitValue::query()->create([
                        'register_limit_id' => $record->id,
                        'value' => $cv['value'],
                        'max_limit' => (int) $cv['max_limit'],
                    ]);
                }
            }
        });

        $this->registerLimitService->forgetCache($group->id);
    }

    /**
     * 組別存在、類型是 6；平台端（主帳號 id=1）以外要有該組別的組別管理權
     */
    private function guardGroup(Agent $operator, int $groupId): AgentGroup
    {
        $group = AgentGroup::query()->find($groupId);
        if ($group === null) {
            throw new ApiException(__('error_message.member_field_setting.group_not_found'));
        }

        if ((int) $group->agent_group_type !== AgentGroupTypeEnum::MEMBER_FIELD_SETTING->value) {
            throw new ApiException(__('error_message.9999'));
        }

        $isPlatform = $this->scope->hostId($operator) === 1;
        if (!$isPlatform && !AgentGroupPermission::query()
            ->where('agent_id', $operator->id)
            ->where('agent_group_id', $group->id)
            ->where('status', 1)
            ->exists()
        ) {
            throw new ApiException(__('error_message.9003'));
        }

        return $group;
    }

    /**
     * 登入頁：玩家帳號、手機號碼至少開一個；註冊的玩家帳號關閉時只能用手機號碼
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function assertLoginFields(AgentGroup $group, array $fields): void
    {
        $effective = $this->effectiveFields($group, MemberFieldPageTypeEnum::LOGIN, $fields);
        $enabled = array_filter(
            [MemberFieldKeyEnum::USERNAME->value, MemberFieldKeyEnum::MOBILE->value],
            fn (string $key): bool => in_array($key, array_column($fields, 'field_key'), true)
                && (int) ($effective[$key]['status'] ?? 0) === 1,
        );
        if ($enabled === []) {
            throw new ApiException(__('error_message.member_field_setting.login_identifier_required'));
        }

        $this->assertLoginMobileOnly($group, $effective);
    }

    /**
     * 註冊的玩家帳號關閉時，登入頁只接受「只用手機號碼」：玩家帳號關、手機號碼開
     *
     * @param  array<string, array<string, mixed>>  $effectiveLogin
     */
    private function assertLoginMobileOnly(AgentGroup $group, array $effectiveLogin): void
    {
        $registerUsername = AgentMemberFieldSetting::query()
            ->where('agent_group_id', $group->id)
            ->where('page_type', MemberFieldPageTypeEnum::REGISTER->value)
            ->where('field_key', MemberFieldKeyEnum::USERNAME->value)
            ->value('status');
        if ($registerUsername === null || (int) $registerUsername !== 0) {
            return;
        }

        if ((int) ($effectiveLogin[MemberFieldKeyEnum::USERNAME->value]['status'] ?? 0) !== 0
            || (int) ($effectiveLogin[MemberFieldKeyEnum::MOBILE->value]['status'] ?? 0) !== 1
        ) {
            throw new ApiException(__('error_message.member_field_setting.login_mobile_only'));
        }
    }

    /**
     * 註冊頁：層級連號、字數設定、驗證碼相依
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function assertRegisterFields(array $fields): void
    {
        $this->assertFieldGroupSequence($fields);
        foreach ($fields as $field) {
            $this->assertLengthSetting($field);
        }
        $this->assertVerifyCodeDependency($fields);
    }

    /**
     * 層級須從 1 起連號，跳號會讓中間那一關的註冊表單沒有任何欄位；停用欄位不計
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function assertFieldGroupSequence(array $fields): void
    {
        $fieldGroups = [];
        foreach ($fields as $field) {
            if ((int) ($field['status'] ?? 0) === 1) {
                $fieldGroups[] = (int) ($field['field_group'] ?? 0);
            }
        }
        $fieldGroups = array_values(array_unique($fieldGroups));
        sort($fieldGroups);
        foreach ($fieldGroups as $index => $fieldGroup) {
            if ($fieldGroup !== $index + 1) {
                throw new ApiException(__('error_message.member_field_setting.field_group_sequence'));
            }
        }
    }

    /**
     * 有字數設定的啟用欄位：最短、最長都要填，最短至少 1 且不大於最長，最長不超過上限
     *
     * @param  array<string, mixed>  $field
     */
    private function assertLengthSetting(array $field): void
    {
        $cfg = $field['extra_config'] ?? null;
        if ((int) ($field['status'] ?? 0) !== 1
            || !is_array($cfg)
            || !array_key_exists('min_length', $cfg)
            || !array_key_exists('max_length', $cfg)
        ) {
            return;
        }

        $min = $cfg['min_length'];
        $max = $cfg['max_length'];
        if ($min === null || $min === '' || $max === null || $max === '') {
            throw new ApiException(__('error_message.member_field_setting.length_required'));
        }
        if ((int) $min < 1) {
            throw new ApiException(__('error_message.member_field_setting.min_length_zero'));
        }
        if ((int) $min > (int) $max) {
            throw new ApiException(__('error_message.member_field_setting.min_exceeds_max'));
        }
        $limit = self::FIELD_MAX_LENGTH_LIMITS[$field['field_key']] ?? null;
        if ($limit !== null && (int) $max > $limit) {
            throw new ApiException(__('error_message.member_field_setting.max_length_exceeded', ['max' => $limit]));
        }
    }

    /**
     * 手機驗證碼須手機號碼開啟、郵件驗證碼須電子郵件開啟
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function assertVerifyCodeDependency(array $fields): void
    {
        $statusByKey = array_map(fn (array $f): int => (int) ($f['status'] ?? 0), array_column($fields, null, 'field_key'));
        $verifyDeps = [
            MemberFieldKeyEnum::SMS_VERIFY_CODE->value => MemberFieldKeyEnum::MOBILE->value,
            MemberFieldKeyEnum::EMAIL_VERIFY_CODE->value => MemberFieldKeyEnum::EMAIL->value,
        ];
        foreach ($verifyDeps as $child => $parent) {
            if (($statusByKey[$child] ?? 0) === 1 && ($statusByKey[$parent] ?? 0) !== 1) {
                throw new ApiException(__('error_message.member_field_setting.verify_code_dependency'));
            }
        }
    }

    /**
     * 玩家帳號關閉時的存檔規則：帳號前綴合格、手機號碼開啟且必填、組別底下每個代理都設了簡訊組別
     *
     * @param  array<string, array<string, mixed>>  $effective  註冊頁存檔後的欄位狀態（DB 現值疊上送來的列）
     */
    private function assertUsernameOffRules(AgentGroup $group, array $effective): void
    {
        $prefix = $effective[MemberFieldKeyEnum::USERNAME->value]['extra_config'][self::USERNAME_PREFIX_KEY] ?? null;
        if (!is_string($prefix) || preg_match(self::USERNAME_PREFIX_PATTERN, $prefix) !== 1) {
            throw new ApiException(__('error_message.member_field_setting.username_prefix_invalid'));
        }

        $mobile = $effective[MemberFieldKeyEnum::MOBILE->value] ?? [];
        if ((int) ($mobile['status'] ?? 0) !== 1 || (int) ($mobile['is_required'] ?? 0) !== 1) {
            throw new ApiException(__('error_message.member_field_setting.mobile_required_when_username_off'));
        }

        $missingSmsGroup = Agent::query()
            ->where('member_field_setting_group_id', $group->id)
            ->where(fn ($q) => $q->whereNull('sms_gateway_group_id')->orWhere('sms_gateway_group_id', 0))
            ->exists();
        if ($missingSmsGroup) {
            throw new ApiException(__('error_message.member_field_setting.sms_gateway_group_required'));
        }
    }

    /**
     * 某頁存檔後的欄位狀態：DB 現值疊上這次送來的列，以 field_key 為鍵
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    private function effectiveFields(AgentGroup $group, MemberFieldPageTypeEnum $pageType, array $fields): array
    {
        $effective = AgentMemberFieldSetting::query()
            ->where('agent_group_id', $group->id)
            ->where('page_type', $pageType->value)
            ->get(['field_key', 'status', 'is_required', 'extra_config'])
            ->keyBy('field_key')
            ->map(fn (AgentMemberFieldSetting $row): array => [
                'status' => (int) $row->status,
                'is_required' => (int) $row->is_required,
                'extra_config' => $row->extra_config,
            ])
            ->all();

        foreach ($fields as $field) {
            $effective[$field['field_key']] = [
                'status' => (int) ($field['status'] ?? 0),
                'is_required' => (int) ($field['is_required'] ?? 0),
                'extra_config' => $field['extra_config'] ?? null,
            ];
        }

        return $effective;
    }

    /**
     * 寫入前正規化狀態：驗證碼開啟即必填；實名驗證的真實姓名恆為開啟必填
     *
     * @param  array<string, mixed>  $field
     * @return array{0:int, 1:int} [status, is_required]
     */
    private function normalizeStatus(MemberFieldPageTypeEnum $pageType, array $field): array
    {
        $status = (int) ($field['status'] ?? 0);
        $isRequired = (int) ($field['is_required'] ?? 0);

        $verifyCodeKeys = [MemberFieldKeyEnum::SMS_VERIFY_CODE->value, MemberFieldKeyEnum::EMAIL_VERIFY_CODE->value];
        if ($status === 1 && in_array($field['field_key'], $verifyCodeKeys, true)) {
            $isRequired = 1;
        }
        if ($pageType === MemberFieldPageTypeEnum::VERIFICATION && $field['field_key'] === MemberFieldKeyEnum::LAST_NAME->value) {
            $status = 1;
            $isRequired = 1;
        }

        return [$status, $isRequired];
    }

    /**
     * 註冊頁玩家帳號的帳號前綴存檔前轉小寫
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>|null
     */
    private function normalizeExtraConfig(MemberFieldPageTypeEnum $pageType, array $field): ?array
    {
        $extraConfig = $field['extra_config'] ?? null;
        if (!is_array($extraConfig)) {
            return null;
        }

        if ($pageType === MemberFieldPageTypeEnum::REGISTER
            && $field['field_key'] === MemberFieldKeyEnum::USERNAME->value
            && is_string($extraConfig[self::USERNAME_PREFIX_KEY] ?? null)
        ) {
            $extraConfig[self::USERNAME_PREFIX_KEY] = strtolower($extraConfig[self::USERNAME_PREFIX_KEY]);
        }

        return $extraConfig;
    }

    private function syncLoginToMobileOnly(Agent $operator, int $groupId): void
    {
        $targets = [
            MemberFieldKeyEnum::USERNAME->value => ['status' => 0, 'sort' => 1],
            MemberFieldKeyEnum::MOBILE->value => ['status' => 1, 'sort' => 2],
        ];

        foreach ($targets as $fieldKey => $target) {
            $row = AgentMemberFieldSetting::query()->firstOrNew(
                [
                    'agent_group_id' => $groupId,
                    'page_type' => MemberFieldPageTypeEnum::LOGIN->value,
                    'field_key' => $fieldKey,
                ],
                ['currency_id' => self::NEW_ROW_CURRENCY_ID],
            );
            if (!$row->exists) {
                $row->sort = $target['sort'];
            }
            $row->status = $target['status'];
            $row->admin = $operator->id;
            $row->save();
        }
    }
}
