<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\EventV2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventV2DetailBonusCodeResource extends JsonResource
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
             * @var string 活動優惠碼
             *
             * @example "M838NP94"
             **/
            'bonus_code' => $this->bonus_code,
            /**
             * @var string 參加時間
             *
             * @example "2023-01-01 00:00:00"
             **/
            'created_at' => \Carbon\Carbon::parse($this->created_at)->toFrontendTime()->toDateTimeString(),
        ];
    }
}
