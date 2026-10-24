<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\GgrCondition;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GgrConditionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int 條件編號 */
            'id' => $this->id,
            /** @var string GGR 條件標題 */
            'name' => $this->name,
            /** @var int 類型 1:或者(OR) 2:和(AND) */
            'logic_type' => $this->logic_type,
            /** @var int 僅計算活躍用戶 1:是 0:否 */
            'active_user_only' => $this->active_user_only,
            /** @var int 狀態 1:啟用 0:停用 */
            'status' => $this->status,
            /** @var array|null 階梯列（view 才載入；依 tier 升冪） */
            'tiers' => $this->whenLoaded('tiers', fn () => $this->tiers->map(fn ($tier) => [
                /** @var int 用戶人數（必要條件） */
                'user_count' => $tier->user_count,
                /** @var string 存款門檻（單一會員，0=無要求） */
                'deposit_amount' => $tier->deposit_amount,
                /** @var string 總投注金額門檻（單一會員，0=無要求） */
                'total_bet_amount' => $tier->total_bet_amount,
                /** @var string 遊戲總收入門檻（單一會員，0=無要求） */
                'game_revenue_amount' => $tier->game_revenue_amount,
                /** @var string 有效流水門檻（單一會員，0=無要求） */
                'valid_bet_amount' => $tier->valid_bet_amount,
                /** @var string 應得百分比% */
                'percentage' => $tier->percentage,
            ])->all()),
            /** @var string|null 建立人員帳號 */
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->username),
            /** @var string|null 更新者帳號 */
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater?->username),
            /** @var string|null 建立時間 */
            'created_at' => $this->created_at?->toBackendTime()->toDateTimeString(),
            /** @var string|null 更新時間 */
            'updated_at' => $this->updated_at?->toBackendTime()->toDateTimeString(),
        ];
    }
}
