<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\EventProgressiveJackpot;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventProgressiveJackpotSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 活動狀態 0:未開放(不顯示), 1:開放
             *
             * @example 1
             **/
            'status' => $this['status'],
            /**
             * @var string|null 活動標題
             *
             * @example "成長彩金計劃"
             **/
            'title' => $this['title'],
            /**
             * @var string|null 活動內文
             *
             * @example "累計儲值達標即可開啟對應成長彩金"
             **/
            'content' => $this['content'],
            /**
             * @var string|null 活動圖片
             *
             * @example "https://example.invalid/image.jpg"
             **/
            'image' => $this['image'],
        ];
    }
}
