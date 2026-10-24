<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Vendor;

use App\Data\VendorGameUrlResultAppInfoData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VendorGameUrlResultAppInfoData */
class GetGameUrlAppInfoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            'username' => $this->username,

            /** @var string|null */
            'password' => $this->password,

            /** @var array<string> */
            'ios_downloads' => $this->ios_downloads,

            /** @var string|null */
            'ios_open' => $this->ios_open,

            /** @var array<string> */
            'android_downloads' => $this->android_downloads,

            /** @var string|null */
            'android_open' => $this->android_open,

            /** @var string|null */
            'app_icon' => $this->app_icon,
        ];
    }
}
