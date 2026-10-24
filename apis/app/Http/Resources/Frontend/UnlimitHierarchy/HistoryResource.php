<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\UnlimitHierarchy;

use App\Models\UnlimitWithdrawLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UnlimitWithdrawLog */
class HistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            'date' => $this->created_at->toFrontendTime()->format('Y-m-d H:i:s'),
            'no' => $this->id,
            'amount' => $this->amount,
            'type' => $this->type === 1 ? __('param.issued') : __('param.notIssued'),
            'withdrawType' => __('param.points'),
            'withdrawAccount' => 0,
        ];
    }
}
