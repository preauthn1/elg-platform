<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EventV2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventV2DetailLangResource extends JsonResource
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
             * @var int ID
             *
             * @example 1
             **/
            'id' => $this->id,
            /**
             * @var int 活動詳細ID
             *
             * @example 1
             **/
            'event_v2_detail_id' => $this->event_v2_detail_id,
            /**
             * @var int 語系ID
             *
             * @example 2
             **/
            'language_id' => $this->language_id,
            /**
             * @var string|null 活動名稱
             *
             * @example "每日簽到存 30 送 10"
             **/
            'name' => $this->name,
            /**
             * @var string|null 活動圖片
             *
             * @example "https://example.invalid/image.jpg"
             **/
            'image' => $this->image,
            /**
             * @var string|null 活動獎勵名稱
             *
             * @example "威幣"
             **/
            'reward_name' => $this->reward_name,
            /**
             * @var string|null 活動內容
             *
             * @example "<div>內容</div>"
             **/
            'content' => $this->content,
        ];
    }
}
