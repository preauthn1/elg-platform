<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\EventInvite;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventInviteListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 狀態 0:進行中, 1:已完成
             *
             * @example 0
             **/
            'status' => $this->status,
            /**
             * @var string|null 玩家（被邀請人）創建帳號日期
             *
             * @example "2026-08-01 00:00:00"
             **/
            'created_at' => $this->member?->created_at?->toFrontendTime()->format('Y-m-d H:i:s'),
            /**
             * @var string 被邀請人帳號
             *
             * @example "test0001"
             **/
            'username' => $this->member?->username,
            /**
             * @var int 真實姓名認證獎勵是否已結算 0:未結算, 1:已結算
             *
             * @example 0
             **/
            'realname_status' => $this->realname_status,
            /**
             * @var int 儲值獎勵是否已結算 0:未結算, 1:已結算
             *
             * @example 0
             **/
            'deposit_status' => $this->deposit_status,
            /**
             * @var string 累積流水
             *
             * @example "12000.0000"
             **/
            'bet' => $this->bet,
            /**
             * @var string 累積可領取金額
             *
             * @example "6.0000"
             **/
            'point' => $this->point,
            /**
             * @var string 已領取金額
             *
             * @example "5.0000"
             **/
            'receive' => $this->receive,
        ];
    }
}
