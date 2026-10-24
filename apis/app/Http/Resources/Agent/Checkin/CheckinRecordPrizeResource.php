<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Checkin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckinRecordPrizeResource extends JsonResource
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
             * @var string 獎勵類別
             *
             * @example "平台點數"
             **/
            'prize_type' => match ($this->checkinPrize->prize_type) {
                1 => __('param.platform_point'),
                2 => __('param.rolling_chip'),
                default => '',
            },
            /**
             * @var string 獎勵金額
             *
             * @example "10.00"
             **/
            'prize_amount' => $this->checkinPrize->prize_amount,
        ];
    }
}
