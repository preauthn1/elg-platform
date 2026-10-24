<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Vip;

use App\Http\Resources\Frontend\Vip\Info\RebateSummaryRecordResource;
use App\Http\Resources\Frontend\Vip\Info\RewardResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InfoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int */
            'vip_content_id' => $this->member_info->vip_content_id ?? 0,

            /** @var string */
            'deposit_amount' => $this->member_info->deposit_amount ?? '0.000',

            /** @var string */
            'keep_amount' => $this->member_info->keep_amount ?? '0.000',

            /** @var int */
            'level' => $this->member_info->vipContent->level ?? 0,

            /** @var array */
            'this_rebate_records' => [
                /** @var string */
                'total_bet' => $this->this_rebate_records?->total_bet ?? null,
                /** @var string */
                'rebate_amount' => $this->this_rebate_records?->rebate_amount ?? null,
            ],

            /** @var array */
            'last_rebate_records' => [
                /** @var string */
                'total_bet' => $this->last_rebate_records?->total_bet ?? null,
                /** @var string */
                'rebate_amount' => $this->last_rebate_records?->rebate_amount ?? null,
            ],

            /** @var RebateSummaryRecordResource[] */
            'rebate_summary_records' => RebateSummaryRecordResource::collection($this->rebate_summary_records ?? []),

            /** @var RewardResource[] */
            'reward' => RewardResource::collection($this->reward ?? []),
        ];
    }
}
