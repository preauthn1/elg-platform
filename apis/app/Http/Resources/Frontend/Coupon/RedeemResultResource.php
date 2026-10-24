<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Coupon;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RedeemResultResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var float */
            'amount' => $this['amount'],

            /** @var int */
            'type' => $this['type'],

            /** @var string|null */
            'image_url' => $this['image_url'] ?? null,
        ];
    }
}
