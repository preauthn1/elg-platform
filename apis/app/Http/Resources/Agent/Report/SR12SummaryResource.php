<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Report;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @knowledge rules/report-sr12-payout
 */
class SR12SummaryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'bet_amount' => $this->bet_amount ?? 0,
            'valid_bet_amount' => $this->valid_bet_amount ?? 0,
            'payout' => $this->payout ?? 0,
            'win_lose' => $this->win_lose ?? 0,
            'rolling_chip' => $this->rolling_chip ?? 0,
            'commission' => $this->commission ?? 0,
            // 'cnt' => $this->cnt ?? 0,
        ];
    }
}
