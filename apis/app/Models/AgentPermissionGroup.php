<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 代理群組（權限角色）主檔 工單4080
 *
 * 管理權限由選單控制。
 * 代理最終權限 = 個人權限(AgentPermission) ∪ 角色權限(AgentPermissionGroupMenu)。
 *
 * 角色持有者（target/tickets#4543，畫面「所屬代理」）：owner_agent_id＝建立者的主帳號 agents.id，
 * 誰持有誰才能套給自己的下級。NULL＝admin 持有（只有 admin 與其子帳號能套，可套給任何人，畫面顯示「admin」）；
 * 1＝admin預設角色（4422，只能綁 admin 子帳號，畫面顯示「admin（子帳號專用）」）。
 * 2026-10-19 前 owner 記的是「所屬總代」，一般代理建的也記成總代；4543 補丁後新建的一律記建立者。
 *
 * @property int $id 系統編號
 * @property string $name 角色名稱
 * @property null|int $owner_agent_id 持有者 agents.id，NULL=admin 持有、1=admin預設角色
 * @property null|string $remark 備註
 * @property int $status 狀態 0:關閉 1:啟用
 * @property int $default_setting 是否預設群組 0:否 1:是（建立代理時下拉預選，非強制）
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|AgentPermissionGroup newModelQuery()
 * @method static Builder<static>|AgentPermissionGroup newQuery()
 * @method static Builder<static>|AgentPermissionGroup query()
 *
 * @mixin \Eloquent
 */
class AgentPermissionGroup extends Model
{
    /**
     * super admin 的 agents.id；owner_agent_id 存這個值＝admin預設角色（target/tickets#4422）。
     * 必須只綁到 super admin 的子帳號（host_id = 1）；admin 子帳號的角色下拉只給 admin預設角色＋admin 持有（NULL）的角色，
     * 其他帳號一律看不到。判斷一律走 isAdminOwner()／isAllowedForTargetHost()，禁止各處另寫 === 1。
     * ⚠️ 這個值不是「admin 持有」：admin 持有要給總代用的角色是 NULL（4543），寫成 1 會讓它套不給總代。
     * 與 API_Agent 同名常數為同一條規則（建立／編輯營運商的綁定檢查仍在 API_Agent）。
     */
    public const int ADMIN_OWNER_ID = 1;

    /** 列表與所屬代理下拉顯示 admin預設角色的名稱（前端另顯示「admin（子帳號專用）」） */
    public const string ADMIN_OWNER_LABEL = 'admin';

    /** 這個 owner_agent_id 是否代表 admin 角色 */
    public static function isAdminOwner(?int $ownerAgentId): bool
    {
        return $ownerAgentId === self::ADMIN_OWNER_ID;
    }

    /** 這個 host_id 是否代表 super admin 的子帳號（只有它們能套用 admin 角色） */
    public static function isAdminSubAccountHost(int $hostId): bool
    {
        return $hostId === self::ADMIN_OWNER_ID;
    }

    /**
     * 目標帳號能不能套用這個角色（只看 admin預設角色規則，持有者與權限子集另由 AgentPermissionScopeService 判斷）：
     * admin 子帳號只能用 admin預設角色或 admin 持有（NULL）的角色；其他帳號不能用 admin預設角色。
     */
    public static function isAllowedForTargetHost(?int $ownerAgentId, int $targetHostId): bool
    {
        if (self::isAdminSubAccountHost($targetHostId)) {
            return empty($ownerAgentId) || self::isAdminOwner($ownerAgentId);
        }

        return !self::isAdminOwner($ownerAgentId);
    }

    /** admin 角色前提缺項：被綁帳號尚未開啟 Google 2FA（agents.has_2fa ≠ 1） */
    public const string ADMIN_REQUIREMENT_TWO_FACTOR = 'two_factor';

    /** admin 角色前提缺項：被綁帳號的登入 IP 白名單為空 */
    public const string ADMIN_REQUIREMENT_LOGIN_IP_WHITELIST = 'login_ip_whitelist';

    /**
     * 套用 admin 角色的前提（target/tickets#4498）：被綁的帳號必須**同時**「已開啟 Google 2FA」與「登入 IP 白名單非空」。
     * 回傳缺的項目（空陣列＝符合），鍵為 ADMIN_REQUIREMENT_*，語系文字在 error_message.agent_permission_group.admin_requires_*。
     *
     * 「已開啟」看的是 has_2fa 開關，不是 agent_authentications 有沒有 secret：只掃了 QR、開關沒打開的不算。
     * 兩個值都要拿**存檔前**資料庫裡的，不能拿同一個請求送進來的 has_2fa，否則直接打 API 一次送齊就能繞過「本人先開 2FA」。
     * 與 API_Agent AgentController 的綁定檢查為同一條規則（建立／編輯營運商的存檔把關仍在 API_Agent）。
     *
     * @return list<string>
     */
    public static function adminRoleMissingRequirements(?int $has2fa, ?string $loginIpWhitelist): array
    {
        $missing = [];
        if ($has2fa !== 1) {
            $missing[] = self::ADMIN_REQUIREMENT_TWO_FACTOR;
        }
        if (trim((string) $loginIpWhitelist) === '') {
            $missing[] = self::ADMIN_REQUIREMENT_LOGIN_IP_WHITELIST;
        }

        return $missing;
    }

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /** 角色規則以 int 嚴格比較（isAdminOwner 等），驅動回字串時會靜默判錯 */
    protected $casts = [
        'owner_agent_id' => 'integer',
        'status' => 'integer',
        'default_setting' => 'integer',
    ];

    /** 該角色勾選的選單權限明細 */
    public function menus(): HasMany
    {
        return $this->hasMany(AgentPermissionGroupMenu::class, 'agent_permission_group_id');
    }

    /** 掛在此角色底下的代理 */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class, 'agent_permission_group_id');
    }

    /** 持有者（所屬代理）；NULL 代表 admin 持有 */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'owner_agent_id');
    }
}
