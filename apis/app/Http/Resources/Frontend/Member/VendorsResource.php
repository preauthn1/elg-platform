<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Member;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorsResource extends JsonResource
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
             * @var int $vendor_id
             */
            'vendor_id' => $this['id'],

            /**
             * @var string $vendor_name
             */
            'vendor_name' => $this['name'],
        ];
    }
}
