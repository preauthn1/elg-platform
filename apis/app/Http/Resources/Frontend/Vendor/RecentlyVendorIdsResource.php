<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Vendor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecentlyVendorIdsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'vendor_id' => $this->vendor_id,
        ];
    }
}
