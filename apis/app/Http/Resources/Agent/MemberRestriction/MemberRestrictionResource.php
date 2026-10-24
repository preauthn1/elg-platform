<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\MemberRestriction;

use App\Enums\MemberRestrictionTargetEnum;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberRestrictionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int 規則編號 */
            'id' => $this->id,
            /** @var int 限制類型 1:会员管理 2:会员群组 */
            'type' => MemberRestrictionTargetEnum::fromMorphAlias($this->target_type)->value,
            /** @var string|null 目標所屬代理帳號 */
            'agent_username' => $this->whenLoaded('agent', fn () => $this->agent?->username),
            /** @var string|null 目標對象（會員帳號或群組名稱） */
            'target_name' => $this->whenLoaded('target', fn () => $this->target instanceof Member
                ? $this->target->username
                : $this->target?->name),
            /** @var string|null 內部備註 */
            'remark' => $this->remark,
            /** @var int 規則總開關 1:啟用 0:關閉 */
            'status' => $this->status,
            /** @var int 是否停用（1:禁止登入） */
            'is_disabled' => $this->is_disabled,
            /** @var int 提款鎖定（1:禁止申請提現） */
            'withdrawal_locked' => $this->withdrawal_locked,
            /** @var string|null 更新者帳號 */
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater?->username),
            /** @var string|null 更新時間 */
            'updated_at' => $this->updated_at?->toBackendTime()->toDateTimeString(),
        ];
    }
}
