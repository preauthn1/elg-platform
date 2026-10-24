<?php

declare(strict_types=1);

namespace App\Models;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Agent\RoleEnum;
use App\Enums\General\CacheTtl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id 系統編號
 * @property int $host_id 主帳號的系統編號(不等於0就是子帳號)
 * @property int $parent 上層代理系統編號(Agent)
 * @property int $role_id roles.id
 * @property null|int $agent_permission_group_id 代理群組ID (agent_permission_groups.id)，NULL=不選擇角色 工單4080
 * @property string|null $hierarchy 階層樹(json型態)
 * @property string|null $hierarchy_path 新版hierarchy結構，存成路徑式的數值
 * @property string $code 邀請碼
 * @property string $username 姓名
 * @property string|null $nickname 暱稱
 * @property string $real_name
 * @property numeric $balance 餘額
 * @property int $is_balance_unlimited 錢包是否為無限額
 * @property int $withdraw_confirm 洗分是否需要同意，0:不需要、1:需要
 * @property string $email 電子信箱
 * @property string $password 密碼
 * @property string|null $payment_password 代理支付密碼
 * @property int $cs_sync_flag 需同步資料的帳號flag為1
 * @property string|null $im_certify vworld影子帳號密碼
 * @property string|null $mobile 聯繫號碼
 * @property numeric|null $percentage 百分比
 * @property string|null $CS_API_USER 與客服系統串接的帳號
 * @property string|null $CS_API_KEY 與客服系統串接的KEY
 * @property int|null $timezone_id 時區系統編號
 * @property int|null $country_id 居住國家(FK_Country)
 * @property string|null $zip_code 郵政編碼
 * @property string|null $district 省/地區/郡
 * @property string|null $city 城鎮/城市
 * @property string|null $address 地址
 * @property int|null $frontend_register 前端是否可以註冊(0:不可註冊,1:可註冊)
 * @property int $has_chat 是否開放聊天室
 * @property int $has_pc 是否有PC版
 * @property int $has_mobile 是否有手機版
 * @property int $has_online_payment 是否有線上儲值
 * @property int $has_app 是否有APP
 * @property int $has_apk APP顯示開關(android)
 * @property int $has_ipa APP顯示開關(ios)
 * @property int $has_2fa Google驗證器開關, 0:關閉, 1:開啟
 * @property string|null $login_ip_whitelist 登入 IP 白名單，逗號分隔；NULL 或空＝不限制（target/tickets#4498）
 * @property int $transaction_all 進入遊戲時是否將所有錢轉入，0:否1:是
 * @property int $agent_backend_transaction 是否可以在代理後台開洗分，0:否1:是
 * @property int $email_validate 電子郵件是否需要認證0:不需要,1:需要
 * @property int $mobile_validate 電子郵件是否需要認證0:不需要,1:需要
 * @property int $mobile_bind 電話號碼是否綁定
 * @property int $need_username 忘記密碼是否需要填寫帳號0:不需要,1:需要
 * @property int $status 帳號狀態 0:鎖定,1:正常
 * @property int $sys_lock 系統鎖定; 1:鎖定, 0:非解鎖
 * @property int $login_fail_count 連續登入密碼錯誤次數；登入成功或解除限制時歸零
 * @property string|null $last_login 最後登入時間
 * @property int|null $last_login_ip 最後登入IP
 * @property int $first_login_ip 首次登入IP
 * @property string $proportion 代理占成
 * @property string|null $memo 方便技術記錄用
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin 更新者ID
 * @property int $created_by 創建代理的操作人
 * @property int $agent_mode 代理模式類型(legacy欄位,實際生效走群組設定7001)。0:無,1:全民代,2:三層代理(計算含代理),3:三層代理(計算限定會員),4:winbox(佔位無實作),5:推廣代理GGR(工單4121)
 * @property int|null $customized_setting_group_id 個性化設置_組別ID (agent_groups 資料表)
 * @property int|null $cash_flow_group_id 群組編號
 * @property int $sms_gateway_group_id 簡訊群組ID
 * @property int|null $email_gateway_group_id 電子郵件群組ID
 * @property int $chat_group_id 聊天群組ID
 * @property int $vendor_customize_group_id 遊戲商個性化設置
 * @property int $organization_id 所屬組織
 * @property int $binding_scope_id 小組織ID
 * @property string|null $last_reset_password 最後更新密碼時間
 * @property int $realname_validate 代理實名認證, 0:未認證, 1:已認證, 2:認證中
 * @property-read Agent|null $agent
 * @property-read Collection<int, AgentCashFlow> $agentCashFlows
 * @property-read int|null $agent_cash_flows_count
 * @property-read Collection<int, AgentCustomizedSetting> $agentCustomizedSettings
 * @property-read int|null $agent_customized_settings_count
 * @property-read mixed $agent_domain
 * @property-read Collection<int, AgentDomain> $agentDomains
 * @property-read int|null $agent_domains_count
 * @property-read Collection<int, AgentGroup> $agentGroups
 * @property-read int|null $agent_groups_count
 * @property-read Collection<int, AgentSetting> $agentSettings
 * @property-read int|null $agent_settings_count
 * @property-read mixed $currency_id
 * @property-read Collection<int, Game> $games
 * @property-read int|null $games_count
 * @property-read mixed $general_agent_id
 * @property-read mixed $hierarchy_array
 * @property-read mixed $is_multi_wallet
 * @property-read Collection<int, Member> $members
 * @property-read int|null $members_count
 * @property-read Organization|null $organization
 * @property-read Collection<int, SmsGatewayInfo> $smsGatewayInfos
 * @property-read int|null $sms_gateway_infos_count
 * @property-read Collection<int, Template> $templates
 * @property-read int|null $templates_count
 *
 * @method static \Database\Factories\AgentFactory factory($count = null, $state = [])
 * @method static Builder<static>|Agent newModelQuery()
 * @method static Builder<static>|Agent newQuery()
 * @method static Builder<static>|Agent query()
 *
 * @mixin \Eloquent
 */
class Agent extends Authenticatable
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'parent', 'id');
    }

    /** 所屬代理群組（權限角色，工單4080）；NULL=不選擇角色，僅吃個人權限 */
    public function permissionGroup(): BelongsTo
    {
        return $this->belongsTo(AgentPermissionGroup::class, 'agent_permission_group_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'parent');
    }

    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'agent_games', 'agent_id', 'game_id');
    }

    public function templates(): BelongsToMany
    {
        return $this->belongsToMany(Template::class, 'agent_domains', 'agent_id', 'template_id');
    }

    /**
     * 關聯的金流商
     */
    public function agentCashFlows(): HasMany
    {
        return $this->hasMany(AgentCashFlow::class, 'agent_group_id', 'cash_flow_group_id');
    }

    /**
     * 關聯的簡訊商
     */
    public function smsGatewayInfos(): HasMany
    {
        return $this->hasMany(SmsGatewayInfo::class, 'sms_gateway_group_id', 'sms_gateway_group_id');
    }

    public function agentDomains(): HasMany
    {
        return $this->hasMany(AgentDomain::class);
    }

    public function agentSettings(): HasMany
    {
        return $this->hasMany(AgentSetting::class);
    }

    public function agentGroups(): HasMany
    {
        return $this->hasMany(AgentGroup::class, 'organization_id', 'organization_id');
    }

    public function agentCustomizedSettings(): HasMany
    {
        return $this->hasMany(AgentCustomizedSetting::class, 'agent_group_id', 'customized_setting_group_id');
    }

    /**
     * 修改器: hierarchy_array
     *
     * @description 關係由近到遠的上級代理 ID 陣列
     */
    protected function hierarchyArray(): Attribute
    {
        // 假設：hierarchy_path 一律以 "/" 開頭
        // 將 hierarchy_path 去除開頭的 "/"（例如 "/5/12/34" -> "5/12/34"）
        // 以 "/" 切割成陣列後反轉（["5","12","34"] -> ["34","12","5"]）
        return Attribute::get(function () {
            return array_reverse(explode('/', substr($this->hierarchy_path, 1)));
        });
    }

    /**
     * 修改器: 取得總代ID general_agent_id
     */
    protected function generalAgentId(): Attribute
    {
        return Attribute::get(function () {
            $agent = Agent::query()
                ->whereIn('id', $this->hierarchy_array)
                ->where('role_id', RoleEnum::GENERAL_AGENT->value)
                ->first();

            return $agent?->id;
        });
    }

    /**
     * 修改器: 取得幣別 currency_id
     */
    protected function currencyId(): Attribute
    {
        return Attribute::get(function () {
            return $this->organization->currency_id;
        });
    }

    /**
     * 修改器: 代理是否是多錢包 is_multi_wallet
     */
    protected function isMultiWallet(): Attribute
    {
        return Attribute::get(function () {
            $settingId = AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_SWITCH;

            return $this->getSettingValue($settingId);
        });
    }

    /**
     * 修改器: 取得代理域名 agent domain
     */
    protected function agentDomain(): Attribute
    {
        return Attribute::get(function () {
            return Cache::remember(CacheKeys::agentModelAgentDomain($this->id), CacheTtl::HALF_HOUR->value, function () {
                $agentIdString = implode(',', $this->hierarchy_array);

                return AgentDomain::whereIn('agent_id', $this->hierarchy_array)
                    ->orderByRaw("FIELD(`agent_id`,{$agentIdString})")
                    ->first();
            });
        });
    }

    /**
     * 查詢代理配置, 優先使用自訂配置 AgentCustomizedSetting
     */
    public function getSettingValue(int $settingId): mixed
    {
        $agentGroupId = $this->customized_setting_group_id;

        $cacheKey = CacheKeys::agentSettingDefine($settingId);

        [$dataType, $defaultValue] = Cache::remember($cacheKey, CacheTtl::HALF_HOUR->value, function () use ($settingId) {
            // 先查詢預設配置, 因為只有 AgentSettingDefine 才有 data_type
            $defaultSetting = AgentSettingDefine::query()
                ->where('setting_id', $settingId)
                ->select(['setting_id', 'default_value', 'data_type'])
                ->first();

            return [
                $defaultSetting->data_type,
                $defaultSetting->default_value,
            ];
        });

        $cacheKey = CacheKeys::agentCustomizedSetting($agentGroupId, $settingId);

        $value = Cache::remember($cacheKey, CacheTtl::HALF_HOUR->value, function () use ($agentGroupId, $settingId) {
            $customizedSetting = AgentCustomizedSetting::query()
                ->where('agent_group_id', $agentGroupId)
                ->where('setting_key', $settingId)
                ->first();

            return $customizedSetting?->value;
        });

        $value = $value ?? $defaultValue;

        return $this->changeDataType($value, $dataType);
    }

    /**
     * 轉換配置型別
     */
    private function changeDataType($value, $dataType = null): mixed
    {
        return match ($dataType) {
            'boolean' => $value == 1,
            'int' => (int) $value,
            'array' => explode(',', $value),
            'json' => json_decode($value, true),
            default => (string) $value,
        };
    }

    public function hasParentAgent($parentId): bool
    {
        $agents = explode('/', $this->hierarchy_path);

        return in_array($parentId, $agents);
    }

    /**
     * 可視範疇的 hierarchy_path 前綴；admin(id=1) 與其子帳號(host_id=1) 回 null 表不限制。
     *
     * 與各 Agent API 列表端點的 `when($agent->id != 1 && $agent->host_id != 1, …)`
     * 過濾條件同源（代理子帳號沿用上層代理的範疇），供寫入端做同等範疇檢查，
     * 避免「讀有擋、寫沒擋」。
     */
    /**
     * 是否為平台端（admin `id=1` 及其子帳號 `host_id=1`），不受組織／hierarchy 限制。
     */
    public function isPlatformAdmin(): bool
    {
        return $this->id == 1 || $this->host_id == 1;
    }

    public function visibleScopePath(): ?string
    {
        if ($this->isPlatformAdmin()) {
            return null;
        }

        $path = $this->hierarchy_path;

        if ($this->host_id != 0) {
            $path = static::query()->whereKey($this->host_id)->value('hierarchy_path') ?? $path;
        }

        return $path;
    }

    /**
     * 目標代理（活動／訂單的所屬代理）是否落在本代理可視範疇內。
     *
     * fail-closed：非 admin 時，目標代理為 null 或查無 hierarchy_path 一律回 false。
     *
     * 比對以「/」為節點邊界（`hierarchy_path` 形如 `/1/100092/100213`，無結尾斜線）：
     * 列表端的 `like '<path>%'` 會讓 `/1/10009` 誤中 `/1/100092`，此處收斂為
     * 本人或真正的下線，只會比列表更嚴、不會擋掉原本就在範疇內的資料。
     */
    public function canSeeAgent(?int $agentId): bool
    {
        $scopePath = $this->visibleScopePath();

        if ($scopePath === null) {
            return true;
        }

        // 範疇路徑為空字串時 fail-closed（rtrim('') . '/' 會變成 '/'，等於比對到全站）
        if ($agentId === null || $scopePath === '') {
            return false;
        }

        $targetPath = static::query()->whereKey($agentId)->value('hierarchy_path');

        if ($targetPath === null) {
            return false;
        }

        return $targetPath === $scopePath
            || str_starts_with((string) $targetPath, rtrim((string) $scopePath, '/') . '/');
    }

    /**
     * 限縮為「操作者自己或下線」的代理（完整路徑比對，非前綴比對）。
     *
     * 與 canSeeAgent() 同口徑：`path = P OR path LIKE 'P/%'`，/1/23 不會誤中 /1/234。
     * 平台端不限制；範疇路徑為空時 fail-closed 全擋。供「收件人／標的限自己下線」這類批次驗證與查詢使用。
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, self $viewer): void
    {
        $scopePath = $viewer->visibleScopePath();

        if ($scopePath === null) {
            return;
        }

        if ($scopePath === '') {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $q) use ($scopePath) {
            $q->where('hierarchy_path', $scopePath)
                ->orWhere('hierarchy_path', 'like', rtrim($scopePath, '/') . '/%');
        });
    }

    /**
     * 解析多個 agent id ⇒ username。
     *
     * 每個 id 各自走一個長效快取（id↔username 實務上幾乎不變），命中即免查 DB，
     * 未命中才逐一查 DB；查無的 id 不納入結果。
     *
     * @param  array<int|null>  $ids
     * @return array<int, string> id => username
     */
    public static function resolveUsernames(array $ids): array
    {
        $result = [];

        foreach (array_unique(array_filter($ids)) as $id) {
            $username = Cache::safeRemember(
                CacheKeys::agentUsername($id),
                CacheTtl::ONE_DAY->value,
                fn () => static::query()->whereKey($id)->value('username'),
            );

            if ($username !== null) {
                $result[$id] = $username;
            }
        }

        return $result;
    }
}
