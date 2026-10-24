<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Event;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrizeRemindResource extends JsonResource
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
             * @var bool VIP 達標可領獎
             *
             * @example true
             **/
            'vip' => $this['vip'],
            /**
             * @var bool 活動 V1 未領取獎勵，或有活動 V2 可參加但尚未參加
             *
             * @example true
             **/
            'event' => $this['event'],
        ];
    }
}
