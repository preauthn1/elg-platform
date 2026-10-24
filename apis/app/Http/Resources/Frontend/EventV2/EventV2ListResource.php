<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\EventV2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventV2ListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int|null 活動V2 ID
             *
             * @example 1
             **/
            'event_detail_id' => $this->event_v2_id,
            /**
             * @var string|null 活動名稱
             *
             * @example "免費送"
             **/
            'name' => $this->name,
            /**
             * @var string|null 活動圖片
             *
             * @example "https://example.invalid/image.jpg"
             **/
            'image' => $this->image,
        ];
    }
}
