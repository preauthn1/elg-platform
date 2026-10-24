<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Deposit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * VPlus33 充值方式分組
 */
class OrderTypeGroupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = data_get($this->resource, 'type', '');
        $items = data_get($this->resource, 'items', []);

        return [
            /** @var string 支付方式類型 */
            'type' => $type,

            /** @var array 支付方式項目列表 */
            'items' => OrderTypeGroupItemResource::collection($items),
        ];
    }
}
