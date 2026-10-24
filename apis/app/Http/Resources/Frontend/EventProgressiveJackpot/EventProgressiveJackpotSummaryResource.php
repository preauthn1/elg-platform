<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\EventProgressiveJackpot;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventProgressiveJackpotSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int|null 目前進行中的檔位，null 代表尚未開啟任何檔位
             *
             * @example 0
             **/
            'level' => $this['level'],
            /**
             * @var string|null 目前檔位的彩金總額
             *
             * @example "5888.0000"
             **/
            'reward' => $this['reward'],
            /**
             * @var int|null 目前檔位狀態 0:進行中, 1:等待解鎖, 2:已完成, 3:已過期, 4:已鎖定(IP、手機號、真實姓名重複), 5:已關閉(已完成且領取完畢，不再變動)
             *
             * @example 0
             **/
            'status' => $this['status'],
            /**
             * @var string 目前檔位解鎖進度(%)
             *
             * @example "0.00"
             **/
            'progress' => $this['progress'],
            /**
             * @var string 可領取金額(已解鎖-已領取)
             *
             * @example "1766.4000"
             **/
            'can_receive' => $this['can_receive'],
            /**
             * @var string 待解鎖金額(彩金總額-已解鎖)
             *
             * @example "3621.6000"
             **/
            'pending_unlock' => $this['pending_unlock'],
            /**
             * @var bool 目前檔位是否為不限時活動
             *
             * @example true
             **/
            'unlimited_time' => $this['unlimited_time'],
            /**
             * @var string|null 目前檔位過期時間，unlimited_time=true 或尚未開啟任何檔位時為 null
             *
             * @example "2026-09-17 00:00:00"
             **/
            'expire_time' => $this['expire_time']?->toFrontendTime()->format('Y-m-d H:i:s'),
            /**
             * @var int 已完成檔位數
             *
             * @example 0
             **/
            'completed_count' => $this['completed_count'],
            /**
             * @var int 檔位總數
             *
             * @example 5
             **/
            'total_count' => $this['total_count'],
        ];
    }
}
