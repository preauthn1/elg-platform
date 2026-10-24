<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\EventInvite;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventInviteSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var string|null 活動標題
             *
             * @example "邀請好友，好禮相送"
             **/
            'title' => $this['title'],
            /**
             * @var string|null 活動內容
             *
             * @example "邀請好友註冊並完成任務即可獲得獎勵"
             **/
            'content' => $this['content'],
            /**
             * @var string 獎勵總額上限
             *
             * @example "10.0000"
             **/
            'max_reward' => $this['max_reward'],
            /**
             * @var int 邀請好友數
             *
             * @example 3
             **/
            'invite_count' => $this['invite_count'],
            /**
             * @var string 已解鎖總額
             *
             * @example "6.0000"
             **/
            'total_point' => $this['total_point'],
            /**
             * @var string 已領取總額
             *
             * @example "4.0000"
             **/
            'total_receive' => $this['total_receive'],
            /**
             * @var string 待領取金額
             *
             * @example "2.0000"
             **/
            'can_receive' => $this['can_receive'],
            /**
             * @var string 累積充值門檻
             *
             * @example "100.0000"
             **/
            'deposit_point' => $this['deposit_point'],
            /**
             * @var string 投注解鎖級距
             *
             * @example "1000.0000"
             **/
            'bet_point' => $this['bet_point'],
            /**
             * @var string 每級距解鎖金額
             *
             * @example "0.5000"
             **/
            'bet_reward' => $this['bet_reward'],
            /**
             * @var string 實名認證獎勵
             *
             * @example "1.0000"
             **/
            'realname_reward' => $this['realname_reward'],
            /**
             * @var string 儲值達標獎勵
             *
             * @example "1.0000"
             **/
            'deposit_reward' => $this['deposit_reward'],
        ];
    }
}
