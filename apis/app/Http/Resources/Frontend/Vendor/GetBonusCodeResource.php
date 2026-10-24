<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Vendor;

use App\Data\VendorBonusCodeResponseData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GetBonusCodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /**
         * @var VendorBonusCodeResponseData $this
         */

        return [
            'bonus_code' => $this->bonus_code,
        ];
    }
}
