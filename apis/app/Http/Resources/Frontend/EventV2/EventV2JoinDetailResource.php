<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\EventV2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventV2JoinDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<float>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 活動詳細ID
             *
             * @example 1
             **/
            'event_detail_id' => $this['event_detail_id'],
            /**
             * @var float 餘額門檻
             *
             * @example 300
             **/
            'balance_limit' => (float) $this['balance_limit'],
            /**
             * @var float 流水門檻
             *
             * @example 1000
             **/
            'flow_limit' => (float) $this['flow_limit'],
            /**
             * @var float 活動餘額
             *
             * @example 19
             **/
            'balance' => (float) $this['balance'],
            /**
             * @var float 活動流水
             *
             * @example 100
             **/
            'flow' => (float) $this['flow'],
        ];
    }
}
