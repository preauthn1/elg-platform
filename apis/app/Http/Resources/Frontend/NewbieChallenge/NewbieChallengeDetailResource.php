<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\NewbieChallenge;

use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewbieChallengeDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var string 活動名稱（依語系，無對應語系時 fallback 英文） */
            'name' => $this->name,
            /** @var string 活動說明與規則文字（依語系，無對應語系時 fallback 英文） */
            'content' => $this->content,
            /** @var string 活動金 */
            'activity_reward' => $this->activity_amount,
            /** @var string 挑戰流水目標(活動金 x 挑戰流水倍數) */
            'challenge_flow_target' => Common::numMul($this->activity_amount, $this->challenge_flow_multiple, 4),
            /** @var string 最低解鎖金額 */
            'min_unlock_amount' => $this->min_unlock_amount,
            /** @var string 最高解鎖金額 */
            'max_unlock_amount' => $this->max_unlock_amount,
            /** @var int 彩金有效期(小時) */
            'bonus_window_hours' => $this->bonus_window_hours,
            /** @var array<int, array{id: int, name: string}> 可參與遊戲商 */
            'allowed_vendors' => $this->allowedVendors,
        ];
    }
}
