<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Agent;
use Illuminate\Http\Request;

/**
 * 會員電話的服務端遮罩（4495，會員追蹤、會員活躍篩選）。
 *
 * 沿用 MemberDetailService 的既有做法：無 `reveal_phone_number`（顯示電話）權限者，
 * 伺服器端直接不回真值，不能只靠前端遮。權限判定為個人∪角色（見 AgentMenuPermissionService）；
 * 平台端不受限，agent_menus 未建該碼的白標依該服務的 fail-open 處置放行。
 *
 * 列表型 Resource 每列都要遮罩，逐列查權限會 N+1：Controller 先以 {@see bindRevealFlags()}
 * 把結果放進 request attributes，Resource 以 {@see mobile()} 讀取；未綁定視為無權限（fail-closed）。
 */
class MemberContactMaskService
{
    public const string REVEAL_PHONE_CODE = 'reveal_phone_number';

    private const string ATTR_PHONE = 'reveal_member_phone';

    /** 無權限時的佔位字串，與會員詳情的電話遮罩一致 */
    public const string MASKED = '-';

    public function __construct(
        private readonly AgentMenuPermissionService $menuPermission,
    ) {}

    public function canRevealPhone(Agent $agent): bool
    {
        return $this->menuPermission->hasAny($agent, [self::REVEAL_PHONE_CODE]);
    }

    /**
     * 供列表 Controller 在回傳 Resource 前呼叫，一次查好權限。
     *
     * Controller 拿到的是 FormRequest（由 app('request') 複製而來，attributes 不共用），
     * 但 Resource 序列化時讀的是 app('request')，所以兩邊都要寫，否則有權限也永遠讀到「無權限」。
     */
    public function bindRevealFlags(Request $request, Agent $agent): void
    {
        $canReveal = $this->canRevealPhone($agent);

        $request->attributes->set(self::ATTR_PHONE, $canReveal);
        app('request')->attributes->set(self::ATTR_PHONE, $canReveal);
    }

    /** 電話：空值維持空，有值但無權限回佔位。 */
    public static function mobile(Request $request, ?string $value): string
    {
        if (blank($value)) {
            return '';
        }

        return $request->attributes->get(self::ATTR_PHONE, false) === true ? (string) $value : self::MASKED;
    }
}
