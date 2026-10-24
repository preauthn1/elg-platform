<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EventV2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventV2DetailExcludeVendorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 遊戲商 ID
             *
             * @example 1
             **/
            'id' => $this->id,
            /**
             * @var string|null 遊戲商名稱
             *
             * @example "UU Slot"
             **/
            'name' => $this->name,
        ];
    }
}
