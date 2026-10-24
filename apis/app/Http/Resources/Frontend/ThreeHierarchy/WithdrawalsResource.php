<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\ThreeHierarchy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WithdrawalsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'username' => $this['username'],

            /**
             * 總獎勵全民積分
             */
            'totalRewardPoints' => $this['totalRewardPoints'],

            /**
             * 已提現全民積分
             */
            'withdrawnPoints' => $this['withdrawnPoints'],

            /**
             * 可提現全民積分
             */
            'withdrawablePoints' => $this['withdrawablePoints'],
        ];
    }
}
