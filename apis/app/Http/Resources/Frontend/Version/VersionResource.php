<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Version;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Version */
class VersionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ios' => [
                'version' => $this->ios_version,
                'downloadLink' => $this->ios_link,
            ],
            'android' => [
                'version' => $this->android_version,
                'downloadLink' => $this->android_link,
            ],
        ];
    }
}
