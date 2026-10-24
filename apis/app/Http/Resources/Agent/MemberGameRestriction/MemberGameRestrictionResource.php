<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\MemberGameRestriction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 會員遊戲限制單 工單4126
 */
class MemberGameRestrictionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $targets = $this->whenLoaded('targets', fn () => $this->targets, collect());
        $members = $this->whenLoaded('members', fn () => $this->members, collect());

        return [
            /** @var int 限制單編號 */
            'id' => (int) $this->id,
            /** @var int 限制類型 1:遊戲商 2:遊戲 */
            'restrict_type' => (int) $this->restrict_type,
            /** @var int 對象範圍 1:指定會員 2:會員群組 */
            'target_scope' => (int) $this->target_scope,
            /** @var int 建立此限制的代理編號 */
            'agent_id' => (int) $this->agent_id,
            /** @var int 啟用狀態 1:啟用 0:停用 */
            'status' => (int) $this->status,
            /** @var string|null 備註 */
            'memo' => $this->memo,
            /**
             * 依 restrict_type 為 vendors.id 或 games.id 的清單，供編輯回填。
             *
             * @var array<int> 受限標的編號
             */
            'target_ids' => $targets->pluck('target_id')->map(fn ($id) => (int) $id)->values()->all(),
            /**
             * 受限標的的名稱，供列表直接顯示與編輯回填，前端不必再打一輪下拉接口。
             * restrict_type=2（遊戲）時 vendor_name 為該遊戲所屬遊戲商，
             * 供辨識同名遊戲（各商都可能有「百家樂」）；=1（遊戲商）時為空字串。
             *
             * @var array<array{id: int, name: string, vendor_name: string}> 受限標的名稱
             */
            'target_names' => $this->target_names ?? [],
            /**
             * target_scope=1 時有值。帶 username 供編輯時顯示已選項名稱
             * （下拉為遠端搜尋，不重查就只剩 id）。
             *
             * @var array<array{id: int, username: string}> 受限會員
             */
            'members' => $members
                ->filter(fn ($row) => (int) $row->member_id > 0)
                ->map(fn ($row) => [
                    'id' => (int) $row->member_id,
                    'username' => (string) ($row->member->username ?? ''),
                ])
                ->values()
                ->all(),
            /**
             * target_scope=2 時有值。
             *
             * @var array<int> 受限會員群組編號
             */
            'member_group_ids' => $members
                ->filter(fn ($row) => (int) $row->member_group_id > 0)
                ->pluck('member_group_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            /** @var string|null 建立日期（後台時區 GMT+8，target/tickets#4492；同 IpRestrictionResource） */
            'created_at' => $this->created_at?->toBackendTime()->toDateTimeString(),
            /** @var string|null 更新日期（後台時區 GMT+8，target/tickets#4492） */
            'updated_at' => $this->updated_at?->toBackendTime()->toDateTimeString(),
        ];
    }
}
