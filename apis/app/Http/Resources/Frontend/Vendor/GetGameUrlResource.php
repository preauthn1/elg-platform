<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Vendor;

use App\Data\VendorGameUrlResultData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GetGameUrlResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var VendorGameUrlResultData $result */
        $result = $this['result'];

        return [
            'pc' => $result->pc,
            'mobile' => $result->mobile,
            'open_window' => $this['open_window'],

            'app_infos' => $this->when(!empty($result->app_infos), GetGameUrlAppInfoResource::make($result->app_infos)),
        ];
    }
}
