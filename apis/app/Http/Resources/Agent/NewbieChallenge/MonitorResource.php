<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\NewbieChallenge;

use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonitorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var string 邏輯狀態分組：challenging(進行中)/bonus_pending(待領取彩金)/
             *             bonus_wagering(彩金任務中)/completed(已完成)/quit(會員自己放棄)/
             *             operator_quit(代理後台強制取消)/expired(已失效，48hr 視窗已過期尚未領取彩金)；
             *             「已放棄/已失效」若要合併顯示，前端自行加總 quit、operator_quit、expired 三組
             *
             * @example "challenging"
             */
            'status_group' => $this->status_group,
            /**
             * @var int 該狀態下的會員數
             *
             * @example 12
             */
            'member_count' => (int) $this->member_count,
            /**
             * @var string 該狀態下累計領取的活動金
             *
             * @example "1056.0000"
             */
            'total_activity_reward' => Common::numAdd('0', (string) $this->total_activity_reward, 4),
            /**
             * @var string 該狀態下累計已撥款彩金
             *
             * @example "0.0000"
             */
            'total_bonus_paid' => Common::numAdd('0', (string) $this->total_bonus_paid, 4),
        ];
    }
}
