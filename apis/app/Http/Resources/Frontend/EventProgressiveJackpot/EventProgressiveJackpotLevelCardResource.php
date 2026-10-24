<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\EventProgressiveJackpot;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventProgressiveJackpotLevelCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 彩金等級
             *
             * @example 0
             **/
            'level' => $this['level'],
            /**
             * @var int|null 狀態 null:未達標(尚未建立參加紀錄), 0:進行中, 1:等待解鎖, 2:已完成, 3:已過期, 4:已鎖定(IP、手機號、真實姓名重複), 5:已關閉(已完成且領取完畢，不再變動)
             *
             * @example null
             **/
            'status' => $this['status'],
            /**
             * @var string 彩金金額(成長彩金總額)；未達標時為預覽值，reward_type=2 顯示 max_reward
             *
             * @example "1888.0000"
             **/
            'reward' => $this['reward'],
            /**
             * @var string 已解鎖金額
             *
             * @example "0.0000"
             **/
            'point' => $this['point'],
            /**
             * @var string 已領取金額
             *
             * @example "0.0000"
             **/
            'receive' => $this['receive'],
            /**
             * @var string|null 剩餘待解鎖金額，未達標(status=null)時為 null
             *
             * @example null
             **/
            'remaining' => $this['remaining'],
            /**
             * @var string 解鎖進度(%)
             *
             * @example "0"
             **/
            'progress' => $this['progress'],
            /**
             * @var string 上次已解鎖金額(前台通知用，投注完成後動畫顯示本次新增解鎖金額)
             *
             * @example "0.0000"
             **/
            'last_point' => $this['last_point'],
            /**
             * @var string|null 過期時間，null 代表無期限或尚未開啟
             *
             * @example null
             **/
            'expire_time' => $this['expire_time']?->toFrontendTime()->format('Y-m-d H:i:s'),
            /**
             * @var string 須累積儲值的金額(首儲檔位為0)
             *
             * @example "3000.0000"
             **/
            'deposit_point' => $this['deposit_point'],
            /**
             * @var string 距離達標還需儲值的金額，已達標(有 user_join 紀錄)時固定為 0
             *
             * @example "3000.0000"
             **/
            'remaining_deposit' => $this['remaining_deposit'],
            /**
             * @var int 獎勵類型 1:固定金額, 2:百分比
             *
             * @example 2
             **/
            'reward_type' => $this['reward_type'],
            /**
             * @var string 金額%數(reward_type=2)
             *
             * @example "200.0000"
             **/
            'reward_percentage' => $this['reward_percentage'],
            /**
             * @var string 金額上限(reward_type=2)
             *
             * @example "5888.0000"
             **/
            'max_reward' => $this['max_reward'],
            /**
             * @var int 多久後過期(天)，0代表不限時
             *
             * @example 0
             **/
            'expire_day' => $this['expire_day'],
        ];
    }
}
