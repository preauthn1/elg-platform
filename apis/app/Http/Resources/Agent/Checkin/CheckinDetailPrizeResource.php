<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Checkin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckinDetailPrizeResource extends JsonResource
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
             * @var int 打卡幾天獲得獎勵
             *
             * @example 1
             **/
            'day' => $this->day,
            /**
             * @var int 獎勵類別
             *
             * @example 1
             **/
            'prize_type' => $this->prize_type,
            /**
             * @var string 獎勵金額
             *
             * @example "10.00"
             **/
            'prize_amount' => $this->prize_amount,
        ];
    }
}
