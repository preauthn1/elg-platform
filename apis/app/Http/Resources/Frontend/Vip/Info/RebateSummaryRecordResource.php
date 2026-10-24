<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Vip\Info;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RebateSummaryRecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var string */
            'summary_start_time' => Carbon::parse($this->summary_start_time)->toFrontendTime()->format('Y-m-d H:i:s'),
            /** @var string */
            'summary_end_time' => Carbon::parse($this->summary_end_time)->toFrontendTime()->format('Y-m-d H:i:s'),
            /** @var int */
            'game_type_id' => $this->game_type_id,
            /** @var string */
            'bet' => $this->bet,
            /** @var string */
            'proportion' => $this->proportion,
            /** @var string */
            'rebate_amount' => $this->rebate_amount,
        ];
    }
}
